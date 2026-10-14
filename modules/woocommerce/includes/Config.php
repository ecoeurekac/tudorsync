<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;

/**
 * Reads this store's TUDOR sync settings from WordPress options, filled in via the
 * settings page registered by Admin\SettingsPage (Settings > TUDOR Sync).
 *
 * Credentials are TUDOR's OAuth2 client ID / client secret (the retailer's Okta app),
 * one pair per Environment; core's TudorApiClient exchanges them for an access token.
 */
final class Config
{
    public const OPTION_COUNTRY = 'tudorsync_country';
    public const OPTION_ENVIRONMENT = 'tudorsync_environment';
    /** sprintf() pattern, one option per Environment: tudorsync_client_id_staging, …_production. */
    public const OPTION_CLIENT_ID = 'tudorsync_client_id_%s';
    public const OPTION_CLIENT_SECRET = 'tudorsync_client_secret_%s';
    public const OPTION_CLICK_AND_COLLECT_ENABLED = 'tudorsync_click_and_collect_enabled';
    /**
     * Consent category the order-attribution cookie waits for, checked against the store's cookie
     * manager (WebToffee's cookielawinfo-checkbox-<category>). Empty = write without asking.
     */
    public const OPTION_ATTRIBUTION_CONSENT_CATEGORY = 'tudorsync_attribution_consent_category';

    /** Pre-OAuth2 options, kept only so a future uninstall can clean them up. */
    public const LEGACY_OPTIONS = ['tudorsync_api_key_staging', 'tudorsync_api_key_production'];

    /**
     * Post meta key holding the TUDOR model code on a product — its presence (non-empty)
     * is what marks a product as enrolled in the TUDOR program at all. Added to the product
     * edit screen by ProductField.
     */
    public const META_MODEL_CODE = '_tudor_model_code';

    public function getClientConfig(): ClientConfig
    {
        $environment = $this->getEnvironment();

        return new ClientConfig(
            clientName: (string) get_bloginfo('name'),
            market: $this->getCountry(),
            languages: [], // TODO: derive from whatever multilingual plugin (if any) the client uses, once confirmed
            environment: $environment,
            offersClickAndCollect: (bool) get_option(self::OPTION_CLICK_AND_COLLECT_ENABLED, false),
            // OAuth2 client credentials of the retailer's Okta app for this environment.
            clientId: $this->getClientId($environment),
            clientSecret: $this->getClientSecret($environment),
        );
    }

    public function getEnvironment(): Environment
    {
        return Environment::tryFrom((string) get_option(self::OPTION_ENVIRONMENT, ''))
            ?? Environment::Staging;
    }

    /**
     * Client ID of the retailer's Okta application for the given environment (not a secret).
     */
    public function getClientId(Environment $environment): string
    {
        return trim((string) get_option(self::clientIdOption($environment), ''));
    }

    public function getClientSecret(Environment $environment): string
    {
        return trim((string) get_option(self::clientSecretOption($environment), ''));
    }

    /**
     * True when the active environment has both a client ID and a client secret: the gate the
     * settings page, cron and actions use before trying to talk to TUDOR.
     */
    public function hasCredentials(): bool
    {
        $environment = $this->getEnvironment();

        return $this->getClientId($environment) !== '' && $this->getClientSecret($environment) !== '';
    }

    public static function clientIdOption(Environment $environment): string
    {
        return sprintf(self::OPTION_CLIENT_ID, $environment->value);
    }

    public static function clientSecretOption(Environment $environment): string
    {
        return sprintf(self::OPTION_CLIENT_SECRET, $environment->value);
    }

    /**
     * ISO 3166-1 alpha-2 market code, upper-cased; empty string when not configured.
     */
    public function getCountry(): string
    {
        return strtoupper(trim((string) get_option(self::OPTION_COUNTRY, '')));
    }

    /**
     * Consent category the attribution cookie waits for (e.g. a WebToffee category slug like
     * "advertisement"). Empty string means write the cookie without asking.
     */
    public function getAttributionConsentCategory(): string
    {
        return trim((string) get_option(self::OPTION_ATTRIBUTION_CONSENT_CATEGORY, ''));
    }
}
