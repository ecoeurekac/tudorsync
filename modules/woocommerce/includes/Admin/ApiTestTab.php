<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Tudorsync\Woocommerce\Api\ApiTester;
use Tudorsync\Woocommerce\Plugin;

/**
 * "API tests": one button per TUDOR endpoint (ApiTester). POST tests can't be run in PROD.
 */
final class ApiTestTab
{
    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function render(): void
    {
        $tester = $this->plugin->apiTester();
        $production = $tester->isProduction();
        $query = isset($_GET['q']) && is_string($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $this->renderLastResult();

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Read', 'tudorsync') . '</h2>';
        $this->button(ApiTester::ACTION_HEALTH, 'GET /health');
        $this->button(ApiTester::ACTION_POINT_OF_SALES, 'GET /v1/point-of-sales');
        View::formStart('tudorsync_api_test', 'class="tudorsync-inline-form"');
        printf('<input type="hidden" name="test" value="%s">', esc_attr(ApiTester::ACTION_GET_STOCKS));
        printf(
            '%s <input type="number" name="page" value="0" min="0" class="small-text"> %s <input type="number" name="size" value="100" min="1" max="500" class="small-text"> ',
            esc_html__('Page', 'tudorsync'),
            esc_html__('Size', 'tudorsync'),
        );
        submit_button('GET /v1/stocks', 'secondary', 'submit', false);
        echo '</form></div>';

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Send', 'tudorsync') . '</h2>';

        if ($production) {
            echo '<p class="tudorsync-error">' . esc_html__('POST tests are disabled in the production environment: they would change what tudorwatch.com shows.', 'tudorsync') . '</p></div>';

            return;
        }

        echo '<p>' . esc_html__('Find TUDOR products, choose them and, optionally, the value to send (empty = their salable stock; 0 with POST /v1/stocks withdraws the watch).', 'tudorsync') . '</p>';
        printf(
            '<form method="get" action="%s" class="tudorsync-filters"><input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="api-test"><input type="search" name="q" value="%s" placeholder="%s"> ',
            esc_url(admin_url('admin.php')),
            esc_attr(Admin::PAGE),
            esc_attr($query),
            esc_attr__('SKU, name or model code', 'tudorsync'),
        );
        submit_button(__('Search', 'tudorsync'), 'secondary', '', false);
        echo '</form>';

        if (mb_strlen($query) >= 2) {
            $results = $this->plugin->catalogConnector()->search($query);
            View::formStart('tudorsync_api_test', 'onsubmit="return confirm(\'' . esc_js(__('Send to TUDOR PREPROD?', 'tudorsync')) . '\')"');
            View::rawTable(
                ['', __('ID', 'tudorsync'), __('SKU', 'tudorsync'), __('Name', 'tudorsync'), __('Model code', 'tudorsync'), __('Salable', 'tudorsync'), __('Value to send', 'tudorsync')],
                array_map(static fn (array $row, int $index): array => [
                    sprintf('<input type="checkbox" name="products[%d][product_id]" value="%d"%s>', $index, $row['product_id'], $row['model_code'] === '' ? ' disabled' : ''),
                    esc_html((string) $row['product_id']),
                    esc_html($row['sku']),
                    esc_html($row['name']),
                    esc_html($row['model_code'] !== '' ? $row['model_code'] : '—'),
                    esc_html((string) $row['salable_qty']),
                    sprintf('<input type="number" min="0" name="products[%d][value]" class="small-text">', $index),
                ], $results, array_keys($results)),
                __('No product found.', 'tudorsync'),
            );
            printf('<button type="submit" name="test" value="%s" class="button">POST /v1/stocks</button> ', esc_attr(ApiTester::ACTION_CREATE_STOCK));
            printf('<button type="submit" name="test" value="%s" class="button">POST /v1/stocks/batch</button>', esc_attr(ApiTester::ACTION_BATCH));
            echo '<p class="description">' . esc_html__('A batch with only some products sets every other model to 0 in PREPROD.', 'tudorsync') . '</p>';
            echo '</form>';
        }

        echo '<h3>' . esc_html__('Whole catalog', 'tudorsync') . '</h3>';
        View::formStart('tudorsync_api_test', 'onsubmit="return confirm(\'' . esc_js(__('Send the whole catalog to TUDOR PREPROD?', 'tudorsync')) . '\')"');
        printf('<input type="hidden" name="test" value="%s"><input type="hidden" name="scope" value="catalog">', esc_attr(ApiTester::ACTION_BATCH));
        submit_button(__('POST /v1/stocks/batch with the catalog, as the sync sends it', 'tudorsync'), 'secondary', 'submit', false);
        echo '</form></div>';
    }

    private function renderLastResult(): void
    {
        $details = Admin::takeNoticeDetails();

        if (!is_array($details) || !array_key_exists('result', $details)) {
            return;
        }

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Result', 'tudorsync') . '</h2>';
        View::json($details['result']);

        if (!empty($details['calls'])) {
            echo '<h3>' . esc_html__('Calls made', 'tudorsync') . '</h3>';
            View::rawTable(
                [__('Log', 'tudorsync'), __('Call', 'tudorsync'), __('HTTP', 'tudorsync'), __('Time', 'tudorsync'), __('Error', 'tudorsync')],
                array_map(static fn (array $call): array => [
                    sprintf('<a href="%s">#%d</a>', esc_url(View::url('api-log', ['log' => (int) $call['log_id']])), (int) $call['log_id']),
                    esc_html($call['method'] . ' ' . $call['endpoint']),
                    esc_html((string) ($call['status_code'] ?? '—')),
                    esc_html($call['duration_ms'] . ' ms'),
                    esc_html((string) ($call['error'] ?? '')),
                ], $details['calls']),
            );
        }

        echo '</div>';
    }

    private function button(string $action, string $label): void
    {
        View::formStart('tudorsync_api_test', 'class="tudorsync-inline-form"');
        printf('<input type="hidden" name="test" value="%s">', esc_attr($action));
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    public function handleRun(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- Admin::guard()
        $action = sanitize_key((string) ($_POST['test'] ?? ''));
        $products = [];

        foreach ((array) ($_POST['products'] ?? []) as $product) {
            if (is_array($product) && !empty($product['product_id'])) {
                $products[] = [
                    'product_id' => (int) $product['product_id'],
                    'value' => isset($product['value']) && $product['value'] !== '' ? (int) $product['value'] : null,
                ];
            }
        }

        $params = [
            'page' => (int) ($_POST['page'] ?? 0),
            'size' => (int) ($_POST['size'] ?? 100),
            'scope' => sanitize_key((string) ($_POST['scope'] ?? '')),
            'products' => $products,
        ];
        // phpcs:enable

        try {
            $result = $this->plugin->apiTester()->run($action, $params);
        } catch (\Throwable $e) {
            Admin::redirect('api-test', false, $e->getMessage());
        }

        Admin::redirect('api-test', $result['success'], $result['summary'], [], $result);
    }
}
