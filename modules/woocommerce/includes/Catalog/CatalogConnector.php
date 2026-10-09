<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Catalog;

use Tudorsync\Core\Contract\CatalogConnectorInterface;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Url\UtmUrlBuilder;
use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\ModelCodeResolver;

/**
 * Reads WooCommerce's catalog and stock and maps it into core's StockAvailability records.
 *
 * Products looked at: every product or variation with the `_tudor_model_code` meta filled in,
 * plus, when a SKU rule is configured, the products whose SKU it matches (only those starting
 * with the SKU prefix and/or within the product scope terms, when set).
 *
 * A product is left out (never sent) when it isn't published, is hidden from the catalog, has
 * no model code, doesn't manage stock (unless the "unmanaged stock" setting sends it as 1),
 * allows backorders ("on demand", never published), is out of stock, or has nothing left once
 * the stock held by checkouts in progress is taken off. Variable products are not read
 * themselves: each variation is, with its own SKU or code.
 *
 * Several products with the same model code become one record: `value` is the sum of their
 * salable quantity (or 1 in "signal" mode) and the URLs are those of the product with the most
 * stock (lowest id on a tie). Core then reviews the records (CatalogReview).
 */
final class CatalogConnector implements CatalogConnectorInterface
{
    private readonly UtmUrlBuilder $utmUrlBuilder;

    public function __construct(
        private readonly Config $config,
        private readonly ModelCodeResolver $modelCodeResolver,
        private readonly LocalizedUrls $localizedUrls,
        ?UtmUrlBuilder $utmUrlBuilder = null,
    ) {
        $this->utmUrlBuilder = $utmUrlBuilder ?? new UtmUrlBuilder();
    }

    public function getAvailableCatalog(): array
    {
        return $this->collect()->getItemsToSend();
    }

    public function collect(): CatalogSnapshot
    {
        $warnings = $this->getConfigWarnings();
        $country = $this->config->getCountry();
        $products = [];
        $groups = [];

        foreach ($this->getCandidateIds() as $productId) {
            $product = wc_get_product($productId);

            if (!$product instanceof \WC_Product) {
                continue;
            }

            $row = $this->describe($product);
            $resolved = $this->modelCodeResolver->resolveWithSource(
                (string) $product->get_meta(Config::META_MODEL_CODE),
                (string) $product->get_sku(),
            );

            if ($resolved === null) {
                $products[$productId] = $this->exclude($row, __('no model code (field empty and the SKU rule did not match)', 'tudorsync'));
                continue;
            }

            $row['model_code'] = $resolved['code'];
            $row['model_code_source'] = $resolved['source'];

            $problem = $this->getProductProblem($product);
            if ($problem !== null) {
                $products[$productId] = $this->exclude($row, $problem);
                continue;
            }

            $stock = $this->getStock($product);
            $row['qty'] = $stock['qty'];
            $row['salable_qty'] = $stock['salable_qty'];

            if ($stock['problem'] !== null) {
                $products[$productId] = $this->exclude($row, $stock['problem']);
                continue;
            }

            $row['status'] = CatalogSnapshot::SENT;
            $products[$productId] = $row;
            $groups[$resolved['code']][] = $productId;
        }

        $items = [];

        foreach ($groups as $modelCode => $productIds) {
            usort($productIds, static fn (int $a, int $b): int => [$products[$b]['salable_qty'], $a] <=> [$products[$a]['salable_qty'], $b]);
            $mainId = $productIds[0];

            foreach (array_slice($productIds, 1) as $otherId) {
                $products[$otherId]['status'] = CatalogSnapshot::MERGED;
                /* translators: %d: id of the product the watch is merged into */
                $products[$otherId]['reason'] = sprintf(__('same model code as product %d', 'tudorsync'), $mainId);
            }

            $product = wc_get_product($mainId);
            $permalink = $product instanceof \WC_Product ? (string) $product->get_permalink() : '';

            if ($permalink === '') {
                $products[$mainId] = $this->exclude($products[$mainId], __('no product URL', 'tudorsync'));
                continue;
            }

            if (count($productIds) > 1) {
                $products[$mainId]['merged_count'] = count($productIds) - 1;
            }

            $value = $this->config->sendsSignalOnly()
                ? 1
                : array_sum(array_map(static fn (int $id): int => $products[$id]['salable_qty'], $productIds));

            $items[] = new StockAvailability(
                modelCode: $modelCode,
                country: $country,
                value: $value,
                defaultUrl: $this->utmUrlBuilder->withTracking($permalink),
                localizedUrls: array_map(
                    fn (string $url): string => $this->utmUrlBuilder->withTracking($url),
                    $this->localizedUrls->forProduct($product, $permalink),
                ),
                onlinePurchaseEnabled: true,
                storePickupAvailable: $this->config->offersClickAndCollect(),
                homeDeliveryTimingHours: $this->config->getHomeDeliveryTimingHours(),
            );
        }

        $review = CatalogReview::of($items);

        // Products whose model core left out are not sent either.
        foreach ($products as $productId => $row) {
            if ($row['status'] !== CatalogSnapshot::EXCLUDED && $row['model_code'] !== '') {
                $exclusion = $review->getExclusionFor($row['model_code']);
                if ($exclusion !== null) {
                    /* translators: %s: reason given by the review in tudorsync/core */
                    $products[$productId] = $this->exclude($row, sprintf(__('TUDOR review: %s', 'tudorsync'), $exclusion->message));
                }
            }
        }

        return new CatalogSnapshot($items, $products, $warnings, $review);
    }

    /**
     * One product's record as it would be sent, whatever its stock (the API test page sends a
     * single product or a value of its choosing). Null when the product has no model code.
     */
    public function buildAvailability(int $productId, ?int $value = null): ?StockAvailability
    {
        $product = wc_get_product($productId);

        if (!$product instanceof \WC_Product) {
            return null;
        }

        $modelCode = $this->modelCodeResolver->resolveProduct($product);
        $permalink = (string) $product->get_permalink();

        if ($modelCode === null) {
            return null;
        }

        return new StockAvailability(
            modelCode: $modelCode,
            country: $this->config->getCountry(),
            value: $value ?? max(0, $this->getStock($product)['salable_qty']),
            defaultUrl: $this->utmUrlBuilder->withTracking($permalink),
            localizedUrls: array_map(
                fn (string $url): string => $this->utmUrlBuilder->withTracking($url),
                $this->localizedUrls->forProduct($product, $permalink),
            ),
            onlinePurchaseEnabled: true,
            storePickupAvailable: $this->config->offersClickAndCollect(),
            homeDeliveryTimingHours: $this->config->getHomeDeliveryTimingHours(),
        );
    }

    /**
     * Products (or variations) matching a SKU, name or model code, for the API test page.
     *
     * @return list<array{product_id: int, sku: string, name: string, model_code: string, salable_qty: int}>
     */
    public function search(string $query, int $limit = 20): array
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($query) . '%';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = p.ID
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (l.sku LIKE %s OR p.post_title LIKE %s OR m.meta_value LIKE %s)
             ORDER BY p.ID LIMIT %d",
            Config::META_MODEL_CODE,
            $like,
            $like,
            $like,
            $limit,
        ));

        $results = [];

        foreach (array_map('intval', $ids) as $productId) {
            $product = wc_get_product($productId);

            if (!$product instanceof \WC_Product || $product->is_type('variable')) {
                continue;
            }

            $results[] = [
                'product_id' => $productId,
                'sku' => (string) $product->get_sku(),
                'name' => wp_strip_all_tags($product->get_name()),
                'model_code' => (string) $this->modelCodeResolver->resolveProduct($product),
                'salable_qty' => $this->getStock($product)['salable_qty'],
            ];
        }

        return $results;
    }

    /**
     * Ids of every product to look at: model code meta filled in, or SKU rule candidates.
     *
     * @return list<int>
     */
    public function getCandidateIds(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value <> ''
             WHERE p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft')",
            Config::META_MODEL_CODE,
        ));

        foreach ($this->getSkuRuleCandidateIds() as $productId) {
            $ids[] = $productId;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function getSkuRuleCandidateIds(): array
    {
        global $wpdb;

        if (!$this->modelCodeResolver->isSkuRuleConfigured()) {
            return [];
        }

        $prefix = $this->config->getSkuPrefix();
        $sql = "SELECT l.product_id, l.sku, p.post_parent FROM {$wpdb->wc_product_meta_lookup} l
                INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
                WHERE l.sku <> '' AND p.post_type IN ('product', 'product_variation')
                  AND p.post_status NOT IN ('trash', 'auto-draft')";

        if ($prefix !== '') {
            $sql .= $wpdb->prepare(' AND l.sku LIKE %s', $wpdb->esc_like($prefix) . '%');
        }

        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
        $inScope = $this->getScopeProductIds();
        $ids = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $parentId = (int) $row['post_parent'];

            if ($inScope !== null && !isset($inScope[$productId]) && !isset($inScope[$parentId])) {
                continue;
            }

            if ($this->modelCodeResolver->fromSku((string) $row['sku']) !== null) {
                $ids[] = $productId;
            }
        }

        return $ids;
    }

    /**
     * Products in the configured scope terms, as a set; null when no scope is configured.
     *
     * @return array<int, true>|null
     */
    private function getScopeProductIds(): ?array
    {
        $scope = $this->config->getProductScope();

        if ($scope === []) {
            return null;
        }

        $ids = [];

        foreach ($scope as $term) {
            if (!taxonomy_exists($term['taxonomy'])) {
                continue;
            }

            $found = get_posts([
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'numberposts' => -1,
                'suppress_filters' => true,
                'tax_query' => [[
                    'taxonomy' => $term['taxonomy'],
                    'field' => 'slug',
                    'terms' => $term['slug'],
                    'include_children' => true,
                ]],
            ]);

            foreach ($found as $productId) {
                $ids[(int) $productId] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function getConfigWarnings(): array
    {
        $warnings = [];

        if (preg_match('/^[A-Z]{2}$/', $this->config->getCountry()) !== 1) {
            $warnings[] = __('Market / Country is not a 2-letter ISO code (e.g. ES): nothing will be synced.', 'tudorsync');
        }

        $pattern = $this->config->getSkuPattern();

        if ($pattern !== '' && !ModelCodeResolver::isValidPattern($pattern)) {
            $warnings[] = __('The SKU pattern is not a valid regular expression (it needs delimiters, e.g. /^M(.+)$/): the SKU rule is ignored.', 'tudorsync');
        } elseif ($pattern !== '' && $this->config->getSkuPrefix() === '' && $this->config->getProductScope() === []) {
            $warnings[] = __('The SKU rule is tried on the whole catalog: set a SKU prefix or a product scope to limit it to TUDOR watches.', 'tudorsync');
        }

        foreach ($this->config->getProductScope() as $term) {
            if (!taxonomy_exists($term['taxonomy'])) {
                /* translators: %s: taxonomy name */
                $warnings[] = sprintf(__('Product scope: the taxonomy "%s" does not exist.', 'tudorsync'), $term['taxonomy']);
            } elseif (!term_exists($term['slug'], $term['taxonomy'])) {
                /* translators: 1: term slug, 2: taxonomy name */
                $warnings[] = sprintf(__('Product scope: there is no term "%1$s" in "%2$s".', 'tudorsync'), $term['slug'], $term['taxonomy']);
            }
        }

        return $warnings;
    }

    /**
     * Why a product can't be published regardless of its stock; null when it can.
     */
    private function getProductProblem(\WC_Product $product): ?string
    {
        if ($product->is_type('variable')) {
            return __('variable product: each variation is read on its own', 'tudorsync');
        }

        $parent = $product->get_parent_id() > 0 ? wc_get_product($product->get_parent_id()) : null;
        $visibilitySource = $parent instanceof \WC_Product ? $parent : $product;

        if ($visibilitySource->get_status() !== 'publish' || ($parent !== null && $product->get_status() !== 'publish')) {
            return __('not published', 'tudorsync');
        }

        if ($visibilitySource->get_catalog_visibility() === 'hidden') {
            return __('hidden from the catalog', 'tudorsync');
        }

        if ($visibilitySource->get_post_password() !== '') {
            return __('password protected', 'tudorsync');
        }

        return null;
    }

    /**
     * Stock of one product and, if it can't be sold online right now, why.
     *
     * The salable quantity is the stock minus what checkouts in progress hold
     * (wc_get_held_stock_quantity()): WooCommerce already took off the stock of placed orders.
     *
     * @return array{qty: int, salable_qty: int, problem: ?string}
     */
    public function getStock(\WC_Product $product): array
    {
        $managed = $product->managing_stock();
        $qty = $managed ? (int) $product->get_stock_quantity() : 0;
        $held = $managed && function_exists('wc_get_held_stock_quantity') ? (int) wc_get_held_stock_quantity($product) : 0;
        $salable = $qty - $held;

        $problem = match (true) {
            // is_in_stock() is also true for "on backorder", so that status is checked first.
            $product->backorders_allowed(), $product->get_stock_status() === 'onbackorder' => __('backorders allowed (on demand)', 'tudorsync'),
            !$product->is_in_stock() => __('out of stock', 'tudorsync'),
            !$managed && !$this->config->sendsUnmanagedStock() => __('stock not managed (cannot tell if it is immediately available)', 'tudorsync'),
            $managed && $salable <= 0 => $held > 0
                ? __('no salable quantity left (held by checkouts in progress)', 'tudorsync')
                : __('no stock left', 'tudorsync'),
            default => null,
        };

        if (!$managed && $problem === null) {
            $salable = 1;
        }

        return ['qty' => $qty, 'salable_qty' => $salable, 'problem' => $problem];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(\WC_Product $product): array
    {
        return [
            'product_id' => $product->get_id(),
            'sku' => (string) $product->get_sku(),
            'name' => wp_strip_all_tags($product->get_name()),
            'model_code' => '',
            'model_code_source' => '',
            'qty' => 0,
            'salable_qty' => 0,
            'status' => CatalogSnapshot::EXCLUDED,
            'reason' => '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function exclude(array $row, string $reason): array
    {
        $row['status'] = CatalogSnapshot::EXCLUDED;
        $row['reason'] = $reason;
        unset($row['merged_count']);

        return $row;
    }

    /**
     * Normalized code, as the queue and the review use it.
     */
    public static function normalize(string $modelCode): string
    {
        return ValidModelList::normalize($modelCode);
    }
}
