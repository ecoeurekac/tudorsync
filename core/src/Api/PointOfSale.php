<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

/**
 * One of this retailer's active, non-virtual TUDOR points of sale, as returned by
 * `GET /v1/point-of-sales` (API schema: `PointOfSaleDto`).
 *
 * `stoId` is the point of sale's RSWI id ("stoId represents the identifier, often named
 * RSWI", TUDOR's API Spotlight docs), e.g. "RSWI_185580" — the same key
 * StockAvailability::$storesAvailabilityDetails uses on the write side.
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
