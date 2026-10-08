/**
 * TUDOR e-Stock API test page (Reports > TUDOR e-Stock > API tests).
 *
 * Product picker for the POST tests, one button per endpoint, and every response shown in full:
 * summary, parsed result and each HTTP call made (request and response, secrets already masked
 * by the server). Every run is also saved in the API log; each call links to its log entry.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    var SEARCH_DELAY_MS = 350;

    /**
     * JSON pretty-printed, NDJSON one pretty object per line, anything else as it is.
     */
    function pretty(body) {
        var lines, out = [];

        if (body === null || body === undefined || body === '') {
            return '';
        }

        if (typeof body !== 'string') {
            return JSON.stringify(body, null, 2);
        }

        try {
            return JSON.stringify(JSON.parse(body), null, 2);
        } catch (e) {
            lines = body.split('\n').filter(function (line) {
                return line.trim() !== '';
            });

            try {
                lines.forEach(function (line) {
                    out.push(JSON.stringify(JSON.parse(line), null, 2));
                });

                return out.join('\n');
            } catch (e2) {
                return body;
            }
        }
    }

    function pre(text) {
        return $('<pre class="tudorsync-pre"></pre>').text(text);
    }

    function isOk(status) {
        return status >= 200 && status < 300;
    }

    return function (config, element) {
        var $root = $(element),
            selected = [],
            searchTimer = null,
            busy = false;

        function post(test, params) {
            return $.ajax({
                url: config.runUrl,
                type: 'POST',
                dataType: 'json',
                data: {form_key: config.formKey, test: test, params: JSON.stringify(params || {})}
            });
        }

        /* ---------- product picker ---------- */

        function renderResults(products) {
            var $box = $root.find('#tudorsync-product-results').empty(),
                $table, $tbody;

            if (!products.length) {
                $box.append($('<p class="note"></p>').text($t('No TUDOR product matches.')));

                return;
            }

            $table = $('<table class="data-grid"><thead><tr></tr></thead><tbody></tbody></table>');
            [$t('SKU'), $t('Product'), $t('TUDOR model'), $t('Salable qty'), $t('Sync'), ''].forEach(function (label) {
                $table.find('thead tr').append($('<th class="data-grid-th"></th>').text(label));
            });
            $tbody = $table.find('tbody');

            products.forEach(function (product) {
                var $row = $('<tr class="data-row"></tr>'),
                    $choose = $('<button type="button" class="action-secondary"></button>').text($t('Choose'));

                $row.append($('<td></td>').text(product.sku));
                $row.append($('<td></td>').text(product.name));
                $row.append($('<td></td>').text(product.model_code || '—'));
                $row.append($('<td></td>').text(product.salable_qty === null ? '—' : product.salable_qty));
                $row.append($('<td></td>').text(product.excluded_reason ? $t('left out') + ': ' + product.excluded_reason : $t('sent')));

                if (product.model_code) {
                    $choose.on('click', function () {
                        addSelected(product);
                    });
                    $row.append($('<td></td>').append($choose));
                } else {
                    $row.append($('<td></td>'));
                }

                $tbody.append($row);
            });

            $box.append($table);
        }

        function search(query) {
            if (query.length < 2) {
                $root.find('#tudorsync-product-results').empty();

                return;
            }

            $.getJSON(config.searchUrl, {q: query}).done(function (data) {
                renderResults(data.products || []);
            });
        }

        function addSelected(product) {
            if (selected.some(function (item) {
                return item.product.product_id === product.product_id;
            })) {
                return;
            }

            selected.push({product: product, value: ''});
            renderSelected();
        }

        function preview(item, $target) {
            $target.empty().append($('<p class="note"></p>').text($t('Building the JSON...')));
            $.getJSON(config.previewUrl, {product_id: item.product.product_id, value: item.value}).done(function (data) {
                $target.empty();

                if (!data.success) {
                    $target.append($('<p class="tudorsync-warning"></p>').text(data.message));

                    return;
                }

                (data.review || []).forEach(function (line) {
                    $target.append($('<p class="tudorsync-warning"></p>').text($t('Would not be sent (left out by the review):') + ' ' + line));
                });
                (data.warnings || []).forEach(function (line) {
                    $target.append($('<p class="note"></p>').text($t('Review warning:') + ' ' + line));
                });
                $target.append($('<p class="note"></p>').text($t('JSON that POST /v1/stocks would send (nothing has been sent):')));
                $target.append(pre(pretty(data.payload)));
            });
        }

        function renderSelected() {
            var $box = $root.find('#tudorsync-selected'),
                $list = $root.find('#tudorsync-selected-list').empty();

            $box.prop('hidden', selected.length === 0);

            selected.forEach(function (item, index) {
                var $row = $('<div class="tudorsync-buttons"></div>'),
                    $value = $('<input type="number" min="0" class="admin__control-text">').val(item.value),
                    $previewBox = $('<div></div>'),
                    $previewBtn = $('<button type="button" class="action-secondary"></button>').text($t('See JSON')),
                    $remove = $('<button type="button" class="action-secondary"></button>').text($t('Remove'));

                $value.attr('placeholder', $t('value')).on('change keyup', function () {
                    item.value = $value.val();
                });
                $previewBtn.on('click', function () {
                    preview(item, $previewBox);
                });
                $remove.on('click', function () {
                    selected.splice(index, 1);
                    renderSelected();
                });

                $row.append($('<strong></strong>').text((index === 0 ? '① ' : '') + item.product.model_code))
                    .append($('<span></span>').text(item.product.sku + ' · ' + item.product.name))
                    .append($value, $previewBtn, $remove);
                $list.append($row, $previewBox);
            });

            updateBatchButton();
        }

        /* ---------- running tests ---------- */

        /**
         * What core's review left out before sending, and its warnings, above the raw result.
         */
        function renderReview($run, review) {
            var $list;

            $run.append($('<p class="note"></p>').text(
                $t('Review before sending (tudorsync/core): %1 received, %2 pass.')
                    .replace('%1', review.received).replace('%2', review.passed)
            ));

            if (review.exclusions.length) {
                $list = $('<ul class="tudorsync-warning"></ul>');
                review.exclusions.forEach(function (exclusion) {
                    $list.append($('<li></li>').text((exclusion.mc || '—') + ' (' + exclusion.country + '): ' + exclusion.message));
                });
                $run.append($('<strong></strong>').text($t('Left out by the review (not sent):')), $list);
            }

            if (review.warnings.length) {
                $list = $('<ul></ul>');
                review.warnings.forEach(function (warning) {
                    $list.append($('<li></li>').text(warning));
                });
                $run.append($('<strong></strong>').text($t('Review warnings:')), $list);
            }
        }

        function renderRun(label, data) {
            var $run = $('<div class="tudorsync-run"></div>').toggleClass('is-error', !data.success),
                time = new Date().toLocaleTimeString();

            $run.append($('<h3></h3>').text(label + ' · ' + time));
            $run.append($('<p></p>').append(
                $('<span></span>').addClass(data.success ? 'tudorsync-status-ok' : 'tudorsync-status-error')
                    .text(data.success ? $t('OK') : $t('Error')),
                document.createTextNode(' · ' + (data.summary || ''))
            ));

            if (data.result && data.result.review) {
                renderReview($run, data.result.review);
            }

            if (data.result !== null && data.result !== undefined) {
                $run.append($('<details></details>').append(
                    $('<summary></summary>').text($t('Result read by Tudorsync')),
                    pre(pretty(data.result))
                ));
            }

            (data.calls || []).forEach(function (call) {
                var status = call.status_code === null ? $t('no response') : call.status_code,
                    $details = $('<details class="tudorsync-call"></details>'),
                    $summary = $('<summary></summary>');

                $summary.append(
                    $('<span></span>').addClass(isOk(+call.status_code) ? 'tudorsync-status-ok' : 'tudorsync-status-error')
                        .text(call.method + ' ' + call.endpoint + ' → ' + status),
                    document.createTextNode(' · ' + call.duration_ms + ' ms · ')
                );
                $summary.append($('<a target="_blank"></a>').attr('href', config.logViewUrl.replace('__ID__', call.log_id))
                    .text($t('log') + ' #' + call.log_id));
                $details.append($summary);
                $details.append($('<p></p>').text(call.method + ' ' + call.url));
                $details.append($('<strong></strong>').text($t('Request headers')), pre(pretty(call.request_headers)));

                if (call.request_body) {
                    $details.append($('<strong></strong>').text($t('Request body')), pre(pretty(call.request_body)));
                }

                if (call.error) {
                    $details.append($('<strong></strong>').text($t('Error')), pre(call.error));
                }

                $details.append($('<strong></strong>').text($t('Response body')), pre(pretty(call.response_body)));
                // The API call itself (not the token request) opens by default.
                $details.prop('open', call.endpoint !== 'token');
                $run.append($details);
            });

            $root.find('#tudorsync-runs').find('> p.note').remove();
            $root.find('#tudorsync-runs').prepend($run);
        }

        function paramsFor(test) {
            var products = selected.map(function (item) {
                return {product_id: item.product.product_id, value: item.value};
            });

            switch (test) {
                case 'get_stocks':
                    return {
                        page: $root.find('#tudorsync-stocks-page').val(),
                        size: $root.find('#tudorsync-stocks-size').val()
                    };
                case 'create_stock':
                    return {products: products.slice(0, 1)};
                case 'batch':
                    return {
                        scope: $root.find('input[name="tudorsync-batch-scope"]:checked').val(),
                        products: products
                    };
                default:
                    return {};
            }
        }

        function run(test, $button) {
            var label = $button.text().trim();

            if (busy) {
                return;
            }

            busy = true;
            $root.find('button[data-test]').addClass('disabled');
            $button.find('span').text(label + ' — ' + $t('sending...'));

            post(test, paramsFor(test)).done(function (data) {
                renderRun(label, data);
            }).fail(function (xhr) {
                renderRun(label, {success: false, summary: $t('The request failed') + ' (HTTP ' + xhr.status + ')', calls: []});
            }).always(function () {
                busy = false;
                $root.find('button[data-test]').removeClass('disabled');
                $button.find('span').text(label);

                if (test === 'batch') {
                    $root.find('#tudorsync-batch-confirm').prop('checked', false);
                    updateBatchButton();
                }
            });
        }

        function updateBatchButton() {
            var scope = $root.find('input[name="tudorsync-batch-scope"]:checked').val(),
                ready = config.postAllowed
                    && $root.find('#tudorsync-batch-confirm').is(':checked')
                    && (scope === 'catalog' || selected.length > 0);

            $root.find('button[data-test="batch"]').prop('disabled', !ready);
        }

        /* ---------- wiring ---------- */

        $root.on('click', 'button[data-test]', function () {
            var $button = $(this),
                test = $button.data('test');

            if (test === 'create_stock' && !selected.length) {
                renderRun('POST /v1/stocks', {success: false, summary: $t('Choose a product first.'), calls: []});

                return;
            }

            run(test, $button);
        });

        $root.find('#tudorsync-product-search').on('input', function () {
            var query = $(this).val().trim();

            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                search(query);
            }, SEARCH_DELAY_MS);
        });

        $root.on('change', '#tudorsync-batch-confirm, input[name="tudorsync-batch-scope"]', updateBatchButton);
        updateBatchButton();
    };
});
