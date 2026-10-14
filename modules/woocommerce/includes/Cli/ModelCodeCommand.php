<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Cli;

use Tudorsync\Woocommerce\Config;
use WC_Product;
use WP_CLI;

/**
 * `wp tudorsync model-code fill` — fills the `_tudor_model_code` post meta that enrolls a
 * product in the TUDOR sync, deriving TUDOR's model code (TMC, shape `M<ref>-<variant>`) from
 * what the store already has, in order of reliability:
 *
 *   1. the SKU, when it is already a full TMC (e.g. `M2830A1A0-0001`);
 *   2. otherwise TUDOR's own code in a product image's alt text (e.g. `Tudor M79360N-0002`),
 *      the same source the Magento module prefers.
 *
 * Products it can't resolve are reported so their code can be set by hand on the product
 * screen (ProductField). It never invents a code from a short retailer SKU, because that
 * suffix does not map to TUDOR's variant deterministically.
 *
 * Dry run by default; pass --apply to write. --overwrite also replaces codes already set.
 * --category scopes the run to a product category (slug), e.g. --category=tudor.
 */
final class ModelCodeCommand
{
    /** A SKU that is already a full TMC. */
    private const TMC_EXACT = '/^M[0-9A-Z]+-\d{4}$/i';
    /** A TMC embedded in free text (image alt). */
    private const TMC_IN_TEXT = '/M[0-9A-Z]+-\d{4}/i';

    /**
     * ## OPTIONS
     *
     * [--apply]
     * : Write the codes. Without it, nothing is saved (dry run).
     *
     * [--overwrite]
     * : Also replace codes that are already set. Default: keep existing values.
     *
     * [--category=<slug>]
     * : Only products in this product category (slug). Default: all published products.
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assocArgs
     */
    public function fill(array $args, array $assocArgs): void
    {
        $apply = isset($assocArgs['apply']);
        $overwrite = isset($assocArgs['overwrite']);
        $category = isset($assocArgs['category']) ? (string) $assocArgs['category'] : '';

        $fromSku = 0;
        $fromImage = 0;
        $keptExisting = 0;
        $unresolved = [];
        $changes = [];

        foreach ($this->products($category) as $product) {
            $id = $product->get_id();
            $existing = trim((string) get_post_meta($id, Config::META_MODEL_CODE, true));

            if ($existing !== '' && !$overwrite) {
                $keptExisting++;
                continue;
            }

            [$code, $source] = $this->resolve($product);

            if ($code === '') {
                $unresolved[] = $product->get_sku() ?: ('#' . $id);
                continue;
            }

            if ($code === $existing) {
                $keptExisting++;
                continue;
            }

            $source === 'sku' ? $fromSku++ : $fromImage++;
            $changes[] = sprintf('#%d %s → %s (%s)', $id, $product->get_sku() ?: '-', $code, $source);

            if ($apply) {
                update_post_meta($id, Config::META_MODEL_CODE, $code);
            }
        }

        foreach (array_slice($changes, 0, 40) as $line) {
            WP_CLI::log(($apply ? '[set] ' : '[dry] ') . $line);
        }

        if (count($changes) > 40) {
            WP_CLI::log(sprintf('… and %d more.', count($changes) - 40));
        }

        if ($unresolved !== []) {
            WP_CLI::log('');
            WP_CLI::log(sprintf('No TMC found (set by hand on the product), %d:', count($unresolved)));
            WP_CLI::log('  ' . implode(', ', $unresolved));
        }

        WP_CLI::log('');
        WP_CLI::success(sprintf(
            '%s — from SKU: %d, from image alt: %d, kept: %d, unresolved: %d.',
            $apply ? 'Applied' : 'Dry run (use --apply to write)',
            $fromSku,
            $fromImage,
            $keptExisting,
            count($unresolved),
        ));
    }

    /**
     * @return array{0:string,1:string} [code, source] — code '' when none found.
     */
    private function resolve(WC_Product $product): array
    {
        $sku = trim((string) $product->get_sku());

        if ($sku !== '' && preg_match(self::TMC_EXACT, $sku)) {
            return [strtoupper($sku), 'sku'];
        }

        $attachmentIds = array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids()));

        foreach ($attachmentIds as $attachmentId) {
            $alt = (string) get_post_meta((int) $attachmentId, '_wp_attachment_image_alt', true);

            if (preg_match(self::TMC_IN_TEXT, $alt, $m)) {
                return [strtoupper($m[0]), 'image'];
            }
        }

        return ['', ''];
    }

    /**
     * Published products, optionally scoped to a category slug, streamed in pages to keep
     * memory flat over a large catalog.
     *
     * @return iterable<WC_Product>
     */
    private function products(string $category): iterable
    {
        $page = 1;

        do {
            $query = [
                'status' => 'publish',
                'limit' => 200,
                'page' => $page,
                'return' => 'objects',
                'orderby' => 'ID',
                'order' => 'ASC',
            ];

            if ($category !== '') {
                $query['category'] = [$category];
            }

            $products = wc_get_products($query);

            foreach ($products as $product) {
                if ($product instanceof WC_Product) {
                    yield $product;
                }
            }

            $page++;
        } while (count($products) === 200);
    }
}
