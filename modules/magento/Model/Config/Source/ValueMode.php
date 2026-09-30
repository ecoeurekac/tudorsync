<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * What number goes in the API's `value` field. The spec doesn't say whether TUDOR wants the
 * real quantity or just a positive signal, so both are offered until that's confirmed.
 */
class ValueMode implements OptionSourceInterface
{
    public const QUANTITY = 'quantity';
    public const SIGNAL = 'signal';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::QUANTITY, 'label' => __('Salable quantity')],
            ['value' => self::SIGNAL, 'label' => __('Always 1 (available signal only)')],
        ];
    }
}
