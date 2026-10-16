<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\BlockInterface;

/**
 * Rows of Stores > Configuration > TUDOR E-commerce Sync > General > Click & Collect per Point
 * of Sale: one per TUDOR point of sale (RSWI id = stoId of GET /v1/point-of-sales), saying
 * whether it offers click & collect and in how many hours (empty = the default timing).
 * Read back by Model\Config::getPointsOfSale().
 */
class PointsOfSale extends AbstractFieldArray
{
    private ?BlockInterface $pickupRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn('sto_id', ['label' => __('Point of Sale (RSWI id)'), 'class' => 'required-entry']);
        $this->addColumn('pickup', ['label' => __('Click & Collect'), 'renderer' => $this->getPickupRenderer()]);
        $this->addColumn('timing', ['label' => __('Timing (hours)'), 'class' => 'validate-digits']);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add Point of Sale');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $pickup = (string) ($row->getData('pickup') ?? '1');
        $row->setData('option_extra_attrs', [
            'option_' . $this->getPickupRenderer()->calcOptionHash($pickup) => 'selected="selected"',
        ]);
    }

    private function getPickupRenderer(): BlockInterface
    {
        if ($this->pickupRenderer === null) {
            /** @var \Magento\Framework\View\Element\Html\Select $select */
            $select = $this->getLayout()->createBlock(
                \Magento\Framework\View\Element\Html\Select::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $select->setOptions([
                ['value' => '1', 'label' => (string) __('Yes')],
                ['value' => '0', 'label' => (string) __('No')],
            ]);
            $select->setData('input_name', $this->_getCellInputElementName('pickup'));
            $select->setData('input_id', $this->_getCellInputElementId('<%- _id %>', 'pickup'));
            $select->setData('extra_params', 'style="width:80px"');
            $this->pickupRenderer = $select;
        }

        return $this->pickupRenderer;
    }
}
