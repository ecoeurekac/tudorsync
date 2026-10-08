<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Tudorsync\Core\Domain\StockAvailability;

/**
 * Result of one catalog read: what goes to TUDOR plus, for every candidate product, why it
 * was sent, merged or left out. The diagnostics feed `bin/magento tudorsync:catalog:preview`.
 *
 * $items is what the module builds, and what SyncEngine receives; $review is what core's
 * AvailabilityFilter keeps of it ($review->items is what actually goes out). A product whose
 * model the review drops is marked excluded here, with core's reason.
 */
class CatalogSnapshot
{
    public const STATUS_SENT = 'sent';
    public const STATUS_MERGED = 'merged';
    public const STATUS_EXCLUDED = 'excluded';

    /**
     * @param StockAvailability[] $items
     * @param array<int, array<string, mixed>> $products keyed by product id
     * @param string[] $warnings
     */
    public function __construct(
        public readonly array $items,
        public readonly array $products,
        public readonly array $warnings = [],
        public readonly ?CatalogReview $review = null,
    ) {
    }

    /**
     * The records that would really be sent: the ones that pass core's review.
     *
     * @return StockAvailability[]
     */
    public function getItemsToSend(): array
    {
        return $this->review?->items ?? $this->items;
    }

    /**
     * @return array<string, int> status => number of products
     */
    public function countByStatus(): array
    {
        $counts = [self::STATUS_SENT => 0, self::STATUS_MERGED => 0, self::STATUS_EXCLUDED => 0];

        foreach ($this->products as $product) {
            $counts[$product['status']]++;
        }

        return $counts;
    }
}
