<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Report;

/**
 * "Sold online through the programme" figures of TUDOR's monthly report for one period, plus the
 * order lines they were counted from (for the CLI check; not part of the report itself).
 *
 * Shape matches the ProgrammeSales proposed to core in intercambio/2026-09-30-juanjo-peticion-
 * oauth-e-informe.md, so it can be mapped one to one once core defines the interface.
 */
class ProgrammeSales
{
    /**
     * @param list<array{increment_id: string, created_at: string, sku: string, model_code: string,
     *     units: int, click_and_collect: bool, attributed: bool}> $lines
     */
    public function __construct(
        public readonly int $watchesSoldOnline,
        public readonly ?int $clickAndCollectSales,
        public readonly array $lines,
    ) {
    }
}
