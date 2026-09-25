<?php

declare(strict_types=1);

namespace Tudorsync\Core\Reporting;

/**
 * One row of TUDOR's own "Informe de ventas mensual del programa de comercio electrónico de
 * TUDOR" — the monthly sales-tracking report every retailer sends TUDOR, per the Excel
 * templates in core/resources/report-templates/ (one file per language: DE/EN/ES/FR/IT).
 *
 * This is deliberately NOT a per-sale/per-order record: TUDOR's template asks for one row
 * per reporting period (month) with aggregate counts. Three fields are marked mandatory
 * ("*obligatorio") in the template itself — $sessions, $uniqueVisitors, $totalOnlineSales —
 * the rest are optional, matching the template's example row where boutique figures can be
 * "N/A" when a store doesn't track them.
 *
 * Sourcing note: $sessions/$uniqueVisitors/$addedToCart come from web analytics (the UTM
 * parameters added by UtmUrlBuilder are what makes TUDOR-originated traffic identifiable
 * there), $totalOnlineSales/$clickAndCollectSales come from the store's own order data, and
 * $boutiqueSales/$boutiqueAppointments are explicitly noted in the template as being based on
 * in-store staff conversations "siempre que sea posible" (whenever possible) — i.e. likely
 * manual/estimated input, not something a connector can pull automatically. None of this data
 * comes from CatalogConnectorInterface — this report is a separate data path from the stock
 * sync, only sharing the reporting period and the client's own identity.
 */
final class MonthlySalesReportRow
{
    public function __construct(
        public readonly string $retailerName,
        public readonly string $period,
        public readonly int $sessions,
        public readonly int $uniqueVisitors,
        public readonly int $totalOnlineSales,
        public readonly ?int $addedToCart = null,
        public readonly ?int $clickAndCollectSales = null,
        public readonly ?int $boutiqueSales = null,
        public readonly ?int $boutiqueAppointments = null,
        public readonly ?string $comments = null,
    ) {
    }
}
