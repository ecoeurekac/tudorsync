<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop;

use Configuration;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;

/**
 * Reads this store's TUDOR sync settings from PrestaShop's own Configuration table,
 * filled in via the module's admin config form (Tudorsync::getContent()).
 */
final class Config
{
    public const KEY_COUNTRY = 'TUDORSYNC_COUNTRY';
    public const KEY_ENVIRONMENT = 'TUDORSYNC_ENVIRONMENT';
    public const KEY_API_KEY_STAGING = 'TUDORSYNC_API_KEY_STAGING';
    public const KEY_API_KEY_PRODUCTION = 'TUDORSYNC_API_KEY_PRODUCTION';
    public const KEY_CLICK_AND_COLLECT_ENABLED = 'TUDORSYNC_CLICK_AND_COLLECT_ENABLED';
    public const KEY_MODEL_CODE_FEATURE_ID = 'TUDORSYNC_MODEL_CODE_FEATURE_ID';
    public const KEY_CRON_TOKEN = 'TUDORSYNC_CRON_TOKEN';
    public const KEY_LAST_TEST_RESULT = 'TUDORSYNC_LAST_TEST_RESULT';
    public const KEY_LAST_SYNC_RESULT = 'TUDORSYNC_LAST_SYNC_RESULT';

    public function getClientConfig(?int $shopId = null): ClientConfig
    {
        $environment = Environment::from(
            (string) Configuration::get(self::KEY_ENVIRONMENT, null, null, $shopId, Environment::Staging->value),
        );

        $apiKeyKey = $environment === Environment::Production
            ? self::KEY_API_KEY_PRODUCTION
            : self::KEY_API_KEY_STAGING;

        return new ClientConfig(
            clientName: (string) Configuration::get('PS_SHOP_NAME', null, null, $shopId, ''),
            market: (string) Configuration::get(self::KEY_COUNTRY, null, null, $shopId, ''),
            languages: [], // TODO: derive from Language::getLanguages() once confirmed with the client
            environment: $environment,
            tudorApiKey: (string) Configuration::get($apiKeyKey, null, null, $shopId, ''),
            offersClickAndCollect: (bool) Configuration::get(self::KEY_CLICK_AND_COLLECT_ENABLED, null, null, $shopId, false),
        );
    }

    public function getModelCodeFeatureId(): int
    {
        return (int) Configuration::get(self::KEY_MODEL_CODE_FEATURE_ID, null, null, null, 0);
    }

    public function getCronToken(): string
    {
        return (string) Configuration::get(self::KEY_CRON_TOKEN, null, null, null, '');
    }

    public function getLastTestResult(): string
    {
        return (string) Configuration::get(self::KEY_LAST_TEST_RESULT, null, null, null, '');
    }

    public function getLastSyncResult(): string
    {
        return (string) Configuration::get(self::KEY_LAST_SYNC_RESULT, null, null, null, '');
    }
}
