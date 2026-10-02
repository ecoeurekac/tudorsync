<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\EcommerceSync\Model\Config\Source\LocaleFormat;
use Tudorsync\EcommerceSync\Model\Config\Source\ValueMode;

/**
 * Reads this store's TUDOR sync settings from Magento's own system configuration
 * (Stores > Configuration > Catalog > TUDOR E-commerce Sync — see etc/adminhtml/system.xml),
 * store-view scoped so a multi-store-view install could sync different markets per view.
 */
class Config
{
    private const XML_PATH_COUNTRY = 'tudorsync/general/country';
    private const XML_PATH_ENVIRONMENT = 'tudorsync/general/environment';
    private const XML_PATH_CLIENT_ID = 'tudorsync/general/client_id_%s';
    private const XML_PATH_CLIENT_SECRET = 'tudorsync/general/client_secret_%s';
    private const XML_PATH_CLICK_AND_COLLECT_ENABLED = 'tudorsync/general/click_and_collect_enabled';
    private const XML_PATH_HOME_DELIVERY_TIMING = 'tudorsync/general/home_delivery_timing';
    private const XML_PATH_VALUE_MODE = 'tudorsync/general/value_mode';
    private const XML_PATH_LOCALE_FORMAT = 'tudorsync/urls/locale_format';
    private const XML_PATH_SKU_PREFIX = 'tudorsync/model_code/sku_prefix';
    private const XML_PATH_SKU_PATTERN = 'tudorsync/model_code/sku_pattern';
    private const XML_PATH_SKU_REPLACEMENT = 'tudorsync/model_code/sku_replacement';
    private const XML_PATH_REALTIME_ENABLED = 'tudorsync/general/realtime_enabled';
    private const XML_PATH_STORE_NAME = 'general/store_information/name';
    private const XML_PATH_LOCALE_CODE = 'general/locale/code';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getClientConfig(?int $storeId = null): ClientConfig
    {
        $environment = $this->getEnvironment($storeId);

        return new ClientConfig(
            clientName: $this->getString(self::XML_PATH_STORE_NAME, $storeId),
            market: $this->getCountry($storeId),
            languages: [], // not used by the Magento connector: locales come from the store views
            environment: $environment,
            // TUDOR authenticates with OAuth2 client credentials (getClientId()/getClientSecret()),
            // which core's ClientConfig doesn't take yet — pending in core, coordinate with Jorge.
            tudorApiKey: '',
            offersClickAndCollect: $this->scopeConfig->isSetFlag(self::XML_PATH_CLICK_AND_COLLECT_ENABLED, ScopeInterface::SCOPE_STORE, $storeId),
        );
    }

    public function getEnvironment(?int $storeId = null): Environment
    {
        return Environment::tryFrom($this->getString(self::XML_PATH_ENVIRONMENT, $storeId)) ?? Environment::Staging;
    }

    /**
     * Client ID of the retailer's Okta application for the given environment (not a secret).
     */
    public function getClientId(Environment $environment, ?int $storeId = null): string
    {
        return $this->getString(sprintf(self::XML_PATH_CLIENT_ID, $environment->value), $storeId);
    }

    /**
     * Client secret for the given environment, already decrypted: etc/config.xml declares the
     * field as Encrypted, so ScopeConfig hands back the plain value.
     */
    public function getClientSecret(Environment $environment, ?int $storeId = null): string
    {
        return $this->getString(sprintf(self::XML_PATH_CLIENT_SECRET, $environment->value), $storeId);
    }

    /**
     * Republish a TUDOR model code within a minute of a sale or stock change (Model\Realtime),
     * on top of the scheduled full sync.
     */
    public function isRealtimeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REALTIME_ENABLED);
    }

    public function hasCredentials(?int $storeId = null): bool
    {
        $environment = $this->getEnvironment($storeId);

        return $this->getClientId($environment, $storeId) !== ''
            && $this->getClientSecret($environment, $storeId) !== '';
    }

    /**
     * ISO 3166-1 alpha-2 market code, upper-cased; empty string when not configured.
     */
    public function getCountry(?int $storeId = null): string
    {
        return strtoupper($this->getString(self::XML_PATH_COUNTRY, $storeId));
    }

    public function getHomeDeliveryTimingHours(?int $storeId = null): ?int
    {
        $value = $this->getString(self::XML_PATH_HOME_DELIVERY_TIMING, $storeId);

        return ctype_digit($value) ? (int) $value : null;
    }

    public function getValueMode(?int $storeId = null): string
    {
        return $this->getString(self::XML_PATH_VALUE_MODE, $storeId) ?: ValueMode::QUANTITY;
    }

    /**
     * The store view's Magento locale (e.g. "en_GB") in the form TUDOR's localizedUrls keys
     * use: BCP 47 with a hyphen ("en-GB") or the bare language ("en"), per admin config.
     */
    public function getLocaleCode(int $storeId): ?string
    {
        $locale = $this->getString(self::XML_PATH_LOCALE_CODE, $storeId);

        if ($locale === '') {
            return null;
        }

        [$language, $region] = array_pad(explode('_', $locale, 2), 2, '');
        $language = strtolower($language);

        if ($region === '' || $this->getString(self::XML_PATH_LOCALE_FORMAT) === LocaleFormat::LANGUAGE) {
            return $language;
        }

        return $language . '-' . strtoupper($region);
    }

    /**
     * Optional SQL pre-filter: products whose SKU starts with this prefix are candidates even
     * when `tudor_model_code` is empty (their code is then derived with getSkuPattern()).
     */
    public function getSkuPrefix(): string
    {
        return $this->getString(self::XML_PATH_SKU_PREFIX);
    }

    public function getSkuPattern(): string
    {
        return $this->getString(self::XML_PATH_SKU_PATTERN);
    }

    public function getSkuReplacement(): string
    {
        return $this->getString(self::XML_PATH_SKU_REPLACEMENT);
    }

    private function getString(string $path, ?int $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
