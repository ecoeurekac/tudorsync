<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Catalog;

use Throwable;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\StorePickupAvailability;
use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\Logger;

/**
 * Per-point-of-sale click & collect detail (storesAvailabilityDetails, keyed by RSWI id) for
 * every record sent to TUDOR. TUDOR's API has no store-wide pickup timing: it only takes it
 * per point of sale, and a point of sale sent there overrides the store-wide
 * storePickupAvailable. One left out falls back to the store-wide value, so the ones that do
 * NOT offer pickup must be sent explicitly as not available.
 *
 * - Click & collect off: nothing (only storePickupAvailable = false goes out).
 * - Points of sale listed on the settings page: those with their own Yes/No and timing (empty
 *   timing = the default timing); every other point of sale of the retailer goes out as not
 *   available.
 * - None listed but a default timing set: every point of sale of the retailer, read from
 *   GET /v1/point-of-sales (cached a day), with that timing.
 * - Neither: nothing, as before.
 *
 * Every model gets the same detail: the stock is the store's, not each shop's.
 */
final class StorePickupDetails
{
    private const TRANSIENT = 'tudorsync_pos_ids_%s';
    private const CACHE_LIFETIME = DAY_IN_SECONDS;

    /** @var array<string, StorePickupAvailability>|null */
    private ?array $built = null;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<string, StorePickupAvailability>
     */
    public function get(): array
    {
        return $this->built ??= $this->build();
    }

    /**
     * @return array<string, StorePickupAvailability>
     */
    private function build(): array
    {
        if (!$this->config->offersClickAndCollect()) {
            return [];
        }

        $defaultTiming = $this->config->getStorePickupTimingHours();
        $pointsOfSale = $this->config->getPointsOfSale();

        if ($pointsOfSale !== []) {
            $details = [];

            foreach ($pointsOfSale as $stoId => $row) {
                $details[$stoId] = new StorePickupAvailability(
                    $row['available'],
                    $row['available'] ? ($row['timing'] ?? $defaultTiming) : null,
                );
            }

            // Every other point of sale of the retailer goes out as not available, so TUDOR
            // doesn't fall back to the store-wide flag for the shops that don't offer pickup.
            foreach ($this->pointOfSaleIds() as $stoId) {
                $details[$stoId] ??= new StorePickupAvailability(false);
            }

            return $details;
        }

        if ($defaultTiming === null) {
            return [];
        }

        $details = [];

        foreach ($this->pointOfSaleIds() as $stoId) {
            $details[$stoId] = new StorePickupAvailability(true, $defaultTiming);
        }

        return $details;
    }

    /**
     * This retailer's point-of-sale ids (RSWI), from GET /v1/point-of-sales, cached a day per
     * environment + client id. On failure only the listed points of sale go out (the rest keep
     * TUDOR's store-wide fallback); it never breaks the sync.
     *
     * @return list<string>
     */
    private function pointOfSaleIds(): array
    {
        $clientConfig = $this->config->getClientConfig();
        $key = sprintf(self::TRANSIENT, sha1($clientConfig->environment->value . '|' . $clientConfig->clientId));
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $ids = array_values(array_map(
                static fn ($pointOfSale): string => $pointOfSale->stoId,
                (new TudorApiClient($clientConfig, $this->httpClient))->getPointOfSales(),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Tudorsync: click & collect: GET /v1/point-of-sales failed, points of sale not listed are not sent: ' . $e->getMessage());

            return [];
        }

        set_transient($key, $ids, self::CACHE_LIFETIME);

        return $ids;
    }
}
