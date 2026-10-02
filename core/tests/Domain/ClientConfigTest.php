<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;

/**
 * The platform modules build ClientConfig with named arguments and no OAuth credentials;
 * that has to keep working until all three have migrated.
 */
final class ClientConfigTest extends TestCase
{
    public function testBuildsLikeTheMagentoModuleDoesToday(): void
    {
        $config = new ClientConfig(
            clientName: 'Quera',
            market: 'ES',
            languages: [],
            environment: Environment::Staging,
            tudorApiKey: '',
            offersClickAndCollect: true,
        );

        self::assertSame('', $config->clientId);
        self::assertSame('', $config->clientSecret);
        self::assertTrue($config->offersClickAndCollect);
    }

    public function testBuildsLikeThePrestaShopAndWooCommerceModulesDoToday(): void
    {
        $config = new ClientConfig(
            clientName: 'Grau',
            market: 'ES',
            languages: [],
            environment: Environment::Production,
            tudorApiKey: 'legacy-key',
            offersClickAndCollect: false,
        );

        self::assertSame('legacy-key', $config->tudorApiKey);
        self::assertSame('', $config->clientId);
    }

    public function testKeepsTheOriginalPositionalOrder(): void
    {
        $config = new ClientConfig('Quera', 'ES', ['es'], Environment::Staging, 'legacy-key', true);

        self::assertSame('legacy-key', $config->tudorApiKey);
        self::assertTrue($config->offersClickAndCollect);
    }

    public function testAcceptsOAuthCredentialsWithoutAnApiKey(): void
    {
        $config = new ClientConfig(
            clientName: 'Quera',
            market: 'ES',
            languages: [],
            environment: Environment::Staging,
            clientId: 'client-id',
            clientSecret: 'client-secret',
        );

        self::assertSame('', $config->tudorApiKey);
        self::assertSame('client-id', $config->clientId);
        self::assertSame('client-secret', $config->clientSecret);
    }
}
