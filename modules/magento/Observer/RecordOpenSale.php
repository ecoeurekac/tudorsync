<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Tudorsync\EcommerceSync\Model\Realtime\ChangeRecorder;
use Tudorsync\EcommerceSync\Model\Stock\OpenSales;
use Tudorsync\EcommerceSync\Model\Stock\SalableQtyProvider;

/**
 * On `sales_model_service_quote_submit_success` (storefront, REST and admin orders), in stores
 * that do not subtract stock on order: saves each TUDOR line with the salable quantity there was
 * (Model\Stock\OpenSales) and queues its model code, so it is republished with that unit less
 * within a minute. With reservations (the usual case) MSI does all this and this is a no-op.
 *
 * Runs right after the order is placed, so it never throws: a missed line is only published
 * late, a broken checkout is not acceptable.
 */
class RecordOpenSale implements ObserverInterface
{
    private const STOCK_TYPES = ['simple', 'virtual'];

    public function __construct(
        private readonly OpenSales $openSales,
        private readonly SalableQtyProvider $salableQtyProvider,
        private readonly ChangeRecorder $changeRecorder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var Order|null $order */
            $order = $observer->getEvent()->getData('order');

            if ($order === null || !$order->getId() || !$this->openSales->isActive()) {
                return;
            }

            $items = [];

            foreach ($order->getAllItems() as $item) {
                // Only the lines with stock of their own: a configurable/bundle parent repeats the
                // SKU of its simple child, which comes as a line of its own.
                if ($item->getId() && $item->getProductId() && in_array($item->getProductType(), self::STOCK_TYPES, true)) {
                    $items[] = $item;
                }
            }

            $modelCodes = $this->changeRecorder->getModelCodesBySku(
                array_map(static fn ($item): string => (string) $item->getSku(), $items)
            );

            if ($modelCodes === []) {
                return;
            }

            $websiteCode = (string) $this->storeManager->getStore($order->getStoreId())->getWebsite()->getCode();
            $lines = [];

            foreach ($items as $item) {
                $sku = (string) $item->getSku();

                if (isset($modelCodes[$sku])) {
                    $lines[(int) $item->getId()] = [
                        'order_id' => (int) $order->getId(),
                        'sku' => $sku,
                        'qty_at_order' => (float) $this->salableQtyProvider->getStockSalableQty((int) $item->getProductId(), $sku, $websiteCode),
                    ];
                }
            }

            $this->openSales->record($lines);
            $this->changeRecorder->recordSkus(array_keys($modelCodes), 'order, stock not subtracted');
            $this->logger->info(sprintf(
                'Tudorsync: order %s, open sale of %s until the stock shows it',
                $order->getIncrementId(),
                implode(', ', array_keys($modelCodes))
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Tudorsync: could not record the open sale of an order: ' . $e->getMessage());
        }
    }
}
