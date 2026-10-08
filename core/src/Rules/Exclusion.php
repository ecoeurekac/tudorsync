<?php

declare(strict_types=1);

namespace Tudorsync\Core\Rules;

/**
 * One watch AvailabilityFilter left out of what gets sent to TUDOR, and why: a stable reason
 * code for the modules to filter or count on, plus a Spanish text to show as is.
 */
final class Exclusion
{
    public const MISSING_MODEL_CODE = 'missing_model_code';
    public const INVALID_COUNTRY = 'invalid_country';
    public const NOT_AVAILABLE = 'not_available';
    public const INVALID_URL = 'invalid_url';
    public const NOT_IN_VALID_LIST = 'not_in_valid_list';

    /**
     * @param string $modelCode The model code after normalization (may be empty).
     * @param string $country The country after normalization.
     * @param string $reason One of the constants above.
     * @param string $message Spanish text for the admin/log, e.g. "Enlace no válido: debe empezar por https://".
     */
    public function __construct(
        public readonly string $modelCode,
        public readonly string $country,
        public readonly string $reason,
        public readonly string $message,
    ) {
    }
}
