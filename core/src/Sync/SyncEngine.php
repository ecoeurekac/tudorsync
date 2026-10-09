<?php

declare(strict_types=1);

namespace Tudorsync\Core\Sync;

use Tudorsync\Core\Api\BatchSyncResult;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Contract\CatalogConnectorInterface;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;

/**
 * Orchestrates one full sync run for a single store: read the platform's catalog through
 * its connector, enforce TUDOR's visibility rules, and batch-push the result to TUDOR's API.
 *
 * Each platform module's own scheduler (Magento cron, PrestaShop task scheduler, WP-Cron)
 * decides *when* this runs — this class only knows how to run it once, given a connector.
 * Always run the full current catalog, never a delta: TUDOR's batch endpoint auto-hides
 * anything that was published before but is missing from the latest call (see
 * AvailabilityFilter's docblock).
 *
 * That same behavior makes an empty batch dangerous: it would zero the whole catalog. So if
 * the connector returned watches and none passed the filter, nothing is sent and
 * NothingPassedReviewException is thrown. A connector that really returns nothing (everything
 * sold out) still sends the empty batch, as before.
 *
 * The review is AvailabilityFilter::keepOnlyAvailableForBatch(): TUDOR's list of current models
 * is not applied to a country where it would drop every watch (see AvailabilityFilter). plan()
 * runs exactly what run() does up to the API call and returns what would be sent, without any
 * call to TUDOR, so a module's preview and the real batch can't differ. The filter passed in
 * keeps the warnings, exclusions and countries sent unfiltered of the last plan()/run().
 */
final class SyncEngine
{
    public function __construct(
        private readonly CatalogConnectorInterface $connector,
        private readonly AvailabilityFilter $filter,
        private readonly TudorApiClient $tudorApiClient,
    ) {
    }

    public function run(): BatchSyncResult
    {
        return $this->tudorApiClient->batchUpsertStocks($this->plan());
    }

    /**
     * What run() would send right now, without calling TUDOR (not even for a token).
     *
     * @return list<StockAvailability>
     * @throws NothingPassedReviewException The connector returned watches and none passed the review.
     */
    public function plan(): array
    {
        $catalog = $this->connector->getAvailableCatalog();
        $toSend = $this->filter->keepOnlyAvailableForBatch($catalog);

        if ($catalog !== [] && $toSend === []) {
            throw new NothingPassedReviewException(count($catalog), $this->filter->getExclusions());
        }

        return array_values($toSend);
    }
}
