<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

/**
 * Adds a "TUDOR Model Code" text field to the General tab of the product edit screen, and
 * saves it to post meta (Config::META_MODEL_CODE). A product is enrolled in the TUDOR
 * program simply by having this field filled in — see CatalogConnector.
 */
final class ProductField
{
    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderField']);
        add_action('woocommerce_process_product_meta', [$this, 'saveField']);
    }

    public function renderField(): void
    {
        global $post;

        woocommerce_wp_text_input([
            'id' => Config::META_MODEL_CODE,
            'label' => __('TUDOR Model Code', 'tudorsync'),
            'desc_tip' => true,
            'description' => __(
                'TUDOR\'s reference code for this watch model. Leave empty to exclude this product from the TUDOR e-commerce sync entirely.',
                'tudorsync',
            ),
            'value' => get_post_meta($post->ID, Config::META_MODEL_CODE, true),
        ]);
    }

    public function saveField(int $postId): void
    {
        $value = isset($_POST[Config::META_MODEL_CODE]) ? sanitize_text_field(wp_unslash($_POST[Config::META_MODEL_CODE])) : '';

        update_post_meta($postId, Config::META_MODEL_CODE, $value);
    }
}
