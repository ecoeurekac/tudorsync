<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Core\Contract\CatalogConnectorInterface;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Url\UtmUrlBuilder;
use WC_Product;

/**
 * Reads WooCommerce's own catalog/stock data and maps it into tudorsync/core's
 * Tudorsync\Core\Domain\StockAvailability shape.
 *
 * A product is enrolled in the TUDOR program simply by having the `_tudor_model_code` post
 * meta (added to the product edit screen by ProductField) filled in — no separate "enable
 * for TUDOR" toggle. It's excluded from the batch entirely (never returned) when it's not
 * in stock, or when it allows backorders (WooCommerce's closest equivalent to TUDOR's
 * "bajo demanda" / on-demand items, which must never be published).
 *
 * `value` sent to TUDOR is the real stock quantity when this product manages stock; for a
 * product with stock management turned off (WooCommerce then treats "in stock" as a manual
 * yes/no toggle with no quantity at all), `1` is sent as a plain in-stock signal — see
 * Tudorsync\Core\Domain\StockAvailability's docblock on the broader open question of what
 * TUDOR's `value` field actually represents.
 *
 * Multi-language: WooCommerce core has no built-in multilingual product URLs. Rather than
 * assume a specific plugin (Polylang, WPML, ...), this exposes a `tudorsync_localized_urls`
 * filter — a site using one of those plugins should hook in there to supply per-language
 * URLs; until confirmed for Gordillo/Saphir, localizedUrls defaults to empty and only
 * defaultUrl is sent.
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
        $clientConfig = $this->config->getClientConfig();

        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'meta_query' => [
                [
                    'key' => Config::META_MODEL_CODE,
                    'value' => '',
                    'compare' => '!=',
                ],
            ],
        ]);

        $items = [];

        foreach ($products as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }

            $modelCode = trim((string) get_post_meta($product->get_id(), Config::META_MODEL_CODE, true));

            if ($modelCode === '' || !$product->is_in_stock() || $product->backorders_allowed()) {
                continue; // unset mc, out of stock, or backorder/"on demand"-only — never publish
            }

            $quantity = $product->managing_stock() ? (int) $product->get_stock_quantity() : 1;

            if ($quantity <= 0) {
                continue;
            }

            $defaultUrl = $this->utmUrlBuilder->withTracking((string) get_permalink($product->get_id()));

            $items[] = new StockAvailability(
                modelCode: $modelCode,
                country: $clientConfig->market,
                value: $quantity,
                defaultUrl: $defaultUrl,
                localizedUrls: $this->getLocalizedUrls($product, $defaultUrl),
                onlinePurchaseEnabled: true,
                storePickupAvailable: $clientConfig->offersClickAndCollect,
            );
        }

        return $items;
    }

    /**
     * Locale code => URL, sourced from the `tudorsync_localized_urls` filter (see class
     * docblock). The filter receives the untracked default URL as context in case a hook
     * implementation wants to derive locale URLs from it.
     *
     * @return array<string, string>
     */
    private function getLocalizedUrls(WC_Product $product, string $defaultUrl): array
    {
        $urls = apply_filters('tudorsync_localized_urls', [], $product, $defaultUrl);

        if (!is_array($urls) || $urls === []) {
            return [];
        }

        return array_map(
            fn (string $url): string => $this->utmUrlBuilder->withTracking($url),
            $urls,
        );
    }
}
