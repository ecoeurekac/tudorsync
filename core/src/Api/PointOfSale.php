<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

/**
 * One of this retailer's active, non-virtual TUDOR points of sale, as returned by
 * `GET /v1/point-of-sales` (API schema: `PointOfSaleDto`).
 *
 * NOTE: `stoId` here is a different identifier scheme than the "RSWI_..." keys used in
 * StockAvailability::$storesAvailabilityDetails on the write side — whether the two are the
 * same value under different names, or genuinely different codes, is not confirmed by the
 * technical docs shared so far. Don't assume they're interchangeable without checking with
 * TUDOR.
 */
final class PointOfSale
{
    public function __construct(
        public readonly string $stoId,
        public readonly string $name,
        public readonly string $street,
        public readonly string $streetNumber,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $countryCode,
        public readonly ?string $region = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            stoId: $data['stoId'],
            name: $data['name'],
            street: $data['street'],
            streetNumber: $data['streetNumber'],
            city: $data['city'],
            postalCode: $data['postalCode'],
            countryCode: $data['countryCode'],
            region: $data['region'] ?? null,
        );
    }
}
