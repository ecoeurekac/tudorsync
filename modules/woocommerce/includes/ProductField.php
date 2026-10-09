<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Core\Rules\ValidModelList;

/**
 * "TUDOR Model Code" field on the product edit screen (General tab) and on each variation,
 * saved to the `_tudor_model_code` meta (Config::META_MODEL_CODE), normalized like core does
 * (no spaces, upper case). Filling it in enrols the product in the TUDOR programme; when it's
 * empty, the SKU rule (if any) is used instead.
 */
final class ProductField
{
    public function register(): void
    {
        add_action('woocommerce_product_options_sku', [$this, 'renderField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProduct']);
        add_action('woocommerce_variation_options_pricing', [$this, 'renderVariationField'], 10, 3);
        add_action('woocommerce_admin_process_variation_object', [$this, 'saveVariation'], 10, 2);
    }

    public function renderField(): void
    {
        global $product_object;

        woocommerce_wp_text_input([
            'id' => Config::META_MODEL_CODE,
            'label' => __('TUDOR model code', 'tudorsync'),
            'desc_tip' => true,
            'description' => __('TUDOR\'s reference of this watch (TMC), e.g. M79030N-0001. Leave it empty to use the SKU rule, or to leave the product out of TUDOR\'s e-Stock programme.', 'tudorsync'),
            'value' => $product_object instanceof \WC_Product ? (string) $product_object->get_meta(Config::META_MODEL_CODE) : '',
        ]);
    }

    /**
     * @param int $loop
     * @param array<string, mixed> $variationData
     * @param \WP_Post $variation
     */
    public function renderVariationField($loop, $variationData, $variation): void
    {
        woocommerce_wp_text_input([
            'id' => Config::META_MODEL_CODE . '_' . $loop,
            'name' => Config::META_MODEL_CODE . '[' . $loop . ']',
            'label' => __('TUDOR model code', 'tudorsync'),
            'wrapper_class' => 'form-row form-row-full',
            'value' => (string) get_post_meta($variation->ID, Config::META_MODEL_CODE, true),
        ]);
    }

    public function saveProduct(\WC_Product $product): void
    {
        if (isset($_POST[Config::META_MODEL_CODE]) && is_string($_POST[Config::META_MODEL_CODE])) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checked the nonce
            $this->save($product, (string) wp_unslash($_POST[Config::META_MODEL_CODE]));
        }
    }

    public function saveVariation(\WC_Product_Variation $variation, int $loop): void
    {
        if (isset($_POST[Config::META_MODEL_CODE][$loop])) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checked the nonce
            $this->save($variation, (string) wp_unslash($_POST[Config::META_MODEL_CODE][$loop]));
        }
    }

    private function save(\WC_Product $product, string $value): void
    {
        $value = ValidModelList::normalize(sanitize_text_field($value));

        if ($value === '') {
            $product->delete_meta_data(Config::META_MODEL_CODE);
        } else {
            $product->update_meta_data(Config::META_MODEL_CODE, $value);
        }
    }
}
