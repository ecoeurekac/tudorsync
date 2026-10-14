<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Domain\ProgrammeSales;

final class ProgrammeSalesTest extends TestCase
{
    public function testKeepsTheValues(): void
    {
        $sales = new ProgrammeSales(watchesSoldOnline: 7, clickAndCollectSales: 2);

        self::assertSame(7, $sales->watchesSoldOnline);
        self::assertSame(2, $sales->clickAndCollectSales);
    }

    public function testClickAndCollectCanBeNull(): void
    {
        $sales = new ProgrammeSales(watchesSoldOnline: 3, clickAndCollectSales: null);

        self::assertSame(3, $sales->watchesSoldOnline);
        self::assertNull($sales->clickAndCollectSales);
        self::assertNull((new ProgrammeSales(3))->clickAndCollectSales);
    }

    public function testAcceptsZeroSales(): void
    {
        $sales = new ProgrammeSales(0, 0);

        self::assertSame(0, $sales->watchesSoldOnline);
        self::assertSame(0, $sales->clickAndCollectSales);
    }

    public function testClickAndCollectCanEqualTheOnlineSales(): void
    {
        self::assertSame(4, (new ProgrammeSales(4, 4))->clickAndCollectSales);
    }

    public function testRejectsNegativeOnlineSales(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProgrammeSales(-1);
    }

    public function testRejectsNegativeClickAndCollectSales(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProgrammeSales(5, -1);
    }

    public function testRejectsMoreClickAndCollectThanOnlineSales(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed watchesSoldOnline');

        new ProgrammeSales(2, 3);
    }
}
