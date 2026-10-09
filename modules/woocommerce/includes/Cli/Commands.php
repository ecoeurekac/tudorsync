<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Cli;

use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Woocommerce\Catalog\CatalogSnapshot;
use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\Plugin;
use Tudorsync\Woocommerce\Report\ProgrammeSalesSource;
use WP_CLI;

/**
 * WP-CLI commands, all under `wp tudorsync`:
 *
 *     wp tudorsync catalog preview [--all] [--json-file=<file>]   dry run: what would be sent, and why not the rest
 *     wp tudorsync sync run                                  full sync now
 *     wp tudorsync connection test                           GET /v1/point-of-sales
 *     wp tudorsync realtime publish [--send]                 queued changes: plan, or send
 *     wp tudorsync model-code fill [--apply] [--overwrite] [--all]
 *     wp tudorsync report programme-sales [--month=<YYYY-MM>] [--all-orders]
 *     wp tudorsync api-log clean [--days=<n>]
 */
final class Commands
{
    /** TUDOR model code (TMC) as found in image texts: M + 4-5 digits + letters/digits + -NNNN. */
    private const TMC_IN_TEXT = '/(?<![A-Z0-9])M\d{4,5}[A-Z0-9]*-\d{4}(?![0-9])/i';

    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public static function register(Plugin $plugin): void
    {
        $commands = new self($plugin);

        WP_CLI::add_command('tudorsync catalog preview', [$commands, 'catalogPreview'], [
            'shortdesc' => 'Shows what the next sync would send to TUDOR, without calling TUDOR.',
            'synopsis' => [
                ['type' => 'flag', 'name' => 'all', 'optional' => true, 'description' => 'List every excluded product, not only a summary.'],
                ['type' => 'assoc', 'name' => 'json-file', 'optional' => true, 'description' => 'Also write the preview as JSON to this file.'],
            ],
        ]);
        WP_CLI::add_command('tudorsync sync run', [$commands, 'syncRun'], ['shortdesc' => 'Runs the full sync now (POST /v1/stocks/batch).']);
        WP_CLI::add_command('tudorsync connection test', [$commands, 'connectionTest'], ['shortdesc' => 'Tests the credentials with GET /v1/point-of-sales.']);
        WP_CLI::add_command('tudorsync realtime publish', [$commands, 'realtimePublish'], [
            'shortdesc' => 'Shows the queued stock changes and what publishing them would do.',
            'synopsis' => [['type' => 'flag', 'name' => 'send', 'optional' => true, 'description' => 'Publish them now.']],
        ]);
        WP_CLI::add_command('tudorsync model-code fill', [$commands, 'modelCodeFill'], [
            'shortdesc' => 'Fills the TUDOR model code field from the product images\' texts or the SKU rule.',
            'synopsis' => [
                ['type' => 'flag', 'name' => 'apply', 'optional' => true, 'description' => 'Save the codes (without it, nothing is written).'],
                ['type' => 'flag', 'name' => 'overwrite', 'optional' => true, 'description' => 'Replace codes already filled in when they differ.'],
                ['type' => 'flag', 'name' => 'all', 'optional' => true, 'description' => 'List every product, not only those needing attention.'],
            ],
        ]);
        WP_CLI::add_command('tudorsync report programme-sales', [$commands, 'reportProgrammeSales'], [
            'shortdesc' => 'TUDOR watches sold online in a month, as counted for the monthly report.',
            'synopsis' => [
                ['type' => 'assoc', 'name' => 'month', 'optional' => true, 'description' => 'YYYY-MM (default: last month, store timezone).'],
                ['type' => 'flag', 'name' => 'all-orders', 'optional' => true, 'description' => 'Count every order, not only those that came from tudorwatch.com.'],
            ],
        ]);
        WP_CLI::add_command('tudorsync api-log clean', [$commands, 'apiLogClean'], [
            'shortdesc' => 'Deletes API log rows older than 60 days (or --days).',
            'synopsis' => [['type' => 'assoc', 'name' => 'days', 'optional' => true]],
        ]);
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function catalogPreview(array $args, array $assoc): void
    {
        $snapshot = $this->plugin->catalogConnector()->collect();
        $review = $snapshot->review;

        foreach ($snapshot->warnings as $warning) {
            WP_CLI::warning($warning);
        }

        $toSend = $snapshot->getItemsToSend();
        WP_CLI::log(sprintf('Records to send (%d):', count($toSend)));
        $this->table(array_map(static fn (StockAvailability $item): array => [
            'mc' => $item->modelCode,
            'country' => $item->country,
            'value' => $item->value,
            'default URL' => $item->defaultUrl,
            'locales' => implode(', ', array_keys($item->localizedUrls)),
        ], $toSend), ['mc', 'country', 'value', 'default URL', 'locales']);

        $rows = [];
        foreach ($snapshot->products as $product) {
            if ($product['status'] === CatalogSnapshot::EXCLUDED && empty($assoc['all'])) {
                continue;
            }

            $rows[] = [
                'id' => $product['product_id'],
                'sku' => $product['sku'],
                'mc' => $product['model_code'] . ($product['model_code_source'] === 'sku' ? ' (sku)' : ''),
                'qty' => $product['qty'],
                'salable' => $product['salable_qty'],
                'status' => $product['status'],
                'reason' => $product['reason'] . (isset($product['merged_count']) ? sprintf('+%d merged', $product['merged_count']) : ''),
            ];
        }

        WP_CLI::log('');
        WP_CLI::log('Products:');
        $this->table($rows, ['id', 'sku', 'mc', 'qty', 'salable', 'status', 'reason']);

        if (empty($assoc['all'])) {
            foreach ($snapshot->countExcludedByReason() as $reason => $count) {
                WP_CLI::log(sprintf('  excluded: %d × %s', $count, $reason));
            }
        }

        if ($review->exclusions !== [] || $review->warnings !== []) {
            WP_CLI::log('');
            WP_CLI::log('Core review:');
            foreach ($review->getExclusionLines() as $line) {
                WP_CLI::log('  left out: ' . $line);
            }
            foreach ($review->warnings as $warning) {
                WP_CLI::warning($warning);
            }
        }

        if ($review->nothingPassed()) {
            WP_CLI::error('No watch passes the review: the sync would send nothing (an empty batch would set the whole catalog to 0 at TUDOR).', false);
        }

        $counts = $snapshot->countByStatus();
        WP_CLI::log('');
        WP_CLI::log(sprintf(
            '%d record(s) to send (%d built, %d left out by the review) · products: %d sent, %d merged into another, %d excluded.',
            count($toSend),
            count($snapshot->items),
            count($review->exclusions),
            $counts['sent'],
            $counts['merged'],
            $counts['excluded'],
        ));

        if (isset($assoc['json-file']) && is_string($assoc['json-file']) && $assoc['json-file'] !== '') {
            $json = wp_json_encode([
                'generated_at' => gmdate('c'),
                'environment' => $this->plugin->config()->getEnvironment()->value,
                'warnings' => $snapshot->warnings,
                'review' => $review->toArray(),
                'records' => array_map(static fn (StockAvailability $item): array => array_filter([
                    'mc' => $item->modelCode,
                    'country' => $item->country,
                    'value' => $item->value,
                    'defaultUrl' => $item->defaultUrl,
                    'localizedUrls' => $item->localizedUrls,
                    'onlinePurchaseEnabled' => $item->onlinePurchaseEnabled,
                    'storePickupAvailable' => $item->storePickupAvailable,
                    'homeDeliveryTiming' => $item->homeDeliveryTimingHours,
                ], static fn ($value): bool => $value !== null), $toSend),
                'products' => array_values($snapshot->products),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (file_put_contents($assoc['json-file'], $json . "\n") === false) {
                WP_CLI::error('Could not write ' . $assoc['json-file']);
            }

            WP_CLI::success('Preview written to ' . $assoc['json-file']);
        }
    }

    public function syncRun(): void
    {
        $outcome = $this->plugin->syncRunner()->runSync('cli');

        if ($outcome->review !== null) {
            foreach ($outcome->review->getExclusionLines() as $line) {
                WP_CLI::log('left out by the review: ' . $line);
            }
        }

        $outcome->success ? WP_CLI::success($outcome->message) : WP_CLI::error($outcome->message);
    }

    public function connectionTest(): void
    {
        $outcome = $this->plugin->syncRunner()->testConnection('cli');

        $outcome->success ? WP_CLI::success($outcome->message) : WP_CLI::error($outcome->message);
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function realtimePublish(array $args, array $assoc): void
    {
        $runner = $this->plugin->syncRunner();
        $plan = $runner->planPending();

        if ($plan === null) {
            WP_CLI::success('Nothing queued.');

            return;
        }

        $this->table(array_map(static fn (array $row): array => [
            'mc' => $row['model_code'],
            'queued at (UTC)' => substr($row['queued_at'], 0, 19),
            'reason' => $row['reason'],
            'would send' => isset($plan->available[$row['model_code']])
                ? 'value ' . $plan->available[$row['model_code']]->value
                : 'not available → withdrawn',
        ], $plan->queued), ['mc', 'queued at (UTC)', 'reason', 'would send']);

        WP_CLI::log(($plan->fullBatch ? 'FULL BATCH — ' : '') . $plan->why);

        if (empty($assoc['send'])) {
            return;
        }

        $outcome = $runner->publishPending('cli');
        $outcome->success ? WP_CLI::success($outcome->message) : WP_CLI::error($outcome->message);
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function modelCodeFill(array $args, array $assoc): void
    {
        $connector = $this->plugin->catalogConnector();
        $resolver = $this->plugin->modelCodeResolver();
        $apply = !empty($assoc['apply']);
        $overwrite = !empty($assoc['overwrite']);
        $ids = array_values(array_unique(array_merge($connector->getSkuRuleCandidateIds(), $this->getScopeAndMetaIds())));
        sort($ids);

        if ($ids === []) {
            WP_CLI::error('No candidate products: set a SKU rule, a product scope, or fill in the code by hand.');
        }

        $rows = [];
        $summary = ['products' => 0, 'from images' => 0, 'from SKU rule' => 0, 'no code' => 0, 'only by hand' => 0, 'conflicts' => 0, 'images ≠ rule' => 0, 'in stock without code' => 0, 'written' => 0];

        foreach ($ids as $productId) {
            $product = wc_get_product($productId);

            if (!$product instanceof \WC_Product || $product->is_type('variable')) {
                continue;
            }

            $summary['products']++;
            $current = (string) $product->get_meta(Config::META_MODEL_CODE);
            $fromImages = $this->codesInImages($product);
            $fromSku = $resolver->fromSku((string) $product->get_sku());
            $note = '';

            if (count($fromImages) > 1) {
                $code = null;
                $source = 'conflict';
                $note = 'images disagree: ' . implode(', ', $fromImages);
                $summary['conflicts']++;
            } elseif (count($fromImages) === 1) {
                $code = $fromImages[0];
                $source = 'images';
                $summary['from images']++;

                if ($fromSku !== null && $fromSku !== $code) {
                    $note = 'SKU rule gives ' . $fromSku;
                    $summary['images ≠ rule']++;
                }
            } elseif ($fromSku !== null) {
                $code = $fromSku;
                $source = 'sku rule';
                $summary['from SKU rule']++;
            } else {
                $code = null;
                $source = 'none';
                $summary[$current === '' ? 'no code' : 'only by hand']++;
            }

            $action = match (true) {
                $code === null => $current !== '' ? 'kept ' . $current : '—',
                $current === '' => 'fill',
                $current === $code => 'already set',
                $overwrite => 'replace ' . $current,
                default => 'kept ' . $current,
            };

            if ($code === null && $current === '' && $product->is_in_stock()) {
                $summary['in stock without code']++;
            }

            if ($apply && ($action === 'fill' || str_starts_with($action, 'replace '))) {
                $product->update_meta_data(Config::META_MODEL_CODE, $code);
                $product->save_meta_data();
                $summary['written']++;
            }

            $needsAttention = $action === 'fill' || str_starts_with($action, 'replace ') || $source === 'conflict' || $note !== ''
                || ($code === null && $current === '');

            if (!empty($assoc['all']) || $needsAttention) {
                $rows[] = [
                    'id' => $productId,
                    'sku' => (string) $product->get_sku(),
                    'in stock' => $product->is_in_stock() ? 'yes' : 'no',
                    'model code' => (string) $code,
                    'source' => $source,
                    'action' => $action,
                    'note' => $note,
                ];
            }
        }

        $this->table($rows, ['id', 'sku', 'in stock', 'model code', 'source', 'action', 'note']);
        WP_CLI::log(implode(' · ', array_map(static fn ($key, $value): string => $value . ' ' . $key, array_keys($summary), $summary)));

        if (!$apply) {
            WP_CLI::log('Dry run: nothing written. Add --apply to save the codes.');
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function reportProgrammeSales(array $args, array $assoc): void
    {
        $month = isset($assoc['month']) && is_string($assoc['month'])
            ? $assoc['month']
            : (new \DateTimeImmutable('first day of last month', wp_timezone()))->format('Y-m');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            WP_CLI::error('--month must be YYYY-MM.');
        }

        [$from, $to] = ProgrammeSalesSource::monthRange($month);
        $sales = $this->plugin->programmeSalesSource()->getProgrammeSales($from, $to, empty($assoc['all-orders']));

        $this->table(array_map(static fn (array $line): array => [
            'order' => $line['order_number'],
            'placed at' => $line['created_at'],
            'sku' => $line['sku'],
            'mc' => $line['model_code'],
            'units' => $line['units'],
            'click & collect' => $line['click_and_collect'] ? 'yes' : 'no',
            'from tudorwatch.com' => $line['attributed'] ? 'yes' : 'no',
        ], $sales->lines), ['order', 'placed at', 'sku', 'mc', 'units', 'click & collect', 'from tudorwatch.com']);

        WP_CLI::log(sprintf(
            '%s (%s): %d TUDOR watch(es) sold online · click & collect: %s%s',
            $month,
            wp_timezone_string(),
            $sales->watchesSoldOnline,
            $sales->clickAndCollectSales === null ? 'n/a (no pickup shipping method configured)' : (string) $sales->clickAndCollectSales,
            empty($assoc['all-orders']) ? ' · only orders from tudorwatch.com' : ' · all orders',
        ));
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function apiLogClean(array $args, array $assoc): void
    {
        $days = isset($assoc['days']) ? max(1, (int) $assoc['days']) : \Tudorsync\Woocommerce\Api\ApiLog::RETENTION_DAYS;
        $deleted = $this->plugin->apiLog()->deleteOlderThan($days);

        WP_CLI::success(sprintf('%d API log row(s) older than %d days deleted.', $deleted, $days));
    }

    /**
     * Products with the code already filled in, plus every product in the configured scope.
     *
     * @return list<int>
     */
    private function getScopeAndMetaIds(): array
    {
        $ids = $this->plugin->catalogConnector()->getCandidateIds();

        foreach ($this->plugin->config()->getProductScope() as $term) {
            if (!taxonomy_exists($term['taxonomy'])) {
                continue;
            }

            foreach (get_posts([
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'numberposts' => -1,
                'tax_query' => [['taxonomy' => $term['taxonomy'], 'field' => 'slug', 'terms' => $term['slug']]],
            ]) as $productId) {
                $ids[] = (int) $productId;
            }
        }

        return $ids;
    }

    /**
     * TUDOR model codes written in the texts of the product's images (alt text, title, caption),
     * as TUDOR's own image set names them. File names are not used.
     *
     * @return list<string>
     */
    private function codesInImages(\WC_Product $product): array
    {
        $imageIds = array_filter(array_merge([(int) $product->get_image_id()], array_map('intval', $product->get_gallery_image_ids())));
        $codes = [];

        foreach ($imageIds as $imageId) {
            $texts = [
                (string) get_post_meta($imageId, '_wp_attachment_image_alt', true),
                (string) get_the_title($imageId),
                (string) wp_get_attachment_caption($imageId),
            ];

            foreach ($texts as $text) {
                if (preg_match_all(self::TMC_IN_TEXT, $text, $matches) > 0) {
                    foreach ($matches[0] as $match) {
                        $codes[strtoupper($match)] = true;
                    }
                }
            }
        }

        return array_keys($codes);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     */
    private function table(array $rows, array $fields): void
    {
        if ($rows === []) {
            WP_CLI::log('  (none)');

            return;
        }

        // Captured so the table comes out in order with WP_CLI::log() lines.
        ob_start();
        WP_CLI\Utils\format_items('table', $rows, $fields);
        WP_CLI::log(rtrim((string) ob_get_clean()));
    }
}
