<?php

declare(strict_types=1);

namespace Tudorsync\Core\Domain;

/**
 * One TUDOR watch model's stock/availability record as it will be reported to TUDOR's
 * e-Stock Retail Publish API. This is the normalized shape every platform connector must
 * produce — field names deliberately mirror the API's `StockCreateDto` (see
 * doc/stock-retail-publish-public-rest-api-1.7.0-*.zip) so the mapping in TudorApiClient
 * stays a straight pass-through.
 *
 * OPEN QUESTION carried over from the technical API docs: `value` is a plain integer in
 * every example (42, 76, 0, 3, ...) with no description of its semantics, which reads like
 * a real stock quantity — in apparent tension with TUDOR's own onboarding promise that
 * "no hay cantidades de existencias visibles" (no stock quantities are ever shown to
 * shoppers). Most likely reading: `value` is what the retailer reports to TUDOR's backend,
 * while `onlinePurchaseEnabled` is the actual switch that controls whether tudorwatch.com's
 * "Comprar ahora" button appears — TUDOR's frontend just never displays `value` itself.
 * Until confirmed otherwise, treat `value` as "some positive signal of in-stock quantity"
 * and `onlinePurchaseEnabled` as the true availability gate (see AvailabilityFilter).
 */
final class StockAvailability
{
    /**
     * @param string $modelCode TUDOR's model code for this watch (API field: `mc`).
     * @param string $country ISO country code for the market this record applies to
     *                        (API field: `country`) — normally this store's own market.
     * @param int $value Stock signal sent to TUDOR; see the class docblock for the open
     *                   question on what this number actually represents.
     * @param string $defaultUrl Fallback product page URL, UTM tracking already applied.
     * @param array<string, string> $localizedUrls Product URL per TUDOR locale code — note
     *                                              these can carry a region, e.g. 'fr-CH',
     *                                              'fr-FR', not just a bare language code.
     * @param bool $onlinePurchaseEnabled Whether this model can be bought online right now
     *                                     on this store — see the class docblock.
     * @param bool $storePickupAvailable Whether click & collect is offered at all for this
     *                                    model on this store.
     * @param int|null $homeDeliveryTimingHours Optional estimated home delivery time.
     * @param array<string, StorePickupAvailability> $storesAvailabilityDetails Per-point-of-sale
     *                                                click & collect detail, keyed by TUDOR's
     *                                                RSWI id for that point of sale.
     */
    public function __construct(
        public readonly string $modelCode,
        public readonly string $country,
        public readonly int $value,
        public readonly string $defaultUrl,
        public readonly array $localizedUrls,
        public readonly bool $onlinePurchaseEnabled,
        public readonly bool $storePickupAvailable,
        public readonly ?int $homeDeliveryTimingHours = null,
        public readonly array $storesAvailabilityDetails = [],
    ) {
    }
}
