<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Rules\Fake;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes throwaway TUDOR-like price lists (prices_XX.xlsx) into a temporary folder.
 */
final class PriceListFiles
{
    private readonly string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/tudorsync-valid-models-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param list<string> $header
     * @param list<list<mixed>> $rows
     */
    public function write(string $fileName, array $header, array $rows): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([$header, ...$rows], null, 'A1', true);
        (new Xlsx($spreadsheet))->save($this->directory . '/' . $fileName);
    }

    /**
     * A TUDOR-shaped list (TMC in the third column) with the given codes plus enough filler
     * models to reach $total rows.
     *
     * @param list<string> $codes
     */
    public function writeTudorShaped(string $fileName, array $codes, int $total = 120): void
    {
        for ($i = count($codes); $i < $total; $i++) {
            $codes[] = sprintf('m9%04d-0001', $i);
        }

        $this->write(
            $fileName,
            ['Family', 'Model name', 'TMC', 'Price', 'Sales region', 'Currency code', 'Date valid from'],
            array_map(static fn (string $code): array => ['Black Bay', 'Black Bay', $code, 4000, 'ES', 'EUR', '2026-04-01'], $codes),
        );
    }

    public function remove(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
