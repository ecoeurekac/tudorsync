<?php

declare(strict_types=1);

namespace Tudorsync\Core\Contract;

use Tudorsync\Core\Domain\ProgrammeSales;

/**
 * The order side of TUDOR's monthly report ("TUDOR watches sold online through the programme",
 * and of them click & collect). Every platform module (Magento, PrestaShop, WooCommerce)
 * implements it, so the three count sales the same way; core turns the result into a
 * MonthlySalesReportRow.
 *
 * Counting rules agreed by the team (all implementations must follow them):
 *   - Count units of TUDOR watches (order lines whose product has a TUDOR model code), not
 *     orders.
 *   - Cancelled orders don't count. Returns are not subtracted.
 *   - The caller decides the period's limits; the report uses the time zone of each retailer's
 *     GA4 property, so that the figures match what is seen in GA4.
 *   - Sales attributed to the programme: those of customers arriving from tudorwatch.com
 *     (utm_source=tudorwatch.com). How they are attributed (GA4 or the module's own
 *     attribution) is still to be decided and may differ per store: this interface doesn't fix
 *     it.
 *   - If a module uses its own attribution (cookie), it only stores it with the visitor's
 *     consent for analytics cookies.
 *   - Click & collect is also an online purchase (paid online, collected in store). It isn't
 *     offered within the programme today: modules return null.
 */
interface ProgrammeSalesSourceInterface
{
    /**
     * @param \DateTimeImmutable $from Start of the period, inclusive.
     * @param \DateTimeImmutable $to End of the period, exclusive: the period is [$from, $to).
     */
    public function getProgrammeSales(\DateTimeImmutable $from, \DateTimeImmutable $to): ProgrammeSales;
}
