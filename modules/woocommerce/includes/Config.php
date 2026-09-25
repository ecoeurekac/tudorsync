<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;

/**
 * Reads this store's TUDOR sync settings from WordPress options, filled in via the
 * settings page registered by Admin\SettingsPage (Settings > TUDOR Sync).
 */
final class Config
{
    public const OPTION_COUNTRY = 'tudorsync_country';
    public const OPTION_ENVIRONMENT = 'tudorsync_environment';
    public const OPTION_API_KEY_STAGING = 'tudorsync_api_key_staging';
    public const OPTION_API_KEY_PRODUCTION = 'tudorsync_api_key_production';
    public const OPTION_CLICK_AND_COLLECT_ENABLED = 'tudorsync_click_and_collect_enabled';

    /**
     * Post meta key holding the TUDOR model code on a product — its presence (non-empty)
     * is what marks a product as enrolled in the TUDOR program at all. Added to the product
     * edit screen by ProductField.
     */
    public const META_MODEL_CODE = '_tudor_model_code';

    public function getClientConfig(): ClientConfig
    {
        $environment = Environment::from((string) get_option(self::OPTION_ENVIRONMENT, Environment::Staging->value));

        $apiKeyOption = $environment === Environment::Production
            ? self::OPTION_API_KEY_PRODUCTION
            : self::OPTION_API_KEY_STAGING;

        return new ClientConfig(
            clientName: (string) get_bloginfo('name'),
            market: (string) get_option(self::OPTION_COUNTRY, ''),
            languages: [], // TODO: derive from whatever multilingual plugin (if any) the client uses, once confirmed
            environment: $environment,
            tudorApiKey: (string) get_option($apiKeyOption, ''),
            offersClickAndCollect: (bool) get_option(self::OPTION_CLICK_AND_COLLECT_ENABLED, false),
        );
    }
}
