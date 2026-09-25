<?php

declare(strict_types=1);

namespace Tudorsync\Core\Reporting;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Fills TUDOR's own monthly sales-report Excel template (core/resources/report-templates/)
 * with MonthlySalesReportRow data, one row per reporting period, and saves the result.
 *
 * Column layout below comes directly from inspecting the shipped templates (identical
 * structure across all five languages — DE/EN/ES/FR/IT — only the label text differs):
 *
 *   Row 1        title (merged A1:G1)
 *   Row 2        A2 "País:"-equivalent label, B2 = country display name (set once, not per row)
 *   Row 4        column headers, A:J
 *   Rows 5-19    one reporting period per row (15 rows). Row 5 ships with TUDOR's own
 *                worked example values pre-filled (not blank!) — every cell this writer
 *                touches is overwritten unconditionally, including with an explicit null
 *                for an unset optional field, precisely so that example content never
 *                survives into a generated report.
 *   Row 20       NOT a data row despite being inside the sheet's Excel Table range
 *                (A4:J20) — it holds the "*obligatorio" mandatory-field markers and a
 *                footnote. Writing into it would destroy that annotation, so this writer
 *                caps out at row 19 (14 periods after the seeded example row), one short
 *                of the table's nominal range.
 *   Columns      A retailer · B period · C sessions · D unique visitors · E added to cart ·
 *                F total online sales · G click&collect sales (subset of F) ·
 *                H boutique sales · I boutique appointments · J comments
 *
 * Optional fields left null are written as an explicit blank (see the row 5 note above) —
 * never defaulted to "N/A" or similar, even though the template's own example row shows
 * "N/A" for an untracked boutique figure: nothing confirms that's the required convention
 * for every optional column, so this writer doesn't invent one.
 *
 * VERIFIED: exercised against the real ES template via `composer test`
 * (MonthlyReportWriterTest) — the row/column layout and the null-clears-example-content
 * behavior above were confirmed by an actual failing test, not just read from the XML.
 */
final class MonthlyReportWriter
{
    private const FIRST_DATA_ROW = 5;
    private const LAST_DATA_ROW = 19; // row 20 is the "*obligatorio"/footnote row, not data — see class docblock

    private const COLUMN_RETAILER = 'A';
    private const COLUMN_PERIOD = 'B';
    private const COLUMN_SESSIONS = 'C';
    private const COLUMN_UNIQUE_VISITORS = 'D';
    private const COLUMN_ADDED_TO_CART = 'E';
    private const COLUMN_TOTAL_ONLINE_SALES = 'F';
    private const COLUMN_CLICK_AND_COLLECT_SALES = 'G';
    private const COLUMN_BOUTIQUE_SALES = 'H';
    private const COLUMN_BOUTIQUE_APPOINTMENTS = 'I';
    private const COLUMN_COMMENTS = 'J';

    private const COUNTRY_CELL = 'B2';

    /**
     * @param MonthlySalesReportRow[] $rows One row per reporting period, in the order they
     *                                      should appear (typically chronological).
     */
    public function write(string $templatePath, string $country, array $rows, string $outputPath): void
    {
        $maxRows = self::LAST_DATA_ROW - self::FIRST_DATA_ROW + 1;

        if (count($rows) > $maxRows) {
            throw new RuntimeException(sprintf(
                'Cannot write %d rows: the template only has room for %d reporting periods '
                . '(rows %d-%d). Extending the table range requires editing the template in Excel.',
                count($rows),
                $maxRows,
                self::FIRST_DATA_ROW,
                self::LAST_DATA_ROW,
            ));
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue(self::COUNTRY_CELL, $country);

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = self::FIRST_DATA_ROW + $index;

            $sheet->setCellValue(self::COLUMN_RETAILER . $rowNumber, $row->retailerName);
            $sheet->setCellValue(self::COLUMN_PERIOD . $rowNumber, $row->period);
            $sheet->setCellValue(self::COLUMN_SESSIONS . $rowNumber, $row->sessions);
            $sheet->setCellValue(self::COLUMN_UNIQUE_VISITORS . $rowNumber, $row->uniqueVisitors);
            $sheet->setCellValue(self::COLUMN_TOTAL_ONLINE_SALES . $rowNumber, $row->totalOnlineSales);
            $this->setOptional($sheet, self::COLUMN_ADDED_TO_CART . $rowNumber, $row->addedToCart);
            $this->setOptional($sheet, self::COLUMN_CLICK_AND_COLLECT_SALES . $rowNumber, $row->clickAndCollectSales);
            $this->setOptional($sheet, self::COLUMN_BOUTIQUE_SALES . $rowNumber, $row->boutiqueSales);
            $this->setOptional($sheet, self::COLUMN_BOUTIQUE_APPOINTMENTS . $rowNumber, $row->boutiqueAppointments);
            $this->setOptional($sheet, self::COLUMN_COMMENTS . $rowNumber, $row->comments);
        }

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($outputPath);
    }

    private function setOptional(Worksheet $sheet, string $cell, int|string|null $value): void
    {
        // Always write, even when $value is null: row 5 ships with TUDOR's own example
        // content pre-filled, so skipping the call here would let stale example text (e.g.
        // "N/A") leak into a real generated report instead of being cleared.
        $sheet->setCellValue($cell, $value);
    }
}
