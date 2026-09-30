<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Tudorsync\Core\Domain\StockAvailability;

/**
 * Result of one catalog read: what goes to TUDOR plus, for every candidate product, why it
 * was sent, merged or left out. The diagnostics feed `bin/magento tudorsync:catalog:preview`.
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
    ) {
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
