<?php

declare(strict_types=1);

namespace Tudorsync\Core\Domain;

use InvalidArgumentException;

/**
 * Sales through the programme for one period, as returned by ProgrammeSalesSourceInterface
 * (see its docblock for the counting rules). They fill the monthly report's "Total online
 * sales" (column F, mandatory) and "of which Click & Collect" (column G) columns.
 */
final class ProgrammeSales
{
    /**
     * @param int $watchesSoldOnline Units of TUDOR watches sold online in the period
     *                               (cancelled orders left out, returns not subtracted).
     * @param int|null $clickAndCollectSales The part of $watchesSoldOnline paid online and
     *                                       collected in store; null when the store doesn't
     *                                       tell them apart or doesn't offer it (today, always
     *                                       null: not offered within the programme).
     */
    public function __construct(
        public readonly int $watchesSoldOnline,
        public readonly ?int $clickAndCollectSales = null,
    ) {
        if ($watchesSoldOnline < 0) {
            throw new InvalidArgumentException(sprintf(
                'watchesSoldOnline cannot be negative, got %d',
                $watchesSoldOnline
            ));
        }

        if ($clickAndCollectSales !== null && $clickAndCollectSales < 0) {
            throw new InvalidArgumentException(sprintf(
                'clickAndCollectSales cannot be negative, got %d',
                $clickAndCollectSales
            ));
        }

        if ($clickAndCollectSales !== null && $clickAndCollectSales > $watchesSoldOnline) {
            throw new InvalidArgumentException(sprintf(
                'clickAndCollectSales (%d) cannot exceed watchesSoldOnline (%d): click & collect is part of the online sales',
                $clickAndCollectSales,
                $watchesSoldOnline
            ));
        }
    }
}
