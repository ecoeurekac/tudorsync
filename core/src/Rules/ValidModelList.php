<?php

declare(strict_types=1);

namespace Tudorsync\Core\Rules;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * TUDOR's list of current models for a market, read from the price list TUDOR publishes
 * (core/resources/valid-models/prices_{COUNTRY}.xlsx, the file exactly as TUDOR sends it).
 * Only the column titled "TMC" is used, wherever it is; codes are compared trimmed and in
 * upper case, so TUDOR's "m25707b/26-0001" matches the store's "M25707B/26-0001".
 *
 * This is an extra clean-up of ours, not a TUDOR rule: if a country's list is missing,
 * unreadable, has no TMC column or looks truncated (fewer than MIN_MODELS codes), the list
 * counts as not loaded and getProblem() says why — AvailabilityFilter then sends the
 * catalog unfiltered rather than stopping the sync. Each file is read once per instance.
 */
final class ValidModelList
{
    public const MIN_MODELS = 100;

    private const HEADER_TITLE = 'TMC';
    private const HEADER_ROWS_SCANNED = 10;

    private readonly string $directory;

    /** @var array<string, array{codes: array<string, true>|null, problem: string|null}> */
    private array $loaded = [];

    /**
     * @param string|null $directory Folder holding the prices_XX.xlsx files; defaults to
     *                               core/resources/valid-models (relative to core itself).
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? dirname(__DIR__, 2) . '/resources/valid-models', '/');
    }

    public static function normalize(string $modelCode): string
    {
        return strtoupper((string) preg_replace('/[\s\x{00A0}]+/u', '', $modelCode));
    }

    public function fileName(string $country): string
    {
        return sprintf('prices_%s.xlsx', strtoupper(trim($country)));
    }

    /** Whether the country's list was read and is usable as a filter. */
    public function isLoaded(string $country): bool
    {
        return $this->load($country)['codes'] !== null;
    }

    /** Why the country's list can't be used (null when it is loaded). */
    public function getProblem(string $country): ?string
    {
        return $this->load($country)['problem'];
    }

    /** Whether the model is in the country's list. Always false when the list isn't loaded. */
    public function contains(string $country, string $modelCode): bool
    {
        return isset($this->load($country)['codes'][self::normalize($modelCode)]);
    }

    public function count(string $country): int
    {
        return count($this->load($country)['codes'] ?? []);
    }

    /**
     * @return array{codes: array<string, true>|null, problem: string|null}
     */
    private function load(string $country): array
    {
        $country = strtoupper(trim($country));

        return $this->loaded[$country] ??= $this->read($country);
    }

    /**
     * @return array{codes: array<string, true>|null, problem: string|null}
     */
    private function read(string $country): array
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return $this->failed(sprintf('código de país no válido: "%s"', $country));
        }

        $file = $this->fileName($country);
        $path = $this->directory . '/' . $file;

        if (!is_file($path)) {
            return $this->failed(sprintf('no se encuentra %s', $file));
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $e) {
            return $this->failed(sprintf('no se puede leer %s (%s)', $file, $e->getMessage()));
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, false, false, false);

            foreach (array_slice($rows, 0, self::HEADER_ROWS_SCANNED) as $headerIndex => $header) {
                $column = $this->findTmcColumn($header);
                if ($column === null) {
                    continue;
                }

                $codes = [];
                foreach (array_slice($rows, $headerIndex + 1) as $row) {
                    $code = self::normalize((string) ($row[$column] ?? ''));
                    if ($code !== '') {
                        $codes[$code] = true;
                    }
                }

                if (count($codes) < self::MIN_MODELS) {
                    return $this->failed(sprintf(
                        '%s solo tiene %d modelos (mínimo %d)',
                        $file,
                        count($codes),
                        self::MIN_MODELS,
                    ));
                }

                return ['codes' => $codes, 'problem' => null];
            }
        }

        return $this->failed(sprintf('%s no tiene columna "%s"', $file, self::HEADER_TITLE));
    }

    /**
     * @param array<int, mixed> $header
     */
    private function findTmcColumn(array $header): ?int
    {
        foreach ($header as $index => $title) {
            if (is_scalar($title) && self::normalize((string) $title) === self::HEADER_TITLE) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array{codes: null, problem: string}
     */
    private function failed(string $problem): array
    {
        return ['codes' => null, 'problem' => $problem];
    }
}
