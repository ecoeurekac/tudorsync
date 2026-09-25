<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Tudorsync\EcommerceSync\Model\Status;

/**
 * Renders the "Test Connection" / "Run Sync Now" buttons and the last recorded result of
 * each, inline in Stores > Configuration > TUDOR E-commerce Sync. Both buttons AJAX-post to
 * Controller\Adminhtml\Sync\{Test,Run} and swap the response's message straight into the
 * page — Model\Status also persists the outcome so it's still visible after a page reload.
 */
class StatusAndActions extends Field
{
    protected $_template = false;

    public function __construct(
        Context $context,
        private readonly Status $status,
        private readonly Escaper $escaper,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $testUrl = $this->getUrl('tudorsync/sync/test');
        $runUrl = $this->getUrl('tudorsync/sync/run');
        $lastTest = $this->escaper->escapeHtml($this->status->getLastTestSummary() ?: 'Never run.');
        $lastSync = $this->escaper->escapeHtml($this->status->getLastSyncSummary() ?: 'Never run.');

        return <<<HTML
<div>
    <p><strong>Last connection test:</strong> <span id="tudorsync-last-test">{$lastTest}</span></p>
    <p><strong>Last sync:</strong> <span id="tudorsync-last-sync">{$lastSync}</span></p>
    <button type="button" class="action-default" id="tudorsync-test-btn">Test Connection</button>
    <button type="button" class="action-default" id="tudorsync-run-btn" style="margin-left: 10px;">Run Sync Now</button>
</div>
<script>
require(['jquery'], function (\$) {
    function run(url, resultSelector, button) {
        var \$btn = \$(button);
        var \$result = \$(resultSelector);
        \$btn.prop('disabled', true);
        \$result.text('Running...');
        \$.post(url, {form_key: window.FORM_KEY})
            .done(function (response) {
                \$result.text(response.message);
            })
            .fail(function () {
                \$result.text('Request failed.');
            })
            .always(function () {
                \$btn.prop('disabled', false);
            });
    }

    \$('#tudorsync-test-btn').on('click', function () {
        run('{$testUrl}', '#tudorsync-last-test', this);
    });
    \$('#tudorsync-run-btn').on('click', function () {
        run('{$runUrl}', '#tudorsync-last-sync', this);
    });
});
</script>
HTML;
    }
}
