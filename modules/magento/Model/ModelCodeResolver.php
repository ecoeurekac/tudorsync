<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

/**
 * Works out the TUDOR model code (API field `mc`) for one product:
 *   1. the `tudor_model_code` attribute, when filled in — always wins;
 *   2. otherwise, if a SKU rule is configured (regex + replacement in admin config), the code
 *      derived from the SKU. This exists because some retailers already embed the TUDOR
 *      reference in their SKU (Quera: `1001TU…`), which avoids re-typing it product by
 *      product and survives a data re-migration that would wipe a hand-filled attribute.
 */
class ModelCodeResolver
{
    public const ATTRIBUTE_CODE = 'tudor_model_code';

    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function resolve(string $attributeValue, string $sku): ?string
    {
        $attributeValue = trim($attributeValue);

        if ($attributeValue !== '') {
            return $attributeValue;
        }

        $pattern = $this->config->getSkuPattern();

        if ($pattern === '' || !$this->isValidPattern($pattern)) {
            return null;
        }

        if (preg_match($pattern, $sku) !== 1) {
            return null;
        }

        $code = trim((string) preg_replace($pattern, $this->config->getSkuReplacement(), $sku));

        return $code !== '' ? $code : null;
    }

    public function isSkuRuleConfigured(): bool
    {
        $pattern = $this->config->getSkuPattern();

        return $pattern !== '' && $this->isValidPattern($pattern);
    }

    public function isValidPattern(string $pattern): bool
    {
        // An invalid regex typed in admin config must not raise a PHP warning on every run.
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
