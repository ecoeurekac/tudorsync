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
 *
 * On top of that, a model whose code isn't in TUDOR's list of current models for its country
 * (ValidModelList) is dropped the same way. If that list can't be used, or would drop every
 * available watch of a country, it is skipped for that country — the catalog goes out
 * unfiltered — and getWarnings() says so. getExcludedModelCodes() lists what the list
 * dropped. Both describe the last keepOnlyAvailable() call, for the modules to log/show.
 */
final class AvailabilityFilter
{
    private readonly ValidModelList $validModels;

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $excludedModelCodes = [];

    public function __construct(?ValidModelList $validModels = null)
    {
        $this->validModels = $validModels ?? new ValidModelList();
    }

    /**
     * @param StockAvailability[] $items
     * @return StockAvailability[] Only items actually purchasable online right now.
     *                             Everything else is dropped from the batch entirely,
     *                             letting TUDOR's own "missing stocks set to 0" behavior
     *                             hide it — never sent as an explicit zero/false record.
     */
    public function keepOnlyAvailable(array $items): array
    {
        $this->warnings = [];
        $this->excludedModelCodes = [];

        $available = array_values(array_filter(
            $items,
            static fn (StockAvailability $item): bool => $item->onlinePurchaseEnabled && $item->value > 0,
        ));

        return $this->keepOnlyValidModels($available);
    }

    /**
     * Warnings from the last keepOnlyAvailable() call, e.g. "Filtro de modelos vigentes
     * DESACTIVADO: no se encuentra prices_ES.xlsx". Empty when the list was applied normally.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Model codes (as the store sent them, sorted) dropped by the last keepOnlyAvailable() call
     * because they aren't in TUDOR's list of current models; available otherwise.
     *
     * @return list<string>
     */
    public function getExcludedModelCodes(): array
    {
        return $this->excludedModelCodes;
    }

    /**
     * @param list<StockAvailability> $available
     * @return list<StockAvailability>
     */
    private function keepOnlyValidModels(array $available): array
    {
        $byCountry = [];
        foreach ($available as $item) {
            $byCountry[strtoupper(trim($item->country))][] = $item;
        }

        $dropped = [];
        foreach ($byCountry as $country => $countryItems) {
            if (!$this->validModels->isLoaded($country)) {
                $this->warnings[] = 'Filtro de modelos vigentes DESACTIVADO: ' . $this->validModels->getProblem($country);
                continue;
            }

            $outside = array_filter(
                $countryItems,
                fn (StockAvailability $item): bool => !$this->validModels->contains($country, $item->modelCode),
            );

            if (count($outside) === count($countryItems)) {
                // Never send an empty catalog because of the list: more likely a wrong list than a dead catalog.
                $this->warnings[] = sprintf(
                    'Filtro de modelos vigentes DESACTIVADO: ningún reloj disponible de %s está en %s',
                    $country,
                    $this->validModels->fileName($country),
                );
                continue;
            }

            foreach ($outside as $item) {
                $dropped[spl_object_id($item)] = true;
                $this->excludedModelCodes[] = $item->modelCode;
            }
        }

        $this->excludedModelCodes = array_values(array_unique($this->excludedModelCodes));
        sort($this->excludedModelCodes);

        return array_values(array_filter(
            $available,
            static fn (StockAvailability $item): bool => !isset($dropped[spl_object_id($item)]),
        ));
    }
}
