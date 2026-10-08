<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Rules;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Tests\Rules\Fake\PriceListFiles;

final class ValidModelListTest extends TestCase
{
    private PriceListFiles $files;

    protected function setUp(): void
    {
        $this->files = new PriceListFiles();
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testLowerCaseCodeWithSpacesInTheListMatchesTheStoreUpperCaseCode(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', [' m25707b/26-0001 ', "m79030n -\u{00A0}0001"]);
        $list = new ValidModelList($this->files->directory());

        self::assertTrue($list->isLoaded('ES'));
        self::assertNull($list->getProblem('ES'));
        self::assertTrue($list->contains('ES', 'M25707B/26-0001'));
        self::assertTrue($list->contains('es', 'M79030N-0001'));
        self::assertFalse($list->contains('ES', 'M25707B/25-0001'));
    }

    public function testFindsTheTmcColumnInAnyPositionAndWithAnyCase(): void
    {
        $rows = [['m28600-0003', 'x']];
        for ($i = 1; $i < 110; $i++) {
            $rows[] = [sprintf('m8%04d-0001', $i), 'x'];
        }
        $this->files->write('prices_FR.xlsx', [' tmc ', 'Model name'], $rows);
        $list = new ValidModelList($this->files->directory());

        self::assertTrue($list->isLoaded('FR'));
        self::assertSame(110, $list->count('FR'));
        self::assertTrue($list->contains('FR', 'M28600-0003'));
        self::assertFalse($list->contains('FR', 'X'));
    }

    public function testMissingFileIsNotLoadedAndSaysWhy(): void
    {
        $list = new ValidModelList($this->files->directory());

        self::assertFalse($list->isLoaded('ES'));
        self::assertSame('no se encuentra prices_ES.xlsx', $list->getProblem('ES'));
        self::assertFalse($list->contains('ES', 'M79030N-0001'));
    }

    public function testFileWithoutTmcColumnIsNotLoaded(): void
    {
        $this->files->write('prices_ES.xlsx', ['Family', 'Model code'], [['Black Bay', 'm79030n-0001']]);
        $list = new ValidModelList($this->files->directory());

        self::assertFalse($list->isLoaded('ES'));
        self::assertSame('prices_ES.xlsx no tiene columna "TMC"', $list->getProblem('ES'));
    }

    public function testListWithTooFewModelsIsNotLoaded(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', [], total: ValidModelList::MIN_MODELS - 1);
        $list = new ValidModelList($this->files->directory());

        self::assertFalse($list->isLoaded('ES'));
        self::assertSame('prices_ES.xlsx solo tiene 99 modelos (mínimo 100)', $list->getProblem('ES'));
    }

    public function testUnreadableFileIsNotLoaded(): void
    {
        file_put_contents($this->files->directory() . '/prices_ES.xlsx', 'not a spreadsheet');
        $list = new ValidModelList($this->files->directory());

        self::assertFalse($list->isLoaded('ES'));
        self::assertStringStartsWith('no se puede leer prices_ES.xlsx', (string) $list->getProblem('ES'));
    }

    public function testInvalidCountryCodeIsNotLoaded(): void
    {
        $list = new ValidModelList($this->files->directory());

        self::assertFalse($list->isLoaded('../ES'));
        self::assertSame('código de país no válido: "../ES"', $list->getProblem('../ES'));
    }

    public function testEachFileIsReadOnlyOnce(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', ['m79030n-0001']);
        $list = new ValidModelList($this->files->directory());
        self::assertTrue($list->contains('ES', 'M79030N-0001'));

        unlink($this->files->directory() . '/prices_ES.xlsx');

        self::assertTrue($list->isLoaded('ES'));
        self::assertTrue($list->contains('ES', 'M79030N-0001'));
    }
}
