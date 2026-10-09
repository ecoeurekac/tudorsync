<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;

/**
 * This store's TUDOR settings, kept in wp_options (one option per field, all prefixed
 * `tudorsync_`) and edited on the settings page (Admin\SettingsPage).
 *
 * Every store-specific rule (SKU rule, product scope, pickup shipping methods, consent
 * cookie...) is a setting that is empty by default: nothing about a particular retailer is
 * written in this plugin.
 */
final class Config
{
    public const PREFIX = 'tudorsync_';

    public const COUNTRY = 'country';
    public const ENVIRONMENT = 'environment';
    public const CLIENT_ID_STAGING = 'client_id_staging';
    public const CLIENT_ID_PRODUCTION = 'client_id_production';
    public const CLIENT_SECRET_STAGING = 'client_secret_staging';
    public const CLIENT_SECRET_PRODUCTION = 'client_secret_production';
    public const VALUE_MODE = 'value_mode';
    public const UNMANAGED_STOCK = 'unmanaged_stock';
    public const CLICK_AND_COLLECT = 'click_and_collect_enabled';
    public const HOME_DELIVERY_TIMING = 'home_delivery_timing';
    public const SYNC_FREQUENCY = 'sync_frequency';
    public const REALTIME_ENABLED = 'realtime_enabled';
    public const SKU_PREFIX = 'sku_prefix';
    public const SKU_PATTERN = 'sku_pattern';
    public const SKU_REPLACEMENT = 'sku_replacement';
    public const PRODUCT_SCOPE = 'product_scope';
    public const LOCALE_FORMAT = 'locale_format';
    public const CONSENT_MANAGER = 'consent_manager';
    public const CONSENT_CATEGORY = 'consent_category';
    public const CONSENT_COOKIE = 'consent_cookie';
    public const PICKUP_METHODS = 'pickup_shipping_methods';

    public const VALUE_MODE_QUANTITY = 'quantity';
    public const VALUE_MODE_SIGNAL = 'signal';

    public const UNMANAGED_EXCLUDE = 'exclude';
    public const UNMANAGED_SIGNAL = 'signal';

    public const LOCALE_LANGUAGE_REGION = 'language_region';
    public const LOCALE_LANGUAGE = 'language';

    public const CONSENT_NONE = 'none';
    public const CONSENT_WP_CONSENT_API = 'wp_consent_api';
    public const CONSENT_COOKIE_VALUE = 'cookie';

    public const FREQUENCIES = ['hourly', 'twicedaily', 'daily'];

    /** Defaults for every setting; anything not listed defaults to ''. */
    private const DEFAULTS = [
        self::ENVIRONMENT => 'staging',
        self::VALUE_MODE => self::VALUE_MODE_QUANTITY,
        self::UNMANAGED_STOCK => self::UNMANAGED_EXCLUDE,
        self::CLICK_AND_COLLECT => '0',
        self::SYNC_FREQUENCY => 'hourly',
        self::REALTIME_ENABLED => '1',
        self::LOCALE_FORMAT => self::LOCALE_LANGUAGE_REGION,
        self::CONSENT_MANAGER => self::CONSENT_NONE,
        self::CONSENT_CATEGORY => 'statistics',
        self::PICKUP_METHODS => 'local_pickup, pickup_location',
    ];

    /**
     * Post meta holding a product's TUDOR model code. When a SKU rule is configured a product
     * can instead derive its code from its SKU (see ModelCodeResolver); the meta always wins.
     */
    public const META_MODEL_CODE = '_tudor_model_code';

    public static function optionName(string $key): string
    {
        return self::PREFIX . $key;
    }

    public function get(string $key): string
    {
        return (string) get_option(self::optionName($key), self::DEFAULTS[$key] ?? '');
    }

    public function set(string $key, string $value): void
    {
        update_option(self::optionName($key), $value, true);
    }

    public function getClientConfig(): ClientConfig
    {
        return new ClientConfig(
            clientName: $this->getRetailerName(),
            market: $this->getCountry(),
            languages: [],
            environment: $this->getEnvironment(),
            offersClickAndCollect: $this->offersClickAndCollect(),
            clientId: $this->getClientId(),
            clientSecret: $this->getClientSecret(),
        );
    }

    public function getRetailerName(): string
    {
        $name = trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));

        return $name !== '' ? $name : 'Retailer';
    }

    public function getEnvironment(): Environment
    {
        return Environment::tryFrom($this->get(self::ENVIRONMENT)) ?? Environment::Staging;
    }

    /**
     * ISO 3166-1 alpha-2 market code, upper-cased; empty string when not configured.
     */
    public function getCountry(): string
    {
        return strtoupper(trim($this->get(self::COUNTRY)));
    }

    public function offersClickAndCollect(): bool
    {
        return $this->get(self::CLICK_AND_COLLECT) === '1';
    }

    /**
     * Client ID of this store's TUDOR (Okta) application for the given environment.
     */
    public function getClientId(?Environment $environment = null): string
    {
        $environment ??= $this->getEnvironment();

        return trim($this->get($environment === Environment::Production ? self::CLIENT_ID_PRODUCTION : self::CLIENT_ID_STAGING));
    }

    /**
     * Client secret for the given environment, decrypted (see Crypto).
     */
    public function getClientSecret(?Environment $environment = null): string
    {
        $environment ??= $this->getEnvironment();

        return Crypto::decrypt($this->get(
            $environment === Environment::Production ? self::CLIENT_SECRET_PRODUCTION : self::CLIENT_SECRET_STAGING,
        ));
    }

    public function hasCredentials(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    public function getHomeDeliveryTimingHours(): ?int
    {
        $value = trim($this->get(self::HOME_DELIVERY_TIMING));

        return ctype_digit($value) ? (int) $value : null;
    }

    public function sendsSignalOnly(): bool
    {
        return $this->get(self::VALUE_MODE) === self::VALUE_MODE_SIGNAL;
    }

    /**
     * Whether a product that doesn't manage stock (WooCommerce's plain "in stock" toggle) is
     * sent with value 1 instead of being left out.
     */
    public function sendsUnmanagedStock(): bool
    {
        return $this->get(self::UNMANAGED_STOCK) === self::UNMANAGED_SIGNAL;
    }

    public function getSyncFrequency(): string
    {
        $frequency = $this->get(self::SYNC_FREQUENCY);

        return in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'hourly';
    }

    public function isRealtimeEnabled(): bool
    {
        return $this->get(self::REALTIME_ENABLED) === '1';
    }

    public function getSkuPrefix(): string
    {
        return trim($this->get(self::SKU_PREFIX));
    }

    public function getSkuPattern(): string
    {
        return trim($this->get(self::SKU_PATTERN));
    }

    public function getSkuReplacement(): string
    {
        return trim($this->get(self::SKU_REPLACEMENT));
    }

    /**
     * Terms that mark a product as a TUDOR watch for the SKU rule, as `taxonomy:slug` pairs
     * (e.g. `product_cat:tudor`, `product_brand:tudor`). A product with the model code meta
     * filled in is always read; this only narrows which products the SKU rule is tried on.
     *
     * @return list<array{taxonomy: string, slug: string}>
     */
    public function getProductScope(): array
    {
        $scope = [];

        foreach (preg_split('/[\s,]+/', $this->get(self::PRODUCT_SCOPE)) ?: [] as $entry) {
            if (preg_match('/^([a-z0-9_-]+):([a-z0-9_%-]+)$/i', $entry, $match) === 1) {
                $scope[] = ['taxonomy' => $match[1], 'slug' => $match[2]];
            }
        }

        return $scope;
    }

    public function getLocaleFormat(): string
    {
        return $this->get(self::LOCALE_FORMAT) === self::LOCALE_LANGUAGE ? self::LOCALE_LANGUAGE : self::LOCALE_LANGUAGE_REGION;
    }

    public function getConsentManager(): string
    {
        $manager = $this->get(self::CONSENT_MANAGER);

        return in_array($manager, [self::CONSENT_WP_CONSENT_API, self::CONSENT_COOKIE_VALUE], true) ? $manager : self::CONSENT_NONE;
    }

    public function getConsentCategory(): string
    {
        $category = preg_replace('/[^a-z0-9_-]/i', '', $this->get(self::CONSENT_CATEGORY)) ?? '';

        return $category !== '' ? $category : 'statistics';
    }

    /**
     * Consent cookie as `name=value` (e.g. `cookielawinfo-checkbox-analytics=yes`): the UTM cookie
     * is only written once that cookie holds that value.
     *
     * @return array{name: string, value: string}|null
     */
    public function getConsentCookie(): ?array
    {
        $raw = trim($this->get(self::CONSENT_COOKIE));

        if (preg_match('/^([^=;\s]+)=([^;\s]*)$/', $raw, $match) !== 1) {
            return null;
        }

        return ['name' => $match[1], 'value' => $match[2]];
    }

    /**
     * Shipping method id prefixes that mean click & collect, for the monthly report. An empty
     * list means the store doesn't offer it (the report then shows "n/a").
     *
     * @return list<string>
     */
    public function getPickupShippingMethods(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $this->get(self::PICKUP_METHODS))),
            static fn (string $method): bool => $method !== '',
        ));
    }
}
