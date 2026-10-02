<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Plugin;

use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Tudorsync\EcommerceSync\Model\Realtime\ChangeRecorder;

/**
 * MSI writes a reservation every time the salable quantity moves because of an order: order
 * placed, cancelled, shipped (compensation), refunded with "return to stock". One hook covers
 * all of them, whatever the checkout (One Step Checkout, REST, PayPal/Redsys returns, admin).
 */
class QueueOnReservations
{
    public function __construct(
        private readonly ChangeRecorder $changeRecorder,
    ) {
    }

    /**
     * @param ReservationInterface[] $reservations
     */
    public function afterExecute(AppendReservationsInterface $subject, mixed $result, array $reservations): mixed
    {
        $this->changeRecorder->recordSkus(
            array_map(static fn (ReservationInterface $reservation): string => (string) $reservation->getSku(), $reservations),
            'order / reservation'
        );

        return $result;
    }
}
