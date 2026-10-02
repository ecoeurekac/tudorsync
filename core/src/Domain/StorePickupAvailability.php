<?php

declare(strict_types=1);

namespace Tudorsync\Core\Domain;

/**
 * Click & collect detail for one specific point of sale, keyed by its TUDOR-assigned id
 * (an "RSWI" code per the technical API, e.g. "RSWI_2517") in {@see StockAvailability}.
 * That id is the `stoId` returned by `GET /v1/point-of-sales` ({@see \Tudorsync\Core\Api\PointOfSale}).
 */
final class StorePickupAvailability
{
    public function __construct(
        public readonly bool $available,
        public readonly ?int $timingHours = null,
    ) {
    }
}
