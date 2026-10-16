<?php

declare(strict_types=1);

use Tudorsync\Core\Domain\Environment;
use Tudorsync\Prestashop\Api\ApiTester;
use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;

/**
 * Advanced parameters > TUDOR API tests: one button per TUDOR endpoint (ApiTester) and, for each
 * test, what was sent, TUDOR's answer and every HTTP call made (secrets masked). Reads go to the
 * environment chosen here; POST tests always go to PREPROD. Every action posts back here, so
 * PrestaShop's admin token protects it.
 */
class AdminTudorsyncApiTestController extends ModuleAdminController
{
    /** @var array<string, mixed>|null */
    private ?array $lastRun = null;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->l('TUDOR API tests');
    }

    public function postProcess(): void
    {
        if (PHP_VERSION_ID >= 80100 && Tools::isSubmit('submit_tudorsync_api_test')) {
            $products = [];
            foreach ((array) Tools::getValue('products', []) as $product) {
                if (is_array($product) && !empty($product['product_id'])) {
                    $products[] = ['product_id' => (int) $product['product_id'], 'value' => isset($product['value']) ? (string) $product['value'] : null];
                }
            }

            try {
                $this->lastRun = $this->tester()->run((string) Tools::getValue('test'), [
                    'environment' => (string) Tools::getValue('environment'),
                    'page' => (int) Tools::getValue('stocks_page', 0),
                    'size' => (int) Tools::getValue('stocks_size', 100),
                    'scope' => (string) Tools::getValue('scope'),
                    'products' => $products,
                ]);
                if ($this->lastRun['success']) {
                    $this->confirmations[] = $this->lastRun['environment'] . ' · ' . $this->lastRun['summary'];
                } else {
                    $this->errors[] = $this->lastRun['environment'] . ' · ' . $this->lastRun['summary'];
                }
            } catch (Throwable $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        parent::postProcess();
    }

    public function initContent(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->errors[] = sprintf($this->l('Requires PHP 8.1 or later (this server runs %s).'), PHP_VERSION);
        } else {
            $this->content = $this->renderResult() . $this->renderReads() . $this->renderSends();
        }

        parent::initContent();
    }

    private function renderReads(): string
    {
        $config = new Config();
        $current = $config->getEnvironment();
        $selected = Environment::tryFrom((string) Tools::getValue('environment')) ?? $current;
        $options = '';
        foreach (Environment::cases() as $environment) {
            $options .= '<option value="' . $this->e($environment->value) . '"' . ($environment === $selected ? ' selected' : '') . '>'
                . $this->e(ApiTester::label($environment) . ($environment === $current ? ' (' . $this->l('module environment') . ')' : '')) . '</option>';
        }

        $form = '<form method="post" action="' . $this->e($this->url()) . '" class="form-inline">'
            . $this->e($this->l('Environment')) . ' <select name="environment" class="form-control">' . $options . '</select> '
            . $this->testButton(ApiTester::ACTION_HEALTH, 'GET /health') . ' '
            . $this->testButton(ApiTester::ACTION_POINT_OF_SALES, 'GET /v1/point-of-sales') . ' '
            . $this->e($this->l('Page')) . ' <input type="number" name="stocks_page" value="0" min="0" class="form-control" style="width:80px"> '
            . $this->e($this->l('Size')) . ' <input type="number" name="stocks_size" value="100" min="1" max="500" class="form-control" style="width:80px"> '
            . $this->testButton(ApiTester::ACTION_GET_STOCKS, 'GET /v1/stocks')
            . '<p class="help-block">' . $this->e($this->l('Reads change nothing at TUDOR, so they can go to PREPROD or PROD.')) . '</p></form>';

        return $this->panel($this->l('Read'), $form);
    }

    private function renderSends(): string
    {
        $query = trim((string) Tools::getValue('q'));
        $body = '<p class="alert alert-info">' . $this->e($this->l('POST tests always go to PREPROD, whatever the module environment: they never change what tudorwatch.com shows. Empty value = the product\'s stock; 0 with POST /v1/stocks withdraws the watch.')) . '</p>'
            . '<form method="get" action="index.php" class="form-inline">'
            . '<input type="hidden" name="controller" value="AdminTudorsyncApiTest"><input type="hidden" name="token" value="' . $this->e((string) Tools::getAdminTokenLite('AdminTudorsyncApiTest')) . '">'
            . '<input type="search" name="q" value="' . $this->e($query) . '" class="form-control" placeholder="' . $this->e($this->l('Reference, name or model code')) . '"> '
            . '<button type="submit" class="btn btn-default">' . $this->e($this->l('Search')) . '</button></form>';

        if (mb_strlen($query) >= 2) {
            $rows = '';
            foreach ((new CatalogConnector(new Config()))->search($query) as $index => $row) {
                $rows .= '<tr><td><input type="checkbox" name="products[' . $index . '][product_id]" value="' . $row['product_id'] . '"' . ($row['model_code'] === '' ? ' disabled' : '') . '></td>'
                    . '<td>' . $row['product_id'] . '</td><td>' . $this->e($row['reference']) . '</td><td>' . $this->e($row['name']) . '</td>'
                    . '<td>' . $this->e($row['model_code'] !== '' ? $row['model_code'] : '—') . '</td>'
                    . '<td>' . ($row['active'] ? $this->e($this->l('yes')) : $this->e($this->l('no'))) . '</td><td>' . $row['quantity'] . '</td>'
                    . '<td><input type="number" min="0" name="products[' . $index . '][value]" class="form-control" style="width:80px"></td></tr>';
            }

            $body .= '<form method="post" action="' . $this->e($this->url(['q' => $query])) . '" onsubmit="return confirm(\'' . $this->e(addslashes($this->l('Send to TUDOR PREPROD?'))) . '\')">'
                . ($rows === ''
                    ? '<p>' . $this->e($this->l('No product found.')) . '</p>'
                    : '<table class="table"><thead><tr><th></th><th>ID</th><th>' . $this->e($this->l('Reference')) . '</th><th>' . $this->e($this->l('Name')) . '</th><th>'
                        . $this->e($this->l('Model code')) . '</th><th>' . $this->e($this->l('Active')) . '</th><th>' . $this->e($this->l('Stock')) . '</th><th>'
                        . $this->e($this->l('Value to send')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
                        . $this->testButton(ApiTester::ACTION_CREATE_STOCK, 'POST /v1/stocks') . ' '
                        . $this->testButton(ApiTester::ACTION_BATCH, 'POST /v1/stocks/batch')
                        . '<p class="help-block">' . $this->e($this->l('A batch with only some products sets every other model to 0 in PREPROD.')) . '</p>')
                . '</form>';
        }

        $body .= '<h4>' . $this->e($this->l('Whole catalog')) . '</h4>'
            . '<form method="post" action="' . $this->e($this->url()) . '" onsubmit="return confirm(\'' . $this->e(addslashes($this->l('Send the whole catalog to TUDOR PREPROD?'))) . '\')">'
            . '<input type="hidden" name="scope" value="catalog">'
            . $this->testButton(ApiTester::ACTION_BATCH, $this->l('POST /v1/stocks/batch with the catalog, as the sync sends it')) . '</form>';

        return $this->panel($this->l('Send (PREPROD only)'), $body);
    }

    private function renderResult(): string
    {
        if ($this->lastRun === null) {
            return '';
        }

        $run = $this->lastRun;
        $html = '<p><strong>' . $this->e($run['environment']) . '</strong> · ' . $this->e($run['summary']) . '</p>';

        if ($run['review'] !== null) {
            $html .= '<h4>' . $this->e($this->l('Review')) . '</h4>' . $this->json($run['review']);
        }
        if ($run['payload'] !== null) {
            $html .= '<h4>' . $this->e($this->l('Sent')) . '</h4>' . $this->json($run['payload']);
        }
        $html .= '<h4>' . $this->e($this->l('TUDOR\'s answer')) . '</h4>' . $this->json($run['result']);

        if ($run['calls'] !== []) {
            $html .= '<h4>' . $this->e($this->l('HTTP calls made')) . '</h4>';
            foreach ($run['calls'] as $number => $call) {
                $html .= '<details' . ($number === count($run['calls']) - 1 ? ' open' : '') . '><summary><strong>'
                    . $this->e($call['method'] . ' ' . $call['endpoint']) . '</strong> · HTTP ' . $this->e((string) ($call['status_code'] ?? '—'))
                    . ' · ' . (int) $call['duration_ms'] . ' ms' . ($call['error'] !== null ? ' · <span class="text-danger">' . $this->e($call['error']) . '</span>' : '')
                    . '</summary><p><code>' . $this->e($call['url']) . '</code></p>'
                    . '<p>' . $this->e($this->l('Request headers')) . '</p>' . $this->json($call['request_headers'])
                    . ($call['request_body'] !== null ? '<p>' . $this->e($this->l('Request body')) . '</p>' . $this->raw($call['request_body']) : '')
                    . ($call['response_body'] !== null ? '<p>' . $this->e($this->l('Response body')) . '</p>' . $this->raw($call['response_body']) : '')
                    . '</details>';
            }
        }

        return $this->panel($this->l('Result'), $html);
    }

    private function testButton(string $action, string $label): string
    {
        return '<button type="submit" name="test" value="' . $this->e($action) . '" class="btn btn-default">' . $this->e($label) . '</button>'
            . '<input type="hidden" name="submit_tudorsync_api_test" value="1">';
    }

    private function tester(): ApiTester
    {
        $config = new Config();

        return new ApiTester($config, new CatalogConnector($config));
    }

    /**
     * @param array<string, int|string> $params
     */
    private function url(array $params = []): string
    {
        return $this->context->link->getAdminLink('AdminTudorsyncApiTest', true, [], $params);
    }

    private function raw(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return $this->json($decoded);
        }

        $lines = array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), preg_split('/\R/', trim($body)) ?: []), 'is_array');

        return $lines !== [] && count($lines) === count(preg_split('/\R/', trim($body)) ?: [])
            ? $this->json(array_values($lines)) // NDJSON
            : '<pre style="white-space:pre-wrap;max-height:400px;overflow:auto">' . $this->e(mb_substr($body, 0, 20000)) . '</pre>';
    }

    private function json(mixed $value): string
    {
        return '<pre style="white-space:pre-wrap;max-height:400px;overflow:auto">'
            . $this->e((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }

    private function panel(string $title, string $body): string
    {
        return '<div class="panel"><div class="panel-heading">' . $this->e($title) . '</div>' . $body . '</div>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
