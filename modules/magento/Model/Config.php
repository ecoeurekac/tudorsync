<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;

/**
 * Reads this store's TUDOR sync settings from Magento's own system configuration
 * (Stores > Configuration > TUDOR E-commerce Sync — see etc/adminhtml/system.xml),
 * store-view scoped so a multi-store-view install could sync different markets per view.
 */
class Config
{
    private const XML_PATH_COUNTRY = 'tudorsync/general/country';
    private const XML_PATH_ENVIRONMENT = 'tudorsync/general/environment';
    private const XML_PATH_API_KEY_STAGING = 'tudorsync/general/api_key_staging';
    private const XML_PATH_API_KEY_PRODUCTION = 'tudorsync/general/api_key_production';
    private const XML_PATH_CLICK_AND_COLLECT_ENABLED = 'tudorsync/general/click_and_collect_enabled';
    private const XML_PATH_STORE_NAME = 'general/store_information/name';
    private const XML_PATH_LOCALE_CODE = 'general/locale/code';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getClientConfig(?int $storeId = null): ClientConfig
    {
        $environment = Environment::from(
            (string) $this->scopeConfig->getValue(self::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE, $storeId),
        );

        $apiKeyPath = $environment === Environment::Production
            ? self::XML_PATH_API_KEY_PRODUCTION
            : self::XML_PATH_API_KEY_STAGING;

        return new ClientConfig(
            clientName: (string) $this->scopeConfig->getValue(self::XML_PATH_STORE_NAME, ScopeInterface::SCOPE_STORE, $storeId),
            market: (string) $this->scopeConfig->getValue(self::XML_PATH_COUNTRY, ScopeInterface::SCOPE_STORE, $storeId),
            languages: [], // TODO: derive from the store's configured store views once confirmed with the client
            environment: $environment,
            tudorApiKey: (string) $this->scopeConfig->getValue($apiKeyPath, ScopeInterface::SCOPE_STORE, $storeId),
            offersClickAndCollect: (bool) $this->scopeConfig->getValue(self::XML_PATH_CLICK_AND_COLLECT_ENABLED, ScopeInterface::SCOPE_STORE, $storeId),
        );
    }

    public function getLocaleCode(int $storeId): ?string
    {
        $locale = $this->scopeConfig->getValue(self::XML_PATH_LOCALE_CODE, ScopeInterface::SCOPE_STORE, $storeId);

        return $locale !== null ? (string) $locale : null;
    }
}
