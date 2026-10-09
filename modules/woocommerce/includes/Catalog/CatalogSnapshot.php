<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Catalog;

use Tudorsync\Core\Domain\StockAvailability;

/**
 * One read of the catalog: the records built (one per model code), what happened to every
 * product looked at, the connector's own warnings and core's review of the records.
 */
final class CatalogSnapshot
{
    public const SENT = 'sent';
    public const MERGED = 'merged';
    public const EXCLUDED = 'excluded';

    /**
     * @param StockAvailability[] $items Records built by the connector, before core's review.
     * @param array<int, array<string, mixed>> $products Keyed by product id: product_id, sku, name,
     *        model_code, model_code_source (meta|sku), qty, salable_qty, status (sent|merged|excluded),
     *        reason, merged_count (main product of a model only).
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $items,
        public readonly array $products,
        public readonly array $warnings,
        public readonly CatalogReview $review,
    ) {
    }

    /**
     * @return StockAvailability[] What core's review lets through: what is really sent.
     */
    public function getItemsToSend(): array
    {
        return $this->review->items;
    }

    /**
     * @return array{sent: int, merged: int, excluded: int}
     */
    public function countByStatus(): array
    {
        $counts = [self::SENT => 0, self::MERGED => 0, self::EXCLUDED => 0];

        foreach ($this->products as $product) {
            $counts[$product['status']]++;
        }

        return $counts;
    }

    /**
     * Excluded products grouped by reason, most frequent first.
     *
     * @return array<string, int>
     */
    public function countExcludedByReason(): array
    {
        $counts = [];

        foreach ($this->products as $product) {
            if ($product['status'] === self::EXCLUDED) {
                $counts[$product['reason']] = ($counts[$product['reason']] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }
}
