<?php

declare(strict_types=1);

namespace Tudorsync\Core\Domain;

/**
 * Click & collect detail for one specific point of sale, keyed by its TUDOR-assigned id
 * (an "RSWI" code per the technical API, e.g. "RSWI_2517") in {@see StockAvailability}.
 *
 * NOTE: how a retailer's own store locations map to their RSWI id is not yet confirmed —
 * the API's `GET /v1/point-of-sales` returns each location's own `stoId`, which may or may
 * not be the same identifier. Confirm with TUDOR before relying on either being the RSWI key.
 */
final class StorePickupAvailability
{
    public function __construct(
        public readonly bool $available,
        public readonly ?int $timingHours = null,
    ) {
    }
}
