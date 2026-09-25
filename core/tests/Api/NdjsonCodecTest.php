<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Api;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Api\NdjsonCodec;

final class NdjsonCodecTest extends TestCase
{
    public function testEncodesOneJsonObjectPerLine(): void
    {
        $ndjson = (new NdjsonCodec())->encode([
            ['mc' => 'TMC-0001', 'country' => 'TR'],
            ['mc' => 'TMC-0002', 'country' => 'CH'],
        ]);

        self::assertSame(
            "{\"mc\":\"TMC-0001\",\"country\":\"TR\"}\n{\"mc\":\"TMC-0002\",\"country\":\"CH\"}",
            $ndjson,
        );
    }

    public function testDecodesEachLineBackToAnArray(): void
    {
        $body = "{\"mc\":\"TMC-0001\",\"status\":\"CREATED\"}\n{\"mc\":\"TMC-0002\",\"status\":\"UPDATED\"}\n";

        $records = (new NdjsonCodec())->decode($body);

        self::assertSame([
            ['mc' => 'TMC-0001', 'status' => 'CREATED'],
            ['mc' => 'TMC-0002', 'status' => 'UPDATED'],
        ], $records);
    }

    public function testDecodingEmptyBodyReturnsEmptyArray(): void
    {
        self::assertSame([], (new NdjsonCodec())->decode(''));
    }
}
