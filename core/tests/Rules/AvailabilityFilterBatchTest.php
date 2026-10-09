<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Rules;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\CountrySentUnfiltered;
use Tudorsync\Core\Rules\Exclusion;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Tests\Rules\Fake\PriceListFiles;

/**
 * The list of current models is always applied by keepOnlyAvailable(); keepOnlyAvailableForBatch()
 * skips it only for a country where it would leave no watch.
 */
final class AvailabilityFilterBatchTest extends TestCase
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

    public function testASingleWatchOutsideTheListIsDroppedWithoutAListSkippedWarning(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', ['mtest1-0001']);
        $filter = $this->filter();

        $result = $filter->keepOnlyAvailable([$this->stock('MTEST2-0001')]);

        self::assertSame([], $result);
        self::assertEquals([$this->notInList('MTEST2-0001', 'ES')], $filter->getExclusions());
        self::assertSame(['MTEST2-0001'], $filter->getExcludedModelCodes());
        self::assertSame([], $filter->getWarnings());
        self::assertSame([], $filter->getCountriesSentUnfiltered());
    }

    public function testKeepOnlyAvailableDropsEveryWatchWhenNoneIsInTheList(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', []);
        $filter = $this->filter();

        $result = $filter->keepOnlyAvailable([$this->stock('MTEST1-0001'), $this->stock('MTEST2-0001')]);

        self::assertSame([], $result);
        self::assertEquals(
            [$this->notInList('MTEST1-0001', 'ES'), $this->notInList('MTEST2-0001', 'ES')],
            $filter->getExclusions(),
        );
        self::assertSame([], $filter->getWarnings());
        self::assertSame([], $filter->getCountriesSentUnfiltered());
    }

    public function testBatchSendsACountryUnfilteredWhenNoWatchIsInTheList(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', []);
        $filter = $this->filter();
        $items = [$this->stock('MTEST1-0001'), $this->stock('MTEST2-0001')];

        $result = $filter->keepOnlyAvailableForBatch([...$items, $this->stock('MTEST3-0001', value: 0)]);

        $warning = 'Lista de modelos vigentes NO aplicada en ES: ningún reloj (2) la supera, lo que suele indicar una '
            . 'lista errónea. Para no dejar el catálogo vacío en TUDOR, se envían sin este filtro. Revisa prices_ES.xlsx.';
        self::assertSame($items, $result);
        self::assertSame([$warning], $filter->getWarnings());
        self::assertEquals(
            [new CountrySentUnfiltered('ES', $warning, [$this->notInList('MTEST1-0001', 'ES'), $this->notInList('MTEST2-0001', 'ES')])],
            $filter->getCountriesSentUnfiltered(),
        );
        // Only the watch with no stock (step 2) is dropped: the ones the list would drop are sent.
        self::assertSame([Exclusion::NOT_AVAILABLE], array_map(static fn (Exclusion $x): string => $x->reason, $filter->getExclusions()));
        self::assertSame([], $filter->getExcludedModelCodes());
    }

    public function testBatchSkipsTheListOnlyInTheCountryItWouldEmpty(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', []);
        $this->files->writeTudorShaped('prices_PT.xlsx', ['mtest1-0001']);
        $filter = $this->filter();
        $spain = [$this->stock('MTEST1-0001'), $this->stock('MTEST2-0001')];
        $portugalIn = $this->stock('MTEST1-0001', country: 'PT');
        $portugalOut = $this->stock('MTEST2-0001', country: 'PT');

        $result = $filter->keepOnlyAvailableForBatch([...$spain, $portugalIn, $portugalOut]);

        self::assertSame([...$spain, $portugalIn], $result);
        self::assertSame(['ES'], array_map(static fn (CountrySentUnfiltered $c): string => $c->country, $filter->getCountriesSentUnfiltered()));
        self::assertEquals([$this->notInList('MTEST2-0001', 'PT')], $filter->getExclusions());
        self::assertSame(['MTEST2-0001'], $filter->getExcludedModelCodes());
    }

    public function testBatchMatchesKeepOnlyAvailableWhenSomeWatchIsInTheList(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', ['mtest1-0001']);
        $items = [$this->stock('MTEST1-0001'), $this->stock('MTEST2-0001'), $this->stock('', value: 3)];
        $strict = $this->filter();
        $batch = $this->filter();

        self::assertSame($strict->keepOnlyAvailable($items), $batch->keepOnlyAvailableForBatch($items));
        self::assertEquals($strict->getExclusions(), $batch->getExclusions());
        self::assertSame($strict->getWarnings(), $batch->getWarnings());
        self::assertSame($strict->getExcludedModelCodes(), $batch->getExcludedModelCodes());
        self::assertSame([], $batch->getCountriesSentUnfiltered());
    }

    public function testWithoutAFileBothMethodsSendUnfilteredWithTheWarning(): void
    {
        $items = [$this->stock('MTEST1-0001')];
        $warning = ['Filtro de modelos vigentes DESACTIVADO: no se encuentra prices_ES.xlsx'];

        foreach (['keepOnlyAvailable', 'keepOnlyAvailableForBatch'] as $method) {
            $filter = $this->filter();

            self::assertSame($items, $filter->{$method}($items), $method);
            self::assertSame($warning, $filter->getWarnings(), $method);
            self::assertSame([], $filter->getExclusions(), $method);
            self::assertSame([], $filter->getCountriesSentUnfiltered(), $method);
        }
    }

    public function testEachCallStartsWithNoCountrySentUnfiltered(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', []);
        $filter = $this->filter();

        $filter->keepOnlyAvailableForBatch([$this->stock('MTEST1-0001')]);
        $filter->keepOnlyAvailable([$this->stock('MTEST1-0001')]);

        self::assertSame([], $filter->getCountriesSentUnfiltered());
    }

    private function filter(): AvailabilityFilter
    {
        return new AvailabilityFilter(new ValidModelList($this->files->directory()));
    }

    private function notInList(string $modelCode, string $country): Exclusion
    {
        return new Exclusion(
            $modelCode,
            $country,
            Exclusion::NOT_IN_VALID_LIST,
            sprintf('No está en la lista de modelos vigentes de TUDOR (prices_%s.xlsx)', $country),
        );
    }

    private function stock(string $modelCode, string $country = 'ES', int $value = 1): StockAvailability
    {
        return new StockAvailability(
            modelCode: $modelCode,
            country: $country,
            value: $value,
            defaultUrl: 'https://example.com/' . strtolower($modelCode),
            localizedUrls: [],
            onlinePurchaseEnabled: true,
            storePickupAvailable: false,
        );
    }
}
