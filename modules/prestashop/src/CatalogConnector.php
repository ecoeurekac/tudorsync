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
 * column is needed. It's excluded from the batch entirely (never returned) whenever its
 * available quantity isn't strictly positive: with no stock, PrestaShop's own "allow orders
 * when out of stock" setting would make it backorder/"on demand"-only, which TUDOR's
 * program must never publish, and there's no other reliable in-stock signal to fall back on.
 *
 * TODO: a product with stock management disabled entirely (always orderable, no quantity
 * tracked at all) would be wrongly excluded by this logic — confirm with Grau whether any
 * TUDOR models are sold that way before relying on this connector for them.
 *
 * Multi-language: builds one URL per active PrestaShop language via Link::getProductLink().
 */
final class CatalogConnector implements CatalogConnectorInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UtmUrlBuilder $utmUrlBuilder = new UtmUrlBuilder(),
    ) {
    }

    public function getAvailableCatalog(): array
    {
        $featureId = $this->config->getModelCodeFeatureId();

        if ($featureId <= 0) {
            return []; // module not fully installed/configured yet — no Feature to read
        }

        $clientConfig = $this->config->getClientConfig();
        $link = new Link();
        $languages = Language::getLanguages(true);
        $defaultLangId = (int) Context::getContext()->language->id;

        $items = [];

        foreach ($this->getProductsWithModelCode($featureId, $defaultLangId) as $row) {
            $productId = (int) $row['id_product'];
            $modelCode = trim((string) $row['feature_value']);

            if ($modelCode === '') {
                continue;
            }

            $quantity = (int) StockAvailable::getQuantityAvailableByProduct($productId);

            if ($quantity <= 0) {
                continue; // out of stock, or backorder/"on demand"-only — never publish either
            }

            $items[] = new StockAvailability(
                modelCode: $modelCode,
                country: $clientConfig->market,
                value: $quantity,
                defaultUrl: $this->utmUrlBuilder->withTracking(
                    (string) $link->getProductLink($productId, null, null, null, $defaultLangId),
                ),
                localizedUrls: $this->getLocalizedUrls($link, $productId, $languages),
                onlinePurchaseEnabled: true,
                storePickupAvailable: $clientConfig->offersClickAndCollect,
            );
        }

        return $items;
    }

    /**
     * @return array<int, array{id_product: string, feature_value: string}>
     */
    private function getProductsWithModelCode(int $featureId, int $langId): array
    {
        $sql = new DbQuery();
        $sql->select('fp.id_product, fvl.value AS feature_value')
            ->from('feature_product', 'fp')
            ->innerJoin('feature_value_lang', 'fvl', 'fvl.id_feature_value = fp.id_feature_value')
            ->where('fp.id_feature = ' . $featureId)
            ->where('fvl.id_lang = ' . $langId);

        $rows = Db::getInstance()->executeS($sql);

        return $rows !== false ? $rows : [];
    }

    /**
     * @param array<int, array<string, mixed>> $languages Rows as returned by Language::getLanguages().
     * @return array<string, string>
     */
    private function getLocalizedUrls(Link $link, int $productId, array $languages): array
    {
        $urls = [];

        foreach ($languages as $language) {
            $locale = (string) ($language['locale'] ?? $language['language_code'] ?? $language['iso_code'] ?? '');

            if ($locale === '') {
                continue;
            }

            $urls[$locale] = $this->utmUrlBuilder->withTracking(
                (string) $link->getProductLink($productId, null, null, null, (int) $language['id_lang']),
            );
        }

        return $urls;
    }
}
