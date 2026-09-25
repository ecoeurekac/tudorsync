<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Rules;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;

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

    private function stock(string $modelCode, bool $onlinePurchaseEnabled, int $value): StockAvailability
    {
        return new StockAvailability(
            modelCode: $modelCode,
            country: 'CH',
            value: $value,
            defaultUrl: 'https://example.com/' . $modelCode,
            localizedUrls: [],
            onlinePurchaseEnabled: $onlinePurchaseEnabled,
            storePickupAvailable: false,
        );
    }
}
