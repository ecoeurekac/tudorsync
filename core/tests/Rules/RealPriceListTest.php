<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Rules;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Rules\ValidModelList;

/**
 * Checks the real price list shipped in core/resources/valid-models, so a change in TUDOR's
 * file format is caught by `composer test` when the file is replaced, not in production.
 */
final class RealPriceListTest extends TestCase
{
    private const FILE = __DIR__ . '/../../resources/valid-models/prices_ES.xlsx';

    public function testSpanishListExistsAndHasATmcColumn(): void
    {
        self::assertFileExists(self::FILE);

        $list = new ValidModelList();
        self::assertTrue($list->isLoaded('ES'), (string) $list->getProblem('ES'));
    }

    public function testSpanishListHasAtLeastTheMinimumNumberOfModels(): void
    {
        self::assertGreaterThanOrEqual(ValidModelList::MIN_MODELS, (new ValidModelList())->count('ES'));
    }

    public function testEveryCodeInTheSpanishListLooksLikeATmc(): void
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $rows = $reader->load(self::FILE)->getActiveSheet()->toArray(null, false, false, false);
        $column = array_search('TMC', array_map(static fn ($title): string => ValidModelList::normalize((string) $title), $rows[0]), true);
        self::assertIsInt($column, 'no TMC column in the first row');

        $malformed = [];
        foreach (array_slice($rows, 1) as $row) {
            $code = ValidModelList::normalize((string) ($row[$column] ?? ''));
            if ($code !== '' && preg_match('/^M[0-9A-Z\/]+-\d{4}$/', $code) !== 1) {
                $malformed[] = $code;
            }
        }

        self::assertSame([], $malformed);
    }
}
