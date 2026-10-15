<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Cli;

use DateTimeImmutable;
use Tudorsync\Woocommerce\ProgrammeSalesSource;
use WP_CLI;

/**
 * `wp tudorsync report programme-sales` — the order side of TUDOR's monthly report: how many
 * TUDOR watches were sold online through the programme in a month (ProgrammeSalesSource).
 * Read-only; it never calls TUDOR.
 */
final class ReportCommand
{
    /**
     * ## OPTIONS
     *
     * [--month=<YYYY-MM>]
     * : The month to report. Default: the current month.
     *
     * @subcommand programme-sales
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function programmeSales(array $args, array $assocArgs): void
    {
        $timezone = wp_timezone();
        $month = isset($assocArgs['month']) ? (string) $assocArgs['month'] : wp_date('Y-m');

        $from = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00', $timezone);

        if ($from === false) {
            WP_CLI::error('Invalid --month, expected YYYY-MM.');
        }

        $to = $from->modify('first day of next month');

        $sales = (new ProgrammeSalesSource())->getProgrammeSales($from, $to);

        WP_CLI::log(sprintf('Period: %s to %s (%s)', $from->format('Y-m-d'), $to->format('Y-m-d'), $timezone->getName()));
        WP_CLI::success(sprintf(
            'TUDOR watches sold online through the programme: %d (click & collect: %s).',
            $sales->watchesSoldOnline,
            $sales->clickAndCollectSales === null ? 'n/a' : (string) $sales->clickAndCollectSales,
        ));
    }
}
