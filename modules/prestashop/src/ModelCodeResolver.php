<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop;

/**
 * Derives the TUDOR model code (API field `mc`) from a product's reference, with the rule set in
 * the module config (regex + replacement, empty by default). Some retailers already keep the
 * TUDOR reference there, in their own shape (e.g. `79470-0004` or `7943A1A0NU-002` for
 * `M79470-0004` / `M7943A1A0NU-0002`); the rule turns it into TUDOR's.
 *
 * Only used by ModelCodeFiller to fill the "TUDOR Model Code" Feature, which stays the single
 * source the connector reads: every code can be seen and corrected on the product page.
 */
final class ModelCodeResolver
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function fromReference(string $reference): ?string
    {
        $pattern = $this->config->getReferencePattern();
        $reference = trim($reference);

        if ($reference === '' || !$this->isRuleConfigured() || preg_match($pattern, $reference) !== 1) {
            return null;
        }

        $code = strtoupper(trim((string) preg_replace($pattern, $this->config->getReferenceReplacement(), $reference)));

        return $code !== '' ? $code : null;
    }

    public function isRuleConfigured(): bool
    {
        $pattern = $this->config->getReferencePattern();

        return $pattern !== '' && self::isValidPattern($pattern);
    }

    public static function isValidPattern(string $pattern): bool
    {
        // An invalid regex typed in the config must not raise a PHP warning on every product.
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
