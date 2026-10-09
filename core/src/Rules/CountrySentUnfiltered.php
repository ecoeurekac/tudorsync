<?php

declare(strict_types=1);

namespace Tudorsync\Core\Rules;

/**
 * A country whose batch goes out without TUDOR's list of current models, because the list would
 * have dropped every watch of it (AvailabilityFilter::keepOnlyAvailableForBatch()). The watches
 * in $wouldExclude ARE sent: they are not in getExclusions() nor in getExcludedModelCodes().
 */
final class CountrySentUnfiltered
{
    /**
     * @param string $country The country after normalization, e.g. ES.
     * @param string $message Spanish text for the admin/log: why the list wasn't applied.
     * @param list<Exclusion> $wouldExclude What the list would have dropped (reason not_in_valid_list).
     */
    public function __construct(
        public readonly string $country,
        public readonly string $message,
        public readonly array $wouldExclude,
    ) {
    }
}
