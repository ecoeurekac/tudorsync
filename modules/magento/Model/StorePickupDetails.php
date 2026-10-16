<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\StorePickupAvailability;
use Tudorsync\EcommerceSync\Model\Api\LoggingHttpClient;

/**
 * Per-point-of-sale click & collect detail (storesAvailabilityDetails, keyed by RSWI id) for
 * every record sent to TUDOR. TUDOR's API has no store-wide pickup timing: it only takes it
 * per point of sale, and a point of sale sent there overrides the store-wide
 * storePickupAvailable. One left out falls back to the store-wide value, so it is never left
 * out: every point of sale of the retailer (GET /v1/point-of-sales, cached a day) is sent.
 *
 * - Click & collect off: nothing (only storePickupAvailable = false goes out).
 * - Points of sale listed in the admin: those with their own Yes/No and timing (empty timing =
 *   the default timing); every other point of sale of the retailer goes out as not available.
 * - None listed but a default timing set: every point of sale of the retailer, read from
 *   GET /v1/point-of-sales (cached a day), with that timing.
 * - Neither: nothing, as before.
 *
 * Every model gets the same detail: the stock is the store's, not each shop's.
 */
class StorePickupDetails
{
    private const CACHE_KEY = 'tudorsync_point_of_sale_ids_%s';
    private const CACHE_LIFETIME = 86400;

    /** @var array<int, array<string, StorePickupAvailability>> */
    private array $built = [];

    public function __construct(
        private readonly Config $config,
        private readonly LoggingHttpClient $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, StorePickupAvailability>
     */
    public function get(int $storeId): array
    {
        return $this->built[$storeId] ??= $this->build($storeId);
    }

    /**
     * @return array<string, StorePickupAvailability>
     */
    private function build(int $storeId): array
    {
        if (!$this->config->getClientConfig($storeId)->offersClickAndCollect) {
            return [];
        }

        $defaultTiming = $this->config->getStorePickupTimingHours($storeId);
        $pointsOfSale = $this->config->getPointsOfSale($storeId);

        if ($pointsOfSale !== []) {
            $details = array_map(
                static fn (array $row): StorePickupAvailability => new StorePickupAvailability(
                    $row['available'],
                    $row['available'] ? ($row['timing'] ?? $defaultTiming) : null,
                ),
                $pointsOfSale,
            );

            foreach ($this->getPointOfSaleIds($storeId) as $stoId) {
                $details[$stoId] ??= new StorePickupAvailability(false);
            }

            return $details;
        }

        if ($defaultTiming === null) {
            return [];
        }

        $details = [];

        foreach ($this->getPointOfSaleIds($storeId) as $stoId) {
            $details[$stoId] = new StorePickupAvailability(true, $defaultTiming);
        }

        return $details;
    }

    /**
     * @return list<string>
     */
    private function getPointOfSaleIds(int $storeId): array
    {
        $clientConfig = $this->config->getClientConfig($storeId);
        $cacheKey = sprintf(self::CACHE_KEY, sha1($clientConfig->environment->value . '|' . $clientConfig->clientId));
        $cached = $this->cache->load($cacheKey);

        if (is_string($cached) && is_array($ids = json_decode($cached, true))) {
            return $ids;
        }

        try {
            $ids = array_map(
                static fn ($pointOfSale): string => $pointOfSale->stoId,
                (new TudorApiClient($clientConfig, $this->httpClient))->getPointOfSales(),
            );
        } catch (Throwable $e) {
            // Without the ids only the listed points of sale go out (none without rows); the rest keep the store-wide flag.
            $this->logger->warning('Tudorsync: click & collect: GET /v1/point-of-sales failed, points of sale not listed in the admin are not sent: ' . $e->getMessage());

            return [];
        }

        $this->cache->save((string) json_encode($ids), $cacheKey, [], self::CACHE_LIFETIME);

        return $ids;
    }
}
