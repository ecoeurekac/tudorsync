<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Tudorsync\Core\Contract\CatalogConnectorInterface;

/**
 * Hands SyncEngine a catalog that has already been read, so SyncRunner can read it once and
 * still report how many models went out without querying the catalog a second time.
 */
class SnapshotConnector implements CatalogConnectorInterface
{
    public function __construct(
        private readonly CatalogSnapshot $snapshot,
    ) {
    }

    public function getAvailableCatalog(): array
    {
        return $this->snapshot->items;
    }
}
