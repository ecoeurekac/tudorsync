<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Url;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Url\LocaleUrlResolver;

final class LocaleUrlResolverTest extends TestCase
{
    public function testReturnsLocaleUrlWhenAvailable(): void
    {
        $resolver = new LocaleUrlResolver();

        $url = $resolver->resolve(['es' => 'https://example.com/es', 'en' => 'https://example.com/en'], 'es', 'https://example.com/default');

        self::assertSame('https://example.com/es', $url);
    }

    public function testFallsBackToDefaultWhenLocaleMissing(): void
    {
        $resolver = new LocaleUrlResolver();

        $url = $resolver->resolve(['en' => 'https://example.com/en'], 'fr', 'https://example.com/default');

        self::assertSame('https://example.com/default', $url);
    }
}
