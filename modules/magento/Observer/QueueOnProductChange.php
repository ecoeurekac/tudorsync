<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Observer;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Tudorsync\EcommerceSync\Model\ModelCodeResolver;
use Tudorsync\EcommerceSync\Model\Realtime\ChangeRecorder;

/**
 * Product saved or deleted (enabled/disabled, visibility, URL, model code…) and, for stores
 * without MSI, legacy stock item saved. Queues the TUDOR model code, before and after the
 * change, for Cron\PublishPending.
 */
class QueueOnProductChange implements ObserverInterface
{
    public function __construct(
        private readonly ChangeRecorder $changeRecorder,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();

        $stockItem = $event->getData('item');
        if ($stockItem instanceof StockItem) {
            $this->changeRecorder->recordProductIds([(int) $stockItem->getProductId()], 'stock saved');

            return;
        }

        $product = $event->getData('product');
        if (!$product instanceof Product) {
            return;
        }

        $attribute = ModelCodeResolver::ATTRIBUTE_CODE;
        $this->changeRecorder->recordProductData(
            [
                [(string) $product->getData($attribute), (string) $product->getSku()],
                [(string) $product->getOrigData($attribute), (string) $product->getOrigData('sku')],
            ],
            $event->getName() === 'catalog_product_delete_after' ? 'product deleted' : 'product saved'
        );
    }
}
