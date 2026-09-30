<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Tudorsync\Core\Domain\Environment as TudorEnvironment;

/**
 * Options for the "TUDOR API Environment" admin config field (Stores > Configuration >
 * TUDOR E-commerce Sync). Values match Tudorsync\Core\Domain\Environment exactly so the
 * saved config value can be passed straight to Environment::from().
 */
class Environment implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => TudorEnvironment::Staging->value, 'label' => __('Staging')],
            ['value' => TudorEnvironment::Production->value, 'label' => __('Production')],
        ];
    }
}
