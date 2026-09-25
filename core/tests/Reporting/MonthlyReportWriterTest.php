<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Reporting;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Reporting\MonthlyReportWriter;
use Tudorsync\Core\Reporting\MonthlySalesReportRow;

/**
 * Exercises MonthlyReportWriter against the real ES template shipped in
 * core/resources/report-templates/. UNVERIFIED: written without a PHP/Composer toolchain
 * available — run `composer test` to confirm this actually passes.
 */
final class MonthlyReportWriterTest extends TestCase
{
    private const TEMPLATE_PATH = __DIR__ . '/../../resources/report-templates/e-Com-e-Stock program-template_ES.xlsx';

    public function testWritesRowsIntoTheTemplateStartingAtRowFive(): void
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'tudorsync-report') . '.xlsx';

        $rows = [
            new MonthlySalesReportRow(
                retailerName: 'Grau',
                period: '2026-06',
                sessions: 420,
                uniqueVisitors: 300,
                totalOnlineSales: 10,
                addedToCart: 40,
                clickAndCollectSales: 5,
                boutiqueSales: null,
                boutiqueAppointments: 30,
                comments: 'Ejemplo',
            ),
        ];

        (new MonthlyReportWriter())->write(self::TEMPLATE_PATH, 'España', $rows, $outputPath);

        $sheet = IOFactory::load($outputPath)->getActiveSheet();

        self::assertSame('España', $sheet->getCell('B2')->getValue());
        self::assertSame('Grau', $sheet->getCell('A5')->getValue());
        self::assertSame('2026-06', $sheet->getCell('B5')->getValue());
        self::assertSame(420, $sheet->getCell('C5')->getValue());
        self::assertSame(10, $sheet->getCell('F5')->getValue());
        self::assertNull($sheet->getCell('H5')->getValue()); // boutiqueSales left null → untouched

        unlink($outputPath);
    }

    public function testRefusesMoreRowsThanTheTemplateHasRoomFor(): void
    {
        $this->expectException(\RuntimeException::class);

        $tooManyRows = array_fill(0, 16, new MonthlySalesReportRow(
            retailerName: 'Grau',
            period: '2026-01',
            sessions: 1,
            uniqueVisitors: 1,
            totalOnlineSales: 0,
        ));

        (new MonthlyReportWriter())->write(
            self::TEMPLATE_PATH,
            'España',
            $tooManyRows,
            tempnam(sys_get_temp_dir(), 'tudorsync-report') . '.xlsx',
        );
    }
}
