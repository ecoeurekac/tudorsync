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
 * A product is enrolled in the TUDOR program by having a value set for the "TUDOR Model
 * Code" Feature (created once by Tudorsync::install(), its id stored via
 * Config::KEY_MODEL_CODE_FEATURE_ID) — chosen over a database schema migration so no new
 * column is needed.
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
 * Multi-language: builds one URL per active PrestaShop language via Link::getProductLink().
 */
final class CatalogConnector implements CatalogConnectorInterface
{
    private readonly UtmUrlBuilder $utmUrlBuilder;

    public function __construct(
        private readonly Config $config,
        ?UtmUrlBuilder $utmUrlBuilder = null,
    ) {
        $this->utmUrlBuilder = $utmUrlBuilder ?? new UtmUrlBuilder();
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

        foreach ($this->getProductsWithModelCode($featureId, $shopId, $defaultLangId) as $productId => $modelCode) {
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
     * Model code per sellable product. Feature values are per language: the shop's default
     * language wins, otherwise the first non-empty value in any language (a custom value may
     * have been typed in only one of them).
     *
     * @return array<int, string> id_product => model code
     */
    private function getProductsWithModelCode(int $featureId, int $shopId, int $defaultLangId): array
    {
        $sql = new DbQuery();
        $sql->select('fp.id_product, fvl.id_lang, fvl.value AS feature_value')
            ->from('feature_product', 'fp')
            ->innerJoin('feature_value_lang', 'fvl', 'fvl.id_feature_value = fp.id_feature_value')
            ->innerJoin('product_shop', 'ps', 'ps.id_product = fp.id_product AND ps.id_shop = ' . $shopId)
            ->where('fp.id_feature = ' . $featureId)
            ->where('ps.active = 1')
            ->where("ps.visibility <> 'none'")
            ->where('ps.available_for_order = 1')
            ->orderBy('fp.id_product, (fvl.id_lang = ' . $defaultLangId . ') DESC, fvl.id_lang');

        $rows = Db::getInstance()->executeS($sql);
        $codes = [];

        foreach ($rows !== false ? $rows : [] as $row) {
            $productId = (int) $row['id_product'];
            $modelCode = trim((string) $row['feature_value']);

            if ($modelCode !== '' && !isset($codes[$productId])) {
                $codes[$productId] = $modelCode;
            }
        }

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
