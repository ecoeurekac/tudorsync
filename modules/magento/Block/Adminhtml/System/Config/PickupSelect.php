<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\System\Config;

use Magento\Framework\View\Element\Html\Select;

/**
 * Yes/No cell of PointsOfSale. AbstractFieldArray hands a renderer its field name through
 * setInputName(), but Select writes name="" from getName(): without this the value is never
 * posted and every row was saved without it.
 */
class PickupSelect extends Select
{
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions([
                ['value' => '1', 'label' => (string) __('Yes')],
                ['value' => '0', 'label' => (string) __('No')],
            ]);
        }

        return parent::_toHtml();
    }
}
