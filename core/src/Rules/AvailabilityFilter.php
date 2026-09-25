<?php

declare(strict_types=1);

namespace Tudorsync\Core\Rules;

use Tudorsync\Core\Domain\StockAvailability;

/**
 * Re-applies TUDOR's non-negotiable visibility rules regardless of what a connector sends,
 * so a bug or oversight in one platform module can't leak an "on demand" or out-of-stock
 * model into what gets pushed to TUDOR's API.
 *
 * See doc/Primeros pasos del programa de comercio electrónico de TUDOR...pdf, "Reglas de
 * visualización". The sync engine should always submit the *complete* current set of
 * sellable-now records on every run: per the batch endpoint's own documented behavior
 * ("sets missing stocks to 0"), any model/country combination previously published but
 * omitted from a batch gets zeroed out by TUDOR automatically — that's how a model
 * disappears from "Comprar ahora" the moment it stops being immediately purchasable.
 */
final class AvailabilityFilter
{
    /**
     * @param StockAvailability[] $items
     * @return StockAvailability[] Only items actually purchasable online right now.
     *                             Everything else is dropped from the batch entirely,
     *                             letting TUDOR's own "missing stocks set to 0" behavior
     *                             hide it — never sent as an explicit zero/false record.
     */
    public function keepOnlyAvailable(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (StockAvailability $item): bool => $item->onlinePurchaseEnabled && $item->value > 0,
        ));
    }
}
