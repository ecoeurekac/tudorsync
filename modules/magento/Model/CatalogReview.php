<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\Exclusion;
use Tudorsync\Core\Rules\ValidModelList;

/**
 * What tudorsync/core's AvailabilityFilter does with a list of records before they are sent:
 * the records that pass, each watch it drops with the reason, and its warnings. The sync
 * itself still goes through SyncEngine (which runs the filter again); this is only so the
 * preview, the admin pages, the API tests and the log show the same result.
 */
class CatalogReview
{
    /**
     * @param StockAvailability[] $items records that pass the review (what would be sent)
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

    /**
     * Records were received but none passed: SyncEngine sends nothing (NothingPassedReviewException).
     */
    public function nothingPassed(): bool
    {
        return $this->received > 0 && $this->items === [];
    }

    /**
     * Why this model was dropped, or null when it passes (or wasn't reviewed).
     */
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
     * One line per dropped watch ("M79030N-0002 (ES): No está en la lista…"), for the log and the admin.
     *
     * @return list<string>
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
     * @return array{received: int, passed: int, exclusions: list<array<string, string>>, warnings: list<string>}
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
