<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Api;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Api\BatchSyncResult;
use Tudorsync\Core\Api\StockImportResult;

final class BatchSyncResultTest extends TestCase
{
    public function testAllSucceededIsTrueWhenNothingFailed(): void
    {
        $result = new BatchSyncResult([
            new StockImportResult('TMC-0001', 'TR', 'CREATED', 'Stock created successfully'),
            new StockImportResult('TMC-0002', 'CH', 'UPDATED', 'Stock updated successfully'),
        ]);

        self::assertTrue($result->allSucceeded());
        self::assertSame([], $result->failures());
    }

    public function testFailuresReturnsOnlyFailedLines(): void
    {
        $failed = new StockImportResult('TMC-0003', 'DE', 'FAILED', 'Invalid value');
        $result = new BatchSyncResult([
            new StockImportResult('TMC-0001', 'TR', 'CREATED', 'Stock created successfully'),
            $failed,
        ]);

        self::assertFalse($result->allSucceeded());
        self::assertSame([$failed], $result->failures());
    }
}
