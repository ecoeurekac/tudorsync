<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Tudorsync\Core\Contract\CatalogConnectorInterface;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Url\UtmUrlBuilder;

/**
 * Reads Magento's own catalog/stock data and maps it into tudorsync/core's
 * Tudorsync\Core\Domain\StockAvailability shape.
 *
 * A product is considered enrolled in the TUDOR program simply by having the
 * `tudor_model_code` attribute (added by Setup\Patch\Data\AddTudorModelCodeAttribute)
 * filled in — there's no separate "enable for TUDOR" toggle. It's excluded from the batch
 * entirely (never returned) when:
 *   - it isn't enabled in the given store, or
 *   - it's out of stock, or its stock item allows backorders (Magento's closest equivalent
 *     to TUDOR's "bajo demanda" / on-demand items, which must never be published).
 *
 * Multi-language: iterates every configured store view and reloads the product per view via
 * ProductRepositoryInterface, so a Magento site using one store view per language gets one
 * localized URL per view (see Tudorsync\Core\Domain\StockAvailability::$localizedUrls).
 *
 * TODO not yet wired up here: MSI multi-source stock (this uses the legacy single-source
 * StockRegistryInterface, which covers the common single-warehouse retailer case but not a
 * multi-source MSI setup) and per-source click & collect / storesAvailabilityDetails —
 * storePickupAvailable below is a flat store-wide toggle from admin config only.
 */
class CatalogConnector implements CatalogConnectorInterface
{
    private const ATTRIBUTE_MODEL_CODE = 'tudor_model_code';

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly UtmUrlBuilder $utmUrlBuilder = new UtmUrlBuilder(),
    ) {
    }

    public function getAvailableCatalog(): array
    {
        $clientConfig = $this->config->getClientConfig();
        $defaultStoreId = (int) $this->storeManager->getStore()->getId();

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([self::ATTRIBUTE_MODEL_CODE, 'name'])
            ->addAttributeToFilter(self::ATTRIBUTE_MODEL_CODE, ['notnull' => true])
            ->addAttributeToFilter(self::ATTRIBUTE_MODEL_CODE, ['neq' => ''])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->setStore($defaultStoreId);

        $items = [];

        /** @var Product $product */
        foreach ($collection as $product) {
            $modelCode = (string) $product->getData(self::ATTRIBUTE_MODEL_CODE);

            if ($modelCode === '') {
                continue;
            }

            $stockItem = $this->stockRegistry->getStockItem($product->getId());
            $qty = (int) $stockItem->getQty();
            $allowsBackorders = (bool) $stockItem->getBackorders();
            $inStock = (bool) $stockItem->getIsInStock();

            if (!$inStock || $allowsBackorders || $qty <= 0) {
                continue; // never publish backorder-only or genuinely out-of-stock items
            }

            $items[] = new StockAvailability(
                modelCode: $modelCode,
                country: $clientConfig->market,
                value: $qty,
                defaultUrl: $this->buildTrackedUrl((int) $product->getId(), $defaultStoreId),
                localizedUrls: $this->getLocalizedUrls((int) $product->getId()),
                onlinePurchaseEnabled: true,
                storePickupAvailable: $clientConfig->offersClickAndCollect,
            );
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function getLocalizedUrls(int $productId): array
    {
        $urls = [];

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $locale = $this->config->getLocaleCode($storeId);

            if ($locale === null) {
                continue;
            }

            $urls[$locale] = $this->buildTrackedUrl($productId, $storeId);
        }

        return $urls;
    }

    private function buildTrackedUrl(int $productId, int $storeId): string
    {
        $storeScopedProduct = $this->productRepository->getById($productId, false, $storeId);

        return $this->utmUrlBuilder->withTracking($storeScopedProduct->getProductUrl());
    }
}
