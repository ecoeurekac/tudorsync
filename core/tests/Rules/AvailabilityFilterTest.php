<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Rules;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Tests\Rules\Fake\PriceListFiles;

final class AvailabilityFilterTest extends TestCase
{
    public function testDropsItemsNotEnabledForOnlinePurchase(): void
    {
        $enabled = $this->stock(modelCode: 'M79030N', onlinePurchaseEnabled: true, value: 5);
        $disabled = $this->stock(modelCode: 'M79230B', onlinePurchaseEnabled: false, value: 5);

        $result = (new AvailabilityFilter())->keepOnlyAvailable([$enabled, $disabled]);

        self::assertSame([$enabled], $result);
    }

    public function testDropsItemsWithNoStockValueEvenIfEnabled(): void
    {
        $inStock = $this->stock(modelCode: 'M79030N', onlinePurchaseEnabled: true, value: 1);
        $outOfStock = $this->stock(modelCode: 'M79230B', onlinePurchaseEnabled: true, value: 0);

        $result = (new AvailabilityFilter())->keepOnlyAvailable([$inStock, $outOfStock]);

        self::assertSame([$inStock], $result);
    }

    public function testEmptyCatalogStaysEmpty(): void
    {
        self::assertSame([], (new AvailabilityFilter())->keepOnlyAvailable([]));
    }

    public function testDropsAnAvailableModelNotInTheValidModelList(): void
    {
        $files = new PriceListFiles();
        $files->writeTudorShaped('prices_ES.xlsx', ['m79030n-0001']);

        $current = $this->stock(modelCode: 'M79030N-0001', onlinePurchaseEnabled: true, value: 1, country: 'ES');
        $discontinued = $this->stock(modelCode: 'M79030N-0002', onlinePurchaseEnabled: true, value: 1, country: 'ES');
        $notAvailable = $this->stock(modelCode: 'M25807KN-0001', onlinePurchaseEnabled: false, value: 1, country: 'ES');

        $filter = new AvailabilityFilter(new ValidModelList($files->directory()));
        $result = $filter->keepOnlyAvailable([$current, $discontinued, $notAvailable]);
        $files->remove();

        self::assertSame([$current], $result);
        self::assertSame(['M79030N-0002'], $filter->getExcludedModelCodes());
        self::assertSame([], $filter->getWarnings());
    }

    public function testKeepsAnAvailableModelThatIsInTheValidModelList(): void
    {
        $files = new PriceListFiles();
        $files->writeTudorShaped('prices_ES.xlsx', ['m25707b/26-0001', 'm79030n-0001']);

        $items = [
            $this->stock(modelCode: 'M25707B/26-0001', onlinePurchaseEnabled: true, value: 2, country: 'ES'),
            $this->stock(modelCode: 'M79030N-0001', onlinePurchaseEnabled: true, value: 1, country: 'ES'),
        ];

        $filter = new AvailabilityFilter(new ValidModelList($files->directory()));
        $result = $filter->keepOnlyAvailable($items);
        $files->remove();

        self::assertSame($items, $result);
        self::assertSame([], $filter->getExcludedModelCodes());
    }

    public function testWithoutAListForTheCountryEverythingAvailablePassesWithAWarning(): void
    {
        $files = new PriceListFiles();
        $items = [$this->stock(modelCode: 'M79030N-0002', onlinePurchaseEnabled: true, value: 1, country: 'ES')];

        $filter = new AvailabilityFilter(new ValidModelList($files->directory()));
        $result = $filter->keepOnlyAvailable($items);
        $files->remove();

        self::assertSame($items, $result);
        self::assertSame(['Filtro de modelos vigentes DESACTIVADO: no se encuentra prices_ES.xlsx'], $filter->getWarnings());
        self::assertSame([], $filter->getExcludedModelCodes());
    }

    public function testOutsideTheBatchTheListIsAppliedEvenIfItEmptiesACountry(): void
    {
        $files = new PriceListFiles();
        $files->writeTudorShaped('prices_ES.xlsx', []);
        $items = [
            $this->stock(modelCode: 'M79030N-0002', onlinePurchaseEnabled: true, value: 1, country: 'ES'),
            $this->stock(modelCode: 'M25807KN-0001', onlinePurchaseEnabled: true, value: 1, country: 'ES'),
        ];

        $filter = new AvailabilityFilter(new ValidModelList($files->directory()));
        $result = $filter->keepOnlyAvailable($items);
        $files->remove();

        // The batch keeps them (keepOnlyAvailableForBatch(), see AvailabilityFilterBatchTest).
        self::assertSame([], $result);
        self::assertSame(['M25807KN-0001', 'M79030N-0002'], $filter->getExcludedModelCodes());
        self::assertSame([], $filter->getWarnings());
    }

    private function stock(string $modelCode, bool $onlinePurchaseEnabled, int $value, string $country = 'CH'): StockAvailability
    {
        return new StockAvailability(
            modelCode: $modelCode,
            country: $country,
            value: $value,
            defaultUrl: 'https://example.com/' . $modelCode,
            localizedUrls: [],
            onlinePurchaseEnabled: $onlinePurchaseEnabled,
            storePickupAvailable: false,
        );
    }
}
