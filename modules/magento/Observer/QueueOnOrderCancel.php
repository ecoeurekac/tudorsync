<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Tudorsync\EcommerceSync\Model\Realtime\ChangeRecorder;
use Tudorsync\EcommerceSync\Model\Stock\OpenSales;

/**
 * On `order_cancel_after`: the open sales of a cancelled order stop counting
 * (Model\Stock\OpenSales), so their models are republished within a minute with that unit back.
 * Stores with reservations don't need it: the cancellation reservation queues them already.
 */
class QueueOnOrderCancel implements ObserverInterface
{
    public function __construct(
        private readonly OpenSales $openSales,
        private readonly ChangeRecorder $changeRecorder,
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

            $this->changeRecorder->recordSkus($this->openSales->getSkusOfOrders([(int) $order->getId()]), 'order cancelled');
        } catch (\Throwable $e) {
            $this->logger->error('Tudorsync: could not queue a cancelled order: ' . $e->getMessage());
        }
    }
}
