<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Cookie consent banner that gates the tudorsync_utm attribution cookie. With none, the cookie
 * is written on landing; with a consent manager, only once the visitor accepts its category.
 */
class ConsentManager implements OptionSourceInterface
{
    public const NONE = 'none';
    public const COOKIESCRIPT = 'cookiescript';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::NONE, 'label' => __('None (write the cookie without asking)')],
            ['value' => self::COOKIESCRIPT, 'label' => __('CookieScript')],
        ];
    }
}
