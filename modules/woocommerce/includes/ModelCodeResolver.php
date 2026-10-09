<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Core\Rules\ValidModelList;

/**
 * Works out the TUDOR model code (API field `mc`) of one product:
 *   1. the `_tudor_model_code` post meta, when filled in — always wins;
 *   2. otherwise, if a SKU rule is configured (a regex with delimiters + a replacement using
 *      $1, $2...), the code derived from the SKU. Both are empty by default.
 *
 * The SKU prefix setting is not checked here: it only narrows which products are read at all
 * (see CatalogConnector). Codes come out normalized as core does (no spaces, upper case), so
 * the real-time queue and the review use the same code.
 */
final class ModelCodeResolver
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{code: string, source: string}|null source is "meta" or "sku"
     */
    public function resolveWithSource(string $metaValue, string $sku): ?array
    {
        $metaValue = ValidModelList::normalize($metaValue);

        if ($metaValue !== '') {
            return ['code' => $metaValue, 'source' => 'meta'];
        }

        $code = $this->fromSku($sku);

        return $code === null ? null : ['code' => $code, 'source' => 'sku'];
    }

    public function resolve(string $metaValue, string $sku): ?string
    {
        return $this->resolveWithSource($metaValue, $sku)['code'] ?? null;
    }

    public function resolveProduct(\WC_Product $product): ?string
    {
        return $this->resolve((string) $product->get_meta(Config::META_MODEL_CODE), (string) $product->get_sku());
    }

    public function fromSku(string $sku): ?string
    {
        $pattern = $this->config->getSkuPattern();
        $sku = trim($sku);

        if ($sku === '' || !$this->isSkuRuleConfigured() || preg_match($pattern, $sku) !== 1) {
            return null;
        }

        $code = ValidModelList::normalize((string) preg_replace($pattern, $this->config->getSkuReplacement(), $sku));

        return $code !== '' ? $code : null;
    }

    public function isSkuRuleConfigured(): bool
    {
        $pattern = $this->config->getSkuPattern();

        return $pattern !== '' && self::isValidPattern($pattern);
    }

    public static function isValidPattern(string $pattern): bool
    {
        // An invalid regex typed in the settings must not raise a PHP warning on every run.
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
