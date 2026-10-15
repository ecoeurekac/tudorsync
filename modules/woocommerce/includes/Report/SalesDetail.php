<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Report;

/**
 * TUDOR watches sold online in a period, line by line: what ProgrammeSalesSource counted, for the
 * admin and WP-CLI. TUDOR's monthly report gets the totals through core's ProgrammeSales.
 */
final class SalesDetail
{
    /**
     * @param int|null $clickAndCollectSales null when the store has no pickup shipping method configured
     * @param list<array{order_number: string, created_at: string, sku: string, model_code: string, units: int, click_and_collect: bool, attributed: bool}> $lines
     */
    public function __construct(
        public readonly int $watchesSoldOnline,
        public readonly ?int $clickAndCollectSales,
        public readonly array $lines,
    ) {
    }
}
