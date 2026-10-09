<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Catalog;

use Tudorsync\Core\Contract\CatalogConnectorInterface;

/**
 * Hands an already-read snapshot to core's SyncEngine, so the catalog is read once per run and
 * what is logged and shown is exactly what is sent.
 */
final class SnapshotConnector implements CatalogConnectorInterface
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
