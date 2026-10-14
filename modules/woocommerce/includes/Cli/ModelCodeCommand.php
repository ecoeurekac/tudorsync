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
 *   2. otherwise TUDOR's own code in a product image — its file name (`tudor-m79360n-0011.png`)
 *      or alt text (`Tudor M79360N 0002`). TUDOR's own images carry the exact variant, which a
 *      short retailer SKU (`79360N/7206006`) does not, so this is how the variant is recovered.
 *
 * Safety: a code taken from an image is only used when the image's reference matches the SKU's
 * (same first characters) — a product carrying another model's image is reported as a mismatch
 * to fix by hand, never silently given the wrong code. A short retailer SKU is never turned
 * into a code on its own. Everything unresolved is listed for the product screen (ProductField).
 *
 * Dry run by default; pass --apply to write. --overwrite also replaces codes already set.
 * --category scopes the run to a product category (slug), e.g. --category=tudor.
 */
final class ModelCodeCommand
{
    /** A SKU that is already a full TMC. */
    private const TMC_EXACT = '/^M[0-9A-Z]+-\d{4}$/i';
    /** A TMC in an image file name or alt: `m<ref>` then a 4-digit variant, dash/space/underscore between. */
    private const TMC_IN_IMAGE = '/\bm([0-9a-z]+)[\s_-]+(\d{4})\b/i';

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
        $mismatched = [];
        $changes = [];

        foreach ($this->products($category) as $product) {
            $id = $product->get_id();
            $existing = trim((string) get_post_meta($id, Config::META_MODEL_CODE, true));

            if ($existing !== '' && !$overwrite) {
                $keptExisting++;
                continue;
            }

            [$code, $source] = $this->resolve($product);

            if ($source === 'mismatch') {
                $mismatched[] = $product->get_sku() ?: ('#' . $id);
                continue;
            }

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

        if ($mismatched !== []) {
            WP_CLI::log('');
            WP_CLI::log(sprintf('Image belongs to another model — fix the image or set by hand, %d:', count($mismatched)));
            WP_CLI::log('  ' . implode(', ', $mismatched));
        }

        if ($unresolved !== []) {
            WP_CLI::log('');
            WP_CLI::log(sprintf('No TMC found (set by hand on the product), %d:', count($unresolved)));
            WP_CLI::log('  ' . implode(', ', $unresolved));
        }

        WP_CLI::log('');
        WP_CLI::success(sprintf(
            '%s — from SKU: %d, from image: %d, kept: %d, mismatch: %d, unresolved: %d.',
            $apply ? 'Applied' : 'Dry run (use --apply to write)',
            $fromSku,
            $fromImage,
            $keptExisting,
            count($mismatched),
            count($unresolved),
        ));
    }

    /**
     * @return array{0:string,1:string} [code, source]. source: 'sku' / 'image' when found,
     *         'mismatch' when the only image codes belong to another model, '' when none.
     */
    private function resolve(WC_Product $product): array
    {
        $sku = trim((string) $product->get_sku());

        if ($sku !== '' && preg_match(self::TMC_EXACT, $sku)) {
            return [strtoupper($sku), 'sku'];
        }

        $skuRef = strtoupper(explode('/', $sku)[0]);
        $sawImageCode = false;

        foreach ($this->imageStrings($product) as $text) {
            if (!preg_match(self::TMC_IN_IMAGE, $text, $m)) {
                continue;
            }

            $sawImageCode = true;
            $imageRef = strtoupper($m[1]);
            $code = strtoupper('M' . $m[1] . '-' . $m[2]);

            // The image must belong to this product: its reference and the SKU's must share a
            // prefix (covers exact and truncated SKUs, 79210C ⊂ 79210CNU), catching a product
            // that carries another model's image.
            if ($skuRef !== '' && $this->sameModel($skuRef, $imageRef)) {
                return [$code, 'image'];
            }
        }

        // Image codes existed but none matched this SKU → wrong image, not an invented code.
        return ['', $sawImageCode ? 'mismatch' : ''];
    }

    private function sameModel(string $skuRef, string $imageRef): bool
    {
        $n = 4;

        return substr($skuRef, 0, $n) === substr($imageRef, 0, $n);
    }

    /**
     * File names and alt texts of a product's images (featured first, then gallery).
     *
     * @return iterable<string>
     */
    private function imageStrings(WC_Product $product): iterable
    {
        $attachmentIds = array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids()));

        foreach ($attachmentIds as $attachmentId) {
            yield basename((string) get_post_meta((int) $attachmentId, '_wp_attached_file', true));
            yield (string) get_post_meta((int) $attachmentId, '_wp_attachment_image_alt', true);
        }
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
