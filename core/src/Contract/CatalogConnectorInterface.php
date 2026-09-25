<?php

declare(strict_types=1);

namespace Tudorsync\Core\Contract;

use Tudorsync\Core\Domain\StockAvailability;

/**
 * The one contract every platform module must implement. It is the sole seam between a
 * store's own catalog/stock model and the TUDOR-facing sync logic in tudorsync/core.
 *
 * Implementations live in the platform modules (Magento, PrestaShop, WooCommerce), never
 * in core — core must stay usable without knowing any platform exists.
 */
interface CatalogConnectorInterface
{
    /**
     * @return StockAvailability[] Every TUDOR-mapped product currently sellable immediately
     *                             online on this store. Connectors should already exclude
     *                             "on demand" / backorder-only items (never return them at
     *                             all, rather than returning them with onlinePurchaseEnabled
     *                             false) — AvailabilityFilter in core re-checks but cannot
     *                             know a given platform's own backorder semantics.
     */
    public function getAvailableCatalog(): array;
}
