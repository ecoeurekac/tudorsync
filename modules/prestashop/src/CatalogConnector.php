<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop;

use Context;
use Db;
use DbQuery;
use Language;
use Link;
use StockAvailable;
use Tudorsync\Core\Contract\CatalogConnectorInterface;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Url\UtmUrlBuilder;

/**
 * Reads PrestaShop's own catalog/stock data and maps it into tudorsync/core's
 * Tudorsync\Core\Domain\StockAvailability shape.
 *
 * A product is enrolled in the TUDOR program by its model code, taken from:
 *   1. the "TUDOR Model Code" Feature (created once by Tudorsync::install(), its id stored via
 *      Config::KEY_MODEL_CODE_FEATURE_ID — chosen over a database schema migration so no new
 *      column is needed), when filled in: always wins, so a code typed on the product page
 *      corrects the rule;
 *   2. otherwise, for products of the configured TUDOR brand, the reference rule of the module
 *      config (ModelCodeResolver), so a TUDOR product created later is enrolled without
 *      anyone having to fill the Feature first.
 *
 * Only products a customer can actually buy in this shop are returned: present in the shop
 * (product_shop), active, visible somewhere (visibility other than "none") and available for
 * order. They're also excluded whenever the available quantity isn't strictly positive: with
 * no stock, PrestaShop's own "allow orders when out of stock" setting would make it
 * backorder/"on demand"-only, which TUDOR's program must never publish, and there's no other
 * reliable in-stock signal to fall back on.
 *
 * TODO: a product with stock management disabled entirely (always orderable, no quantity
 * tracked at all) would be wrongly excluded by this logic — confirm with the retailer whether
 * any TUDOR models are sold that way before relying on this connector for them.
 *
 * Repeated model codes: only the most recently updated active product counts (pickRepeated()).
 *
 * Multi-language: builds one URL per active PrestaShop language via Link::getProductLink().
 */
final class CatalogConnector implements CatalogConnectorInterface
{
    private readonly UtmUrlBuilder $utmUrlBuilder;
    private readonly ModelCodeResolver $modelCodeResolver;

    /** @var array<string, array{used: int, ignored: list<int>}> */
    private array $repeatedDecisions = [];

    public function __construct(
        private readonly Config $config,
        ?UtmUrlBuilder $utmUrlBuilder = null,
        ?ModelCodeResolver $modelCodeResolver = null,
    ) {
        $this->utmUrlBuilder = $utmUrlBuilder ?? new UtmUrlBuilder();
        $this->modelCodeResolver = $modelCodeResolver ?? new ModelCodeResolver($config);
    }

    public function getAvailableCatalog(): array
    {
        $featureId = $this->config->getModelCodeFeatureId();

        if ($featureId <= 0) {
            return []; // module not fully installed/configured yet — no Feature to read
        }

        $shopId = (int) Context::getContext()->shop->id;
        $clientConfig = $this->config->getClientConfig();
        $link = new Link();
        $languages = Language::getLanguages(true, $shopId);
        $defaultLangId = $this->config->getDefaultLanguageId() ?: (int) Context::getContext()->language->id;

        $items = [];
        $sellable = $this->getProductsWithModelCode($featureId, $shopId, $defaultLangId);
        $winners = $this->pickRepeated($featureId, $shopId, $defaultLangId);

        foreach ($sellable as $productId => $modelCode) {
            if (isset($winners[$modelCode]) && $winners[$modelCode] !== $productId) {
                continue; // repeated model code: only the most recently updated active product counts
            }

            $quantity = (int) StockAvailable::getQuantityAvailableByProduct($productId, null, $shopId);

            if ($quantity <= 0) {
                continue; // out of stock, or backorder/"on demand"-only — never publish either
            }

            $items[] = new StockAvailability(
                modelCode: $modelCode,
                country: $clientConfig->market,
                value: $quantity,
                defaultUrl: $this->utmUrlBuilder->withTracking(
                    (string) $link->getProductLink($productId, null, null, null, $defaultLangId, $shopId),
                ),
                localizedUrls: $this->getLocalizedUrls($link, $productId, $shopId, $languages),
                onlinePurchaseEnabled: true,
                storePickupAvailable: $clientConfig->offersClickAndCollect,
            );
        }

        return $items;
    }

    /**
     * Model codes that more than one product carries, from the last getAvailableCatalog():
     * which product was used and which were ignored (shown by tudorsync:catalog:preview).
     *
     * @return array<string, array{used: int, ignored: list<int>}>
     */
    public function getRepeatedDecisions(): array
    {
        return $this->repeatedDecisions;
    }

    /**
     * When several products carry the same model code (e.g. Grau's old sellable product and the
     * TUDOR catalogue product with an "M" reference), only one counts: the active one updated
     * most recently (product_shop.date_upd; on a tie, the newest id). Its stock and URLs are what
     * is sent; if it isn't sellable or has no stock, the model isn't sent at all. Decided with
     * Juanjo (16-10-2026): "ante la duda, los datos del último activo y actualizado".
     *
     * @return array<string, int> model code => id_product of the one that counts (repeated codes only)
     */
    private function pickRepeated(int $featureId, int $shopId, int $defaultLangId): array
    {
        $byCode = [];
        foreach ($this->getProductsWithModelCode($featureId, $shopId, $defaultLangId, false) as $productId => $modelCode) {
            $byCode[$modelCode][] = $productId;
        }

        $repeated = array_filter($byCode, static fn (array $ids): bool => count($ids) > 1);
        $this->repeatedDecisions = [];

        if ($repeated === []) {
            return [];
        }

        $rows = Db::getInstance()->executeS('SELECT id_product, active, date_upd FROM `' . _DB_PREFIX_ . 'product_shop`
            WHERE id_shop = ' . $shopId . ' AND id_product IN (' . implode(',', array_map('intval', array_merge(...array_values($repeated)))) . ')');
        $state = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $state[(int) $row['id_product']] = ['active' => (int) $row['active'] === 1, 'date_upd' => (string) $row['date_upd']];
        }

        $winners = [];
        foreach ($repeated as $modelCode => $ids) {
            $active = array_values(array_filter($ids, static fn (int $id): bool => $state[$id]['active'] ?? false));
            usort($active, static fn (int $a, int $b): int => [$state[$b]['date_upd'], $b] <=> [$state[$a]['date_upd'], $a]);
            $winner = $active[0] ?? 0; // none active: none of them is sellable anyway
            $winners[(string) $modelCode] = $winner;
            $this->repeatedDecisions[(string) $modelCode] = ['used' => $winner, 'ignored' => array_values(array_diff($ids, [$winner]))];
        }

        return $winners;
    }

    /**
     * Products in this shop with a model code (Feature or reference rule), whatever their stock
     * or status. 0 means the program isn't set up (no code filled in and no rule matching), as
     * opposed to every enrolled watch being sold out.
     */
    public function countEnrolledProducts(): int
    {
        $featureId = $this->config->getModelCodeFeatureId();

        if ($featureId <= 0) {
            return 0;
        }

        $shopId = (int) Context::getContext()->shop->id;
        $defaultLangId = $this->config->getDefaultLanguageId() ?: (int) Context::getContext()->language->id;

        return count($this->getProductsWithModelCode($featureId, $shopId, $defaultLangId, false));
    }

    /**
     * Model codes of the given products, whatever their stock or status (for the order lines
     * of the attribution and the monthly report).
     *
     * @param int[] $productIds
     * @return array<int, string> id_product => model code, only for the products that have one
     */
    public function getModelCodesOf(array $productIds): array
    {
        $featureId = $this->config->getModelCodeFeatureId();
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($featureId <= 0 || $productIds === []) {
            return [];
        }

        $shopId = (int) Context::getContext()->shop->id;
        $defaultLangId = $this->config->getDefaultLanguageId() ?: (int) Context::getContext()->language->id;

        return $this->getProductsWithModelCode($featureId, $shopId, $defaultLangId, false, $productIds);
    }

    /**
     * Model code per product: the Feature value when filled in, otherwise the reference rule
     * for products of the configured TUDOR brand. Feature values are per language: the shop's
     * default language wins, otherwise the first non-empty value in any language (a custom
     * value may have been typed in only one of them).
     *
     * @param bool $sellableOnly Only products a customer can buy (active, visible, available for order).
     * @param int[] $onlyProductIds Limit to these products (all when empty).
     * @return array<int, string> id_product => model code
     */
    private function getProductsWithModelCode(int $featureId, int $shopId, int $defaultLangId, bool $sellableOnly = true, array $onlyProductIds = []): array
    {
        $manufacturerId = $this->modelCodeResolver->isRuleConfigured() ? $this->config->getManufacturerId() : 0;

        $sql = new DbQuery();
        $sql->select('p.id_product, p.reference, p.id_manufacturer, fvl.id_lang, fvl.value AS feature_value')
            ->from('product', 'p')
            ->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . $shopId)
            ->leftJoin('feature_product', 'fp', 'fp.id_product = p.id_product AND fp.id_feature = ' . $featureId)
            ->leftJoin('feature_value_lang', 'fvl', 'fvl.id_feature_value = fp.id_feature_value')
            ->where($manufacturerId > 0
                ? '(fp.id_feature_value IS NOT NULL OR p.id_manufacturer = ' . $manufacturerId . ')'
                : 'fp.id_feature_value IS NOT NULL')
            ->orderBy('p.id_product, (fvl.id_lang = ' . $defaultLangId . ') DESC, fvl.id_lang');

        if ($onlyProductIds !== []) {
            $sql->where('p.id_product IN (' . implode(',', array_map('intval', $onlyProductIds)) . ')');
        }

        if ($sellableOnly) {
            $sql->where('ps.active = 1')
                ->where("ps.visibility <> 'none'")
                ->where('ps.available_for_order = 1');
        }

        $rows = Db::getInstance()->executeS($sql);
        $fromFeature = [];
        $references = [];

        foreach ($rows !== false ? $rows : [] as $row) {
            $productId = (int) $row['id_product'];
            $modelCode = trim((string) $row['feature_value']);

            if ($modelCode !== '' && !isset($fromFeature[$productId])) {
                $fromFeature[$productId] = $modelCode;
            }

            if ($manufacturerId > 0 && (int) $row['id_manufacturer'] === $manufacturerId) {
                $references[$productId] = (string) $row['reference'];
            }
        }

        $codes = $fromFeature;

        foreach (array_diff_key($references, $fromFeature) as $productId => $reference) {
            $modelCode = $this->modelCodeResolver->fromReference($reference);

            if ($modelCode !== null) {
                $codes[$productId] = $modelCode;
            }
        }

        ksort($codes);

        return $codes;
    }

    /**
     * @param array<int, array<string, mixed>> $languages Rows as returned by Language::getLanguages().
     * @return array<string, string>
     */
    private function getLocalizedUrls(Link $link, int $productId, int $shopId, array $languages): array
    {
        $urls = [];

        foreach ($languages as $language) {
            $locale = Config::localeOf($language);

            if ($locale === '') {
                continue;
            }

            $urls[$locale] = $this->utmUrlBuilder->withTracking(
                (string) $link->getProductLink($productId, null, null, null, (int) $language['id_lang'], $shopId),
            );
        }

        return $urls;
    }
}
