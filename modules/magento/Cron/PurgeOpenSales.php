<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Cron;

use Tudorsync\EcommerceSync\Model\Stock\OpenSales;

/**
 * Deletes the open sales that no longer count: cancelled, closed or expired orders
 * (Model\Stock\OpenSales::purge()). Only tidies the table; they are already ignored when counting.
 */
class PurgeOpenSales
{
    public function __construct(
        private readonly OpenSales $openSales,
    ) {
    }

    public function execute(): void
    {
        if ($this->openSales->isActive()) {
            $this->openSales->purge();
        }
    }
}
