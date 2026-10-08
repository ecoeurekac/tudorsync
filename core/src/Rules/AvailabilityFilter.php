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
 * keepOnlyAvailable() reviews each record in four steps:
 *  1. normalize `mc` and `country` (no spaces, upper case), as ValidModelList does;
 *  2. drop what can't be published: no `mc`, a country that isn't two letters, not available
 *     (`value <= 0` or online purchase disabled), a default URL that isn't a valid https://
 *     URL. `localizedUrls` keys are normalized (es_ES → es-ES) and an entry with a bad URL or
 *     a key that isn't BCP 47 (es, ast, es-ES, es-419, zh-Hant) is removed on its own;
 *  3. one record per mc + country — TUDOR keeps only the last line of a repeated `mc`, and a
 *     line it rejects zeroes that watch: values are added up, the other fields come from the
 *     record with the highest value (the first one on a tie), in order of first appearance;
 *  4. drop models not in TUDOR's list of current models for their country (ValidModelList).
 *     If that list can't be used, or would drop every watch of a country, it is skipped for
 *     that country — the catalog goes out unfiltered — with a warning.
 *
 * For the last call: getExclusions() gives every dropped watch with its reason,
 * getWarnings() what was fixed or skipped without dropping a watch, and
 * getExcludedModelCodes() the codes dropped by step 4 only.
 */
final class AvailabilityFilter
{
    /** BCP 47 / ISO 639-1: language (2-3 letters), optional script (4 letters), optional region (2 letters or 3 digits). */
    private const LOCALE_KEY = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|\d{3}))?$/';

    private const LOCALE_KEY_PROBLEM = 'Clave de idioma no válida (debe ser un código de idioma como es, ca o ast, '
        . 'con escritura y/o región opcionales: es-ES, fr-CH, es-419, zh-Hant)';

    private readonly ValidModelList $validModels;

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<Exclusion> */
    private array $exclusions = [];

    /** @var list<string> */
    private array $excludedModelCodes = [];

    public function __construct(?ValidModelList $validModels = null)
    {
        $this->validModels = $validModels ?? new ValidModelList();
    }

    /**
     * @param StockAvailability[] $items
     * @return StockAvailability[] Only items actually purchasable online right now, one per
     *                             model and country. Everything else is dropped from the batch
     *                             entirely, letting TUDOR's own "missing stocks set to 0"
     *                             behavior hide it — never sent as an explicit zero/false record.
     */
    public function keepOnlyAvailable(array $items): array
    {
        $this->warnings = [];
        $this->exclusions = [];
        $this->excludedModelCodes = [];

        $publishable = [];
        foreach ($items as $item) {
            $item = $this->normalize($item);
            if ($this->isPublishable($item)) {
                $publishable[] = $this->withoutBadLocalizedUrls($item);
            }
        }

        return $this->keepOnlyValidModels($this->mergeRepeated($publishable));
    }

    /**
     * Watches dropped by the last keepOnlyAvailable() call, each with its reason.
     *
     * @return list<Exclusion>
     */
    public function getExclusions(): array
    {
        return $this->exclusions;
    }

    /**
     * Warnings from the last keepOnlyAvailable() call: localized URLs removed, repeated models
     * merged, or the valid-model list skipped ("Filtro de modelos vigentes DESACTIVADO: no se
     * encuentra prices_ES.xlsx"). Empty when there was nothing to report.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Model codes (normalized, sorted) dropped by the last keepOnlyAvailable() call because
     * they aren't in TUDOR's list of current models; available otherwise.
     *
     * @return list<string>
     */
    public function getExcludedModelCodes(): array
    {
        return $this->excludedModelCodes;
    }

    private function normalize(StockAvailability $item): StockAvailability
    {
        $modelCode = ValidModelList::normalize($item->modelCode);
        $country = ValidModelList::normalize($item->country);

        if ($modelCode === $item->modelCode && $country === $item->country) {
            return $item;
        }

        return $this->copy($item, modelCode: $modelCode, country: $country);
    }

    private function isPublishable(StockAvailability $item): bool
    {
        $exclusion = match (true) {
            $item->modelCode === '' => [Exclusion::MISSING_MODEL_CODE, 'Sin código de modelo (mc)'],
            preg_match('/^[A-Z]{2}$/', $item->country) !== 1 => [
                Exclusion::INVALID_COUNTRY,
                sprintf('País no válido: "%s" (debe ser un código de dos letras, p. ej. ES)', $item->country),
            ],
            !$item->onlinePurchaseEnabled => [Exclusion::NOT_AVAILABLE, 'No disponible: compra online desactivada'],
            $item->value <= 0 => [Exclusion::NOT_AVAILABLE, sprintf('No disponible: sin stock (value = %d)', $item->value)],
            default => null,
        };

        $urlProblem = $exclusion === null ? $this->urlProblem($item->defaultUrl) : null;
        if ($urlProblem !== null) {
            $exclusion = [Exclusion::INVALID_URL, $urlProblem];
        }

        if ($exclusion === null) {
            return true;
        }

        $this->exclusions[] = new Exclusion($item->modelCode, $item->country, $exclusion[0], $exclusion[1]);

        return false;
    }

    private function urlProblem(string $url): ?string
    {
        return match (true) {
            $url === '' => 'Enlace no válido: vacío',
            filter_var($url, FILTER_VALIDATE_URL) === false => sprintf('Enlace no válido: "%s" no es una URL', $url),
            !str_starts_with(strtolower($url), 'https://') => 'Enlace no válido: debe empezar por https://',
            default => null,
        };
    }

    private function withoutBadLocalizedUrls(StockAvailability $item): StockAvailability
    {
        $kept = [];
        foreach ($item->localizedUrls as $locale => $url) {
            $key = $this->normalizeLocale((string) $locale);
            $problem = match (true) {
                preg_match(self::LOCALE_KEY, $key) !== 1 => self::LOCALE_KEY_PROBLEM,
                array_key_exists($key, $kept) => sprintf('Clave de idioma repetida (ya hay un enlace para %s)', $key),
                default => $this->urlProblem((string) $url),
            };

            if ($problem === null) {
                $kept[$key] = $url;
                continue;
            }

            $this->warnings[] = sprintf(
                '%s (%s): se quita el enlace del idioma "%s": %s',
                $item->modelCode,
                $item->country,
                $locale,
                lcfirst($problem),
            );
        }

        return $kept === $item->localizedUrls ? $item : $this->copy($item, localizedUrls: $kept);
    }

    /**
     * es_ES → es-ES: underscores to hyphens, language in lower case, script capitalized
     * (zh-Hant), region in upper case. Doesn't validate: LOCALE_KEY does.
     */
    private function normalizeLocale(string $locale): string
    {
        $parts = explode('-', str_replace('_', '-', trim($locale)));
        $parts[0] = strtolower($parts[0]);

        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $parts[$i] = preg_match('/^[A-Za-z]{4}$/', $parts[$i]) === 1
                ? ucfirst(strtolower($parts[$i]))
                : strtoupper($parts[$i]);
        }

        return implode('-', $parts);
    }

    /**
     * @param list<StockAvailability> $items
     * @return list<StockAvailability>
     */
    private function mergeRepeated(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->modelCode . '|' . $item->country][] = $item;
        }

        $merged = [];
        foreach ($groups as $group) {
            if (count($group) === 1) {
                $merged[] = $group[0];
                continue;
            }

            $main = $group[0];
            $total = 0;
            foreach ($group as $item) {
                $total += $item->value;
                if ($item->value > $main->value) {
                    $main = $item;
                }
            }

            $this->warnings[] = sprintf(
                '%s (%s) venía repetido %d veces: se envía un solo registro con value = %d (la suma)',
                $main->modelCode,
                $main->country,
                count($group),
                $total,
            );
            $merged[] = $this->copy($main, value: $total);
        }

        return $merged;
    }

    /**
     * @param list<StockAvailability> $available
     * @return list<StockAvailability>
     */
    private function keepOnlyValidModels(array $available): array
    {
        $byCountry = [];
        foreach ($available as $item) {
            $byCountry[$item->country][] = $item;
        }

        $dropped = [];
        foreach ($byCountry as $country => $countryItems) {
            $country = (string) $country;
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
                $this->exclusions[] = new Exclusion(
                    $item->modelCode,
                    $country,
                    Exclusion::NOT_IN_VALID_LIST,
                    sprintf('No está en la lista de modelos vigentes de TUDOR (%s)', $this->validModels->fileName($country)),
                );
            }
        }

        $this->excludedModelCodes = array_values(array_unique($this->excludedModelCodes));
        sort($this->excludedModelCodes);

        return array_values(array_filter(
            $available,
            static fn (StockAvailability $item): bool => !isset($dropped[spl_object_id($item)]),
        ));
    }

    /**
     * @param array<string, string>|null $localizedUrls
     */
    private function copy(
        StockAvailability $item,
        ?string $modelCode = null,
        ?string $country = null,
        ?int $value = null,
        ?array $localizedUrls = null,
    ): StockAvailability {
        return new StockAvailability(
            modelCode: $modelCode ?? $item->modelCode,
            country: $country ?? $item->country,
            value: $value ?? $item->value,
            defaultUrl: $item->defaultUrl,
            localizedUrls: $localizedUrls ?? $item->localizedUrls,
            onlinePurchaseEnabled: $item->onlinePurchaseEnabled,
            storePickupAvailable: $item->storePickupAvailable,
            homeDeliveryTimingHours: $item->homeDeliveryTimingHours,
            storesAvailabilityDetails: $item->storesAvailabilityDetails,
        );
    }
}
