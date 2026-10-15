<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop;

use Configuration;
use Language;
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
    /** sprintf() pattern, one key per Environment: TUDORSYNC_CLIENT_ID_STAGING, …_PRODUCTION. */
    public const KEY_CLIENT_ID = 'TUDORSYNC_CLIENT_ID_%s';
    public const KEY_CLIENT_SECRET = 'TUDORSYNC_CLIENT_SECRET_%s';
    public const KEY_CLICK_AND_COLLECT_ENABLED = 'TUDORSYNC_CLICK_AND_COLLECT_ENABLED';
    public const KEY_MODEL_CODE_FEATURE_ID = 'TUDORSYNC_MODEL_CODE_FEATURE_ID';
    /** Brand whose products the reference rule applies to (other brands' references may look alike). */
    public const KEY_MANUFACTURER_ID = 'TUDORSYNC_MANUFACTURER_ID';
    /** Reference rule (regex + replacement) that derives the model code; empty = no rule. */
    public const KEY_REFERENCE_PATTERN = 'TUDORSYNC_REFERENCE_PATTERN';
    public const KEY_REFERENCE_REPLACEMENT = 'TUDORSYNC_REFERENCE_REPLACEMENT';
    public const KEY_CRON_TOKEN = 'TUDORSYNC_CRON_TOKEN';
    public const KEY_LAST_TEST_RESULT = 'TUDORSYNC_LAST_TEST_RESULT';
    public const KEY_LAST_SYNC_RESULT = 'TUDORSYNC_LAST_SYNC_RESULT';

    /** Pre-OAuth2 keys, only kept so uninstall() can clean them up. */
    public const LEGACY_KEYS = ['TUDORSYNC_API_KEY_STAGING', 'TUDORSYNC_API_KEY_PRODUCTION'];

    public function getClientConfig(?int $shopId = null): ClientConfig
    {
        $environment = $this->getEnvironment($shopId);

        return new ClientConfig(
            clientName: (string) Configuration::get('PS_SHOP_NAME', null, null, $shopId, ''),
            market: $this->getCountry($shopId),
            languages: $this->getLanguageCodes($shopId),
            environment: $environment,
            offersClickAndCollect: (bool) Configuration::get(self::KEY_CLICK_AND_COLLECT_ENABLED, null, null, $shopId, false),
            // OAuth2 client credentials of the retailer's Okta app for this environment: core's
            // TudorApiClient exchanges them for the access token.
            clientId: $this->getClientId($environment, $shopId),
            clientSecret: $this->getClientSecret($environment, $shopId),
        );
    }

    public function getEnvironment(?int $shopId = null): Environment
    {
        return Environment::tryFrom(
            (string) Configuration::get(self::KEY_ENVIRONMENT, null, null, $shopId, ''),
        ) ?? Environment::Staging;
    }

    /**
     * Client ID of the retailer's Okta application for the given environment (not a secret).
     */
    public function getClientId(Environment $environment, ?int $shopId = null): string
    {
        return trim((string) Configuration::get(self::clientIdKey($environment), null, null, $shopId, ''));
    }

    public function getClientSecret(Environment $environment, ?int $shopId = null): string
    {
        return trim((string) Configuration::get(self::clientSecretKey($environment), null, null, $shopId, ''));
    }

    public function hasCredentials(?int $shopId = null): bool
    {
        $environment = $this->getEnvironment($shopId);

        return $this->getClientId($environment, $shopId) !== ''
            && $this->getClientSecret($environment, $shopId) !== '';
    }

    public static function clientIdKey(Environment $environment): string
    {
        return sprintf(self::KEY_CLIENT_ID, strtoupper($environment->value));
    }

    public static function clientSecretKey(Environment $environment): string
    {
        return sprintf(self::KEY_CLIENT_SECRET, strtoupper($environment->value));
    }

    /**
     * ISO 3166-1 alpha-2 market code, upper-cased; empty string when not configured.
     */
    public function getCountry(?int $shopId = null): string
    {
        return strtoupper(trim((string) Configuration::get(self::KEY_COUNTRY, null, null, $shopId, '')));
    }

    /**
     * Active shop languages as locales (es-ES, ca-ES…), default language first.
     *
     * @return string[]
     */
    public function getLanguageCodes(?int $shopId = null): array
    {
        $defaultLangId = $this->getDefaultLanguageId($shopId);
        $codes = [];

        foreach (Language::getLanguages(true, $shopId ?? false) as $language) {
            $code = self::localeOf($language);

            if ($code === '') {
                continue;
            }

            if ((int) $language['id_lang'] === $defaultLangId) {
                array_unshift($codes, $code);
            } else {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    public function getDefaultLanguageId(?int $shopId = null): int
    {
        return (int) Configuration::get('PS_LANG_DEFAULT', null, null, $shopId, 0);
    }

    /**
     * @param array<string, mixed> $language A row as returned by Language::getLanguages().
     */
    public static function localeOf(array $language): string
    {
        return (string) ($language['locale'] ?? $language['language_code'] ?? $language['iso_code'] ?? '');
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

    public function getManufacturerId(): int
    {
        return (int) Configuration::get(self::KEY_MANUFACTURER_ID, null, null, null, 0);
    }

    public function getReferencePattern(): string
    {
        return trim((string) Configuration::get(self::KEY_REFERENCE_PATTERN, null, null, null, ''));
    }

    public function getReferenceReplacement(): string
    {
        return trim((string) Configuration::get(self::KEY_REFERENCE_REPLACEMENT, null, null, null, ''));
    }
}
