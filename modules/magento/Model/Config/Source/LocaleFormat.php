<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How each store view's locale is written as a key of TUDOR's `localizedUrls`. The API spec
 * shows both forms ("en", "fr-CH"); which one TUDOR matches against is still unconfirmed.
 */
class LocaleFormat implements OptionSourceInterface
{
    public const LANGUAGE_REGION = 'language_region';
    public const LANGUAGE = 'language';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::LANGUAGE_REGION, 'label' => __('Language and region (en-GB, fr-FR)')],
            ['value' => self::LANGUAGE, 'label' => __('Language only (en, fr)')],
        ];
    }
}
