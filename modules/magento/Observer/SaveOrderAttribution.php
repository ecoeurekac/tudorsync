<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Tudorsync\EcommerceSync\Model\Attribution\UtmCookie;

/**
 * On `sales_model_service_quote_submit_success` (every storefront checkout, including the
 * One Step Checkout REST call and the PayPal/Redsys returns), stores the TUDOR referral of
 * the customer placing the order, if there is one. Registered only for the frontend and
 * webapi_rest areas: an order created in the admin must not pick up the admin's own cookie.
 *
 * Runs inside the order transaction, so it never throws: losing an attribution row is
 * acceptable, breaking a checkout is not.
 */
class SaveOrderAttribution implements ObserverInterface
{
    private const TABLE = 'tudorsync_order_attribution';

    public function __construct(
        private readonly UtmCookie $utmCookie,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $utm = $this->utmCookie->read();
            /** @var Order|null $order */
            $order = $observer->getEvent()->getData('order');
            if ($utm === null || $order === null || !$order->getId()) {
                return;
            }

            $connection = $this->resource->getConnection('sales');
            $connection->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE, 'sales'),
                [
                    'order_id' => (int) $order->getId(),
                    'increment_id' => (string) $order->getIncrementId(),
                    'store_id' => (int) $order->getStoreId(),
                ] + $utm,
                ['utm_medium', 'utm_campaign', 'landed_at']
            );
            $this->logger->info(sprintf('TUDOR referral saved for order %s', $order->getIncrementId()));
        } catch (\Throwable $e) {
            $this->logger->error('Could not save the TUDOR referral of an order: ' . $e->getMessage());
        }
    }
}
