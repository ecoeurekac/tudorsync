<?php

declare(strict_types=1);

namespace Tudorsync\Core\Sync;

use Tudorsync\Core\Api\BatchSyncResult;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Contract\CatalogConnectorInterface;
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
        $catalog = $this->connector->getAvailableCatalog();
        $availableOnly = $this->filter->keepOnlyAvailable($catalog);

        return $this->tudorApiClient->batchUpsertStocks($availableOnly);
    }
}
