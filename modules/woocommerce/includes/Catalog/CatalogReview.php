<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Catalog;

use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\Exclusion;
use Tudorsync\Core\Rules\ValidModelList;

/**
 * Core's review of the records the connector built (AvailabilityFilter::keepOnlyAvailable()):
 * what passes, what is left out and why, and the warnings. Computed once per catalog read so
 * the preview, the admin, the log and the real send all show exactly what core keeps.
 */
final class CatalogReview
{
    /**
     * @param StockAvailability[] $items What passed: what is really sent.
     * @param list<Exclusion> $exclusions
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly int $received,
        public readonly array $items,
        public readonly array $exclusions,
        public readonly array $warnings,
    ) {
    }

    /**
     * @param StockAvailability[] $records
     */
    public static function of(array $records, ?AvailabilityFilter $filter = null): self
    {
        $filter ??= new AvailabilityFilter();
        $items = $filter->keepOnlyAvailable($records);

        return new self(count($records), $items, $filter->getExclusions(), $filter->getWarnings());
    }

    public function nothingPassed(): bool
    {
        return $this->received > 0 && $this->items === [];
    }

    public function getExclusionFor(string $modelCode): ?Exclusion
    {
        $modelCode = ValidModelList::normalize($modelCode);

        foreach ($this->exclusions as $exclusion) {
            if ($exclusion->modelCode === $modelCode) {
                return $exclusion;
            }
        }

        return null;
    }

    /**
     * @return list<string> "MC (CC): message"
     */
    public function getExclusionLines(): array
    {
        return array_map(
            static fn (Exclusion $exclusion): string => sprintf(
                '%s (%s): %s',
                $exclusion->modelCode === '' ? '—' : $exclusion->modelCode,
                $exclusion->country,
                $exclusion->message,
            ),
            $this->exclusions,
        );
    }

    /**
     * @return array{received: int, passed: int, exclusions: list<array{mc: string, country: string, reason: string, message: string}>, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'received' => $this->received,
            'passed' => count($this->items),
            'exclusions' => array_map(static fn (Exclusion $exclusion): array => [
                'mc' => $exclusion->modelCode,
                'country' => $exclusion->country,
                'reason' => $exclusion->reason,
                'message' => $exclusion->message,
            ], $this->exclusions),
            'warnings' => $this->warnings,
        ];
    }
}
