<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Tudorsync\Core\Contract\CatalogConnectorInterface;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Url\UtmUrlBuilder;
use Tudorsync\EcommerceSync\Model\Config\Source\ValueMode;
use Tudorsync\EcommerceSync\Model\Stock\SalableQtyProvider;

/**
 * Reads Magento's own catalog/stock data and maps it into tudorsync/core's
 * Tudorsync\Core\Domain\StockAvailability shape.
 *
 * Enrollment: a product takes part in the TUDOR program when it has a model code — the
 * `tudor_model_code` attribute or, failing that, one derived from its SKU (ModelCodeResolver).
 *
 * Exclusion ("bajo demanda" must never be published): the product is left out when it's
 * disabled in the default store view, not visible, not stock-managed, out of stock, allows
 * backorders, or has no salable quantity left (MSI reservations already discounted).
 *
 * One record per model code: TUDOR's batch is keyed by model/country, but a store can have
 * several products for the same model (Quera does). Those are merged — quantities summed, URLs
 * taken from the product with the most salable stock — instead of sending duplicate records.
 *
 * Multi-language: one localized URL per active store view where the product is enabled and
 * visible, keyed by the store view's locale (see Config::getLocaleCode()). The default URL is
 * the default store view's.
 *
 * Not wired up yet: per-point-of-sale click & collect (storesAvailabilityDetails / RSWI) —
 * storePickupAvailable is a flat store-wide toggle from admin config.
 */
class CatalogConnector implements CatalogConnectorInterface
{
    private readonly UtmUrlBuilder $utmUrlBuilder;

    /**
     * $utmUrlBuilder is nullable instead of `= new UtmUrlBuilder()`: setup:di:compile writes an object
     * default into generated/metadata as UtmUrlBuilder::__set_state(), which does not exist, and every
     * request in production mode then fails ("There is an error in generated/metadata/global.php").
     */
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly SalableQtyProvider $salableQtyProvider,
        private readonly ModelCodeResolver $modelCodeResolver,
        private readonly Config $config,
        ?UtmUrlBuilder $utmUrlBuilder = null,
    ) {
        $this->utmUrlBuilder = $utmUrlBuilder ?? new UtmUrlBuilder();
    }

    public function getAvailableCatalog(): array
    {
        return $this->collect()->items;
    }

    public function collect(): CatalogSnapshot
    {
        $defaultStore = $this->storeManager->getDefaultStoreView();
        $defaultStoreId = (int) $defaultStore->getId();
        $websiteCode = (string) $this->storeManager->getWebsite($defaultStore->getWebsiteId())->getCode();
        $country = $this->config->getCountry($defaultStoreId);
        $warnings = $this->getWarnings($country);

        /** @var array<int, array<string, mixed>> $products */
        $products = [];
        /** @var array<string, int[]> $productIdsByModelCode */
        $productIdsByModelCode = [];

        /** @var Product $product */
        foreach ($this->getCandidateCollection($defaultStoreId) as $product) {
            $productId = (int) $product->getId();
            $sku = (string) $product->getSku();
            $modelCode = $this->modelCodeResolver->resolve(
                (string) $product->getData(ModelCodeResolver::ATTRIBUTE_CODE),
                $sku,
            );

            $row = [
                'product_id' => $productId,
                'sku' => $sku,
                'name' => (string) $product->getName(),
                'model_code' => $modelCode,
                'model_code_source' => trim((string) $product->getData(ModelCodeResolver::ATTRIBUTE_CODE)) !== ''
                    ? 'attribute'
                    : 'sku',
                'qty' => null,
                'salable_qty' => null,
                'status' => CatalogSnapshot::STATUS_EXCLUDED,
                'reason' => null,
            ];

            if ($modelCode === null) {
                $row['reason'] = 'no model code (attribute empty and SKU rule did not match)';
                $products[$productId] = $row;
                continue;
            }

            $reason = $this->getProductExclusionReason($product);

            if ($reason === null) {
                $stock = $this->salableQtyProvider->getStockData($productId, $sku, $websiteCode);
                $row['qty'] = $stock['qty'];
                $row['salable_qty'] = $stock['salable_qty'];
                $reason = $this->getStockExclusionReason($stock);
            }

            if ($reason !== null) {
                $row['reason'] = $reason;
                $products[$productId] = $row;
                continue;
            }

            $row['status'] = CatalogSnapshot::STATUS_SENT;
            $products[$productId] = $row;
            $productIdsByModelCode[$modelCode][] = $productId;
        }

        $items = [];

        foreach ($productIdsByModelCode as $modelCode => $productIds) {
            usort(
                $productIds,
                static fn (int $a, int $b): int => [$products[$b]['salable_qty'], $a] <=> [$products[$a]['salable_qty'], $b],
            );
            $mainProductId = $productIds[0];
            $totalQty = 0;

            foreach ($productIds as $productId) {
                $totalQty += (int) $products[$productId]['salable_qty'];

                if ($productId !== $mainProductId) {
                    $products[$productId]['status'] = CatalogSnapshot::STATUS_MERGED;
                    $products[$productId]['reason'] = sprintf('same model code as product %d', $mainProductId);
                }
            }

            $defaultUrl = $this->getTrackedUrl($mainProductId, $defaultStore);

            if ($defaultUrl === null) {
                $products[$mainProductId]['status'] = CatalogSnapshot::STATUS_EXCLUDED;
                $products[$mainProductId]['reason'] = 'no URL in the default store view';
                continue;
            }

            $items[] = new StockAvailability(
                modelCode: (string) $modelCode,
                country: $country,
                value: $this->config->getValueMode($defaultStoreId) === ValueMode::SIGNAL ? 1 : $totalQty,
                defaultUrl: $defaultUrl,
                localizedUrls: $this->getLocalizedUrls($mainProductId),
                onlinePurchaseEnabled: true,
                storePickupAvailable: $this->config->getClientConfig($defaultStoreId)->offersClickAndCollect,
                homeDeliveryTimingHours: $this->config->getHomeDeliveryTimingHours($defaultStoreId),
            );
            $products[$mainProductId]['merged_count'] = count($productIds);
        }

        return new CatalogSnapshot($items, $products, $warnings);
    }

    /**
     * TUDOR products (same candidates as the sync) whose SKU or name contains $query, for the API
     * test page. Includes the ones the sync would leave out, with the reason.
     *
     * @return list<array{product_id: int, sku: string, name: string, model_code: ?string,
     *     salable_qty: ?int, excluded_reason: ?string}>
     */
    public function searchCandidates(string $query, int $limit = 20): array
    {
        $defaultStore = $this->storeManager->getDefaultStoreView();
        $websiteCode = (string) $this->storeManager->getWebsite($defaultStore->getWebsiteId())->getCode();
        $collection = $this->getCandidateCollection((int) $defaultStore->getId());
        $like = '%' . addcslashes(trim($query), '%_') . '%';
        $collection->addAttributeToFilter([['attribute' => 'sku', 'like' => $like], ['attribute' => 'name', 'like' => $like]], null, 'left')
            ->setOrder('sku', 'ASC')
            ->setPageSize($limit);
        $rows = [];

        /** @var Product $product */
        foreach ($collection as $product) {
            $productId = (int) $product->getId();
            $sku = (string) $product->getSku();
            $modelCode = $this->modelCodeResolver->resolve((string) $product->getData(ModelCodeResolver::ATTRIBUTE_CODE), $sku);
            $reason = $modelCode === null ? 'no model code' : $this->getProductExclusionReason($product);
            $stock = $this->salableQtyProvider->getStockData($productId, $sku, $websiteCode);

            $rows[] = [
                'product_id' => $productId,
                'sku' => $sku,
                'name' => (string) $product->getName(),
                'model_code' => $modelCode,
                'salable_qty' => $stock['salable_qty'],
                'excluded_reason' => $reason ?? $this->getStockExclusionReason($stock),
            ];
        }

        return $rows;
    }

    /**
     * The record this product would publish, built the same way as in collect() but for this
     * product alone and whatever its stock (API tests: e.g. publish a model or send it with 0).
     * $value replaces the quantity; null = its salable quantity (or 1 in signal mode). Null when the
     * product has no model code or no URL in the default store view.
     */
    public function buildAvailability(int $productId, ?int $value = null): ?StockAvailability
    {
        $defaultStore = $this->storeManager->getDefaultStoreView();
        $defaultStoreId = (int) $defaultStore->getId();

        try {
            /** @var Product $product */
            $product = $this->productRepository->getById($productId, false, $defaultStoreId);
        } catch (NoSuchEntityException) {
            return null;
        }

        $modelCode = $this->modelCodeResolver->resolve(
            (string) $product->getData(ModelCodeResolver::ATTRIBUTE_CODE),
            (string) $product->getSku(),
        );
        $defaultUrl = $this->getTrackedUrl($productId, $defaultStore);

        if ($modelCode === null || $defaultUrl === null) {
            return null;
        }

        if ($value === null) {
            $websiteCode = (string) $this->storeManager->getWebsite($defaultStore->getWebsiteId())->getCode();
            $value = $this->config->getValueMode($defaultStoreId) === ValueMode::SIGNAL
                ? 1
                : max(0, (int) $this->salableQtyProvider->getStockData($productId, (string) $product->getSku(), $websiteCode)['salable_qty']);
        }

        return new StockAvailability(
            modelCode: $modelCode,
            country: $this->config->getCountry($defaultStoreId),
            value: $value,
            defaultUrl: $defaultUrl,
            localizedUrls: $this->getLocalizedUrls($productId),
            onlinePurchaseEnabled: true,
            storePickupAvailable: $this->config->getClientConfig($defaultStoreId)->offersClickAndCollect,
            homeDeliveryTimingHours: $this->config->getHomeDeliveryTimingHours($defaultStoreId),
        );
    }

    private function getCandidateCollection(int $storeId): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->productCollectionFactory->create();
        // Stock is judged per product below (salable qty); keep Magento's "hide out of stock"
        // filter from silently dropping candidates, so the preview can say why each is excluded.
        $collection->setFlag('has_stock_status_filter', true);
        $collection->setStoreId($storeId)
            ->addAttributeToSelect([ModelCodeResolver::ATTRIBUTE_CODE, 'name', 'status', 'visibility']);

        $conditions = [['attribute' => ModelCodeResolver::ATTRIBUTE_CODE, 'neq' => '']];
        $skuPrefix = $this->config->getSkuPrefix();

        if ($skuPrefix !== '' && $this->modelCodeResolver->isSkuRuleConfigured()) {
            $conditions[] = ['attribute' => 'sku', 'like' => addcslashes($skuPrefix, '%_') . '%'];
        }

        $collection->addAttributeToFilter($conditions, null, 'left');

        return $collection;
    }

    private function getProductExclusionReason(Product $product): ?string
    {
        if ((int) $product->getStatus() !== Status::STATUS_ENABLED) {
            return 'disabled';
        }

        if ((int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            return 'not visible individually';
        }

        return null;
    }

    /**
     * @param array{in_stock: bool, backorders: bool, manage_stock: bool, qty: int, salable_qty: int} $stock
     */
    private function getStockExclusionReason(array $stock): ?string
    {
        return match (true) {
            !$stock['manage_stock'] => 'stock not managed (cannot tell if it is immediately available)',
            $stock['backorders'] => 'backorders allowed (on demand)',
            !$stock['in_stock'] => 'out of stock',
            $stock['salable_qty'] <= 0 => 'no salable quantity left (reserved by pending orders)',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function getLocalizedUrls(int $productId): array
    {
        $urls = [];

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }

            $locale = $this->config->getLocaleCode((int) $store->getId());

            if ($locale === null || isset($urls[$locale])) {
                continue; // first store view wins when two share a locale
            }

            $url = $this->getTrackedUrl($productId, $store);

            if ($url !== null) {
                $urls[$locale] = $url;
            }
        }

        return $urls;
    }

    /**
     * Product URL in that store view with UTM tracking, or null when the product isn't sold
     * there (not assigned to its website, disabled or not visible in that view).
     */
    private function getTrackedUrl(int $productId, StoreInterface $store): ?string
    {
        try {
            /** @var Product $storeScopedProduct */
            $storeScopedProduct = $this->productRepository->getById($productId, false, (int) $store->getId());
        } catch (NoSuchEntityException) {
            return null;
        }

        if (!in_array((int) $store->getWebsiteId(), array_map('intval', $storeScopedProduct->getWebsiteIds()), true)
            || (int) $storeScopedProduct->getStatus() !== Status::STATUS_ENABLED
            || (int) $storeScopedProduct->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE
        ) {
            return null;
        }

        $url = (string) $storeScopedProduct->setStoreId((int) $store->getId())->getUrlModel()->getUrl(
            $storeScopedProduct,
            ['_scope' => (int) $store->getId(), '_nosid' => true],
        );

        return $url !== '' ? $this->utmUrlBuilder->withTracking($url) : null;
    }

    /**
     * @return string[]
     */
    private function getWarnings(string $country): array
    {
        $warnings = [];

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $warnings[] = 'Market / Country is not a 2-letter ISO code — nothing will be synced until it is set.';
        }

        if ($this->config->getSkuPattern() !== '' && !$this->modelCodeResolver->isSkuRuleConfigured()) {
            $warnings[] = 'The SKU pattern in config is not a valid regular expression — SKU rule ignored.';
        }

        if ($this->config->getSkuPattern() !== '' && $this->config->getSkuPrefix() === '') {
            $warnings[] = 'SKU pattern set without a SKU prefix — only products with tudor_model_code filled are read.';
        }

        if (!$this->salableQtyProvider->isMsiEnabled()) {
            $warnings[] = 'MSI disabled: using physical stock qty, pending-order reservations are not discounted.';
        }

        return $warnings;
    }
}
