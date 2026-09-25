<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Url;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Url\UtmUrlBuilder;

final class UtmUrlBuilderTest extends TestCase
{
    public function testAppendsTrackingParamsToPlainUrl(): void
    {
        $url = (new UtmUrlBuilder())->withTracking('https://example.com/watch');

        self::assertSame(
            'https://example.com/watch?utm_source=tudorwatch.com&utm_medium=website&utm_campaign=tudor_e-stock_program',
            $url,
        );
    }

    public function testPreservesExistingQueryString(): void
    {
        $url = (new UtmUrlBuilder())->withTracking('https://example.com/watch?ref=abc');

        self::assertStringStartsWith('https://example.com/watch?ref=abc&utm_source=', $url);
    }
}
