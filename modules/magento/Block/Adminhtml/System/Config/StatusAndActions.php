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
 * Under the last sync go the watches core's review left out of it and its warnings.
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
        $reviewHtml = $this->renderReview($this->status->getLastSyncReview());
        $jsConfig = json_encode([
            'testUrl' => $this->getUrl('tudorsync/sync/test'),
            'runUrl' => $this->getUrl('tudorsync/sync/run'),
            'running' => (string) __('Running...'),
            'failed' => (string) __('Request failed.'),
            'reviewTitles' => [
                'exclusions' => (string) __('Left out by the review (not sent):'),
                'warnings' => (string) __('Review warnings:'),
            ],
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $script = <<<JS
require(['jquery'], function ($) {
    var config = {$jsConfig};

    function renderReview(review) {
        var \$box = $('#tudorsync-last-sync-review').empty();
        ['exclusions', 'warnings'].forEach(function (key) {
            var \$list = $('<ul></ul>');
            if (!review[key] || !review[key].length) {
                return;
            }
            review[key].forEach(function (line) {
                \$list.append($('<li></li>').text(line));
            });
            \$box.append($('<p></p>').append($('<strong></strong>').text(config.reviewTitles[key])), \$list);
        });
    }

    function run(url, resultSelector, button) {
        var \$btn = $(button);
        var \$result = $(resultSelector);
        \$btn.prop('disabled', true);
        \$result.text(config.running);
        $.post(url, {form_key: window.FORM_KEY})
            .done(function (response) {
                \$result.text(response.message || config.failed);
                if (response.review) {
                    renderReview(response.review);
                }
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
    <div id="tudorsync-last-sync-review">{$reviewHtml}</div>
    <button type="button" class="action-default" id="tudorsync-test-btn">{$labels['test']}</button>
    <button type="button" class="action-default" id="tudorsync-run-btn" style="margin-left: 10px;">{$labels['run']}</button>
    <p class="note"><span>{$labels['hint']}</span></p>
</div>
HTML . $this->scriptRenderer->renderTag('script', [], $script, false);
    }

    /**
     * The review of the last sync as HTML (after a click, the script above draws the same from
     * the JSON of Controller\Adminhtml\Sync\Run).
     *
     * @param array{exclusions: list<string>, warnings: list<string>} $review
     */
    private function renderReview(array $review): string
    {
        $escaper = $this->_escaper;
        $html = '';
        $sections = [
            'exclusions' => __('Left out by the review (not sent):'),
            'warnings' => __('Review warnings:'),
        ];

        foreach ($sections as $key => $title) {
            if ($review[$key] === []) {
                continue;
            }

            $html .= '<p><strong>' . $escaper->escapeHtml($title) . '</strong></p><ul>';

            foreach ($review[$key] as $line) {
                $html .= '<li>' . $escaper->escapeHtml($line) . '</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }
}
