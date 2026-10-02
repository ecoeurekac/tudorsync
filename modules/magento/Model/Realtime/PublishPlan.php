<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Realtime;

use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\EcommerceSync\Model\CatalogSnapshot;

/**
 * What one run of the real-time publisher will do with the queued model codes.
 *
 * - Every queued model is still available → one POST /v1/stocks per model ($items).
 * - Some model is no longer available (sold out, disabled, deleted, no longer TUDOR), or there
 *   are too many → one full POST /v1/stocks/batch instead ($fullBatch): TUDOR sets whatever is
 *   missing from a batch to 0, which is the documented way of withdrawing a model.
 */
class PublishPlan
{
    /**
     * @param list<array{model_code: string, reason: ?string, queued_at: string}> $pending
     * @param list<StockAvailability> $items
     * @param list<string> $unavailable
     */
    public function __construct(
        public readonly array $pending,
        public readonly string $queuedBefore,
        public readonly CatalogSnapshot $snapshot,
        public readonly array $items,
        public readonly array $unavailable,
        public readonly bool $fullBatch,
        public readonly string $why,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getModelCodes(): array
    {
        return array_column($this->pending, 'model_code');
    }
}
