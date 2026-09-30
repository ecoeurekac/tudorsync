<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Tudorsync\EcommerceSync\Model\Status;

/**
 * Renders the "Test Connection" / "Run Sync Now" buttons and the last recorded result of
 * each, inline in Stores > Configuration > TUDOR E-commerce Sync. Both buttons AJAX-post to
 * Controller\Adminhtml\Sync\{Test,Run} and swap the response's message straight into the
 * page — Model\Status also persists the outcome so it's still visible after a page reload.
 *
 * The script goes through SecureHtmlRenderer so it carries the CSP nonce Magento 2.4.7+
 * expects for inline scripts.
 */
class StatusAndActions extends Field
{
    protected $_template = false;

    public function __construct(
        Context $context,
        private readonly Status $status,
        private readonly SecureHtmlRenderer $scriptRenderer,
        array $data = [],
    ) {
        parent::__construct($context, $data, $scriptRenderer);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $escaper = $this->_escaper;
        $never = (string) __('Never run.');
        $lastTest = $escaper->escapeHtml($this->status->getLastTestSummary() ?: $never);
        $lastSync = $escaper->escapeHtml($this->status->getLastSyncSummary() ?: $never);
        $labels = [
            'lastTest' => $escaper->escapeHtml(__('Last connection test:')),
            'lastSync' => $escaper->escapeHtml(__('Last sync:')),
            'test' => $escaper->escapeHtml(__('Test Connection')),
            'run' => $escaper->escapeHtml(__('Run Sync Now')),
            'hint' => $escaper->escapeHtml(__('Save the configuration before testing: the buttons use the saved values.')),
        ];
        $jsConfig = json_encode([
            'testUrl' => $this->getUrl('tudorsync/sync/test'),
            'runUrl' => $this->getUrl('tudorsync/sync/run'),
            'running' => (string) __('Running...'),
            'failed' => (string) __('Request failed.'),
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $script = <<<JS
require(['jquery'], function ($) {
    var config = {$jsConfig};

    function run(url, resultSelector, button) {
        var \$btn = $(button);
        var \$result = $(resultSelector);
        \$btn.prop('disabled', true);
        \$result.text(config.running);
        $.post(url, {form_key: window.FORM_KEY})
            .done(function (response) {
                \$result.text(response.message || config.failed);
            })
            .fail(function () {
                \$result.text(config.failed);
            })
            .always(function () {
                \$btn.prop('disabled', false);
            });
    }

    $('#tudorsync-test-btn').on('click', function () {
        run(config.testUrl, '#tudorsync-last-test', this);
    });
    $('#tudorsync-run-btn').on('click', function () {
        run(config.runUrl, '#tudorsync-last-sync', this);
    });
});
JS;

        return <<<HTML
<div>
    <p><strong>{$labels['lastTest']}</strong> <span id="tudorsync-last-test">{$lastTest}</span></p>
    <p><strong>{$labels['lastSync']}</strong> <span id="tudorsync-last-sync">{$lastSync}</span></p>
    <button type="button" class="action-default" id="tudorsync-test-btn">{$labels['test']}</button>
    <button type="button" class="action-default" id="tudorsync-run-btn" style="margin-left: 10px;">{$labels['run']}</button>
    <p class="note"><span>{$labels['hint']}</span></p>
</div>
HTML . $this->scriptRenderer->renderTag('script', [], $script, false);
    }
}
