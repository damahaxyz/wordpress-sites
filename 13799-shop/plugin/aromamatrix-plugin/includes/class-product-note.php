<?php
/**
 * Internal product note management for WooCommerce products.
 *
 * @package AromamatrixPlugin
 */

declare(strict_types=1);

namespace Aromamatrix\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class ProductNote
{
    public const META_KEY = '_aromamatrix_product_note';

    private const COLUMN_KEY = 'aromamatrix_product_note';

    public function register(): void
    {
        add_action('woocommerce_product_options_sku', [$this, 'render_product_field']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_field']);

        add_filter('manage_edit-product_columns', [$this, 'add_product_column'], 21);
        add_action('manage_product_posts_custom_column', [$this, 'render_product_column'], 10, 2);
        add_action('admin_head-edit.php', [$this, 'style_product_column']);
    }

    public function render_product_field(): void
    {
        global $post;

        if (! $post instanceof \WP_Post || ! function_exists('woocommerce_wp_textarea_input')) {
            return;
        }

        woocommerce_wp_textarea_input([
            'id'          => self::META_KEY,
            'label'       => __('Product Note', 'aromamatrix-plugin'),
            'value'       => get_post_meta($post->ID, self::META_KEY, true),
            'placeholder' => __('Add an internal note for this product.', 'aromamatrix-plugin'),
            'desc_tip'    => true,
            'description' => __(
                'Internal note for product management. Never shown to customers.',
                'aromamatrix-plugin'
            ),
        ]);
    }

    public function save_product_field(\WC_Product $product): void
    {
        if (! isset($_POST[self::META_KEY])) {
            return;
        }

        $product_note = sanitize_textarea_field(wp_unslash($_POST[self::META_KEY]));

        if ($product_note === '') {
            $product->delete_meta_data(self::META_KEY);
            return;
        }

        $product->update_meta_data(self::META_KEY, $product_note);
    }

    /**
     * @param array<string, string> $columns Product list columns.
     * @return array<string, string>
     */
    public function add_product_column(array $columns): array
    {
        $column = [self::COLUMN_KEY => __('Product Note', 'aromamatrix-plugin')];
        $factory_model_position = array_search('aromamatrix_factory_model', array_keys($columns), true);

        if ($factory_model_position === false) {
            return $columns + $column;
        }

        return array_slice($columns, 0, $factory_model_position + 1, true)
            + $column
            + array_slice($columns, $factory_model_position + 1, null, true);
    }

    public function render_product_column(string $column_name, int $product_id): void
    {
        if ($column_name !== self::COLUMN_KEY) {
            return;
        }

        $product_note = (string) get_post_meta($product_id, self::META_KEY, true);
        echo $product_note !== '' ? nl2br(esc_html($product_note)) : '&ndash;';
    }

    public function style_product_column(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen || $screen->id !== 'edit-product') {
            return;
        }

        echo '<style>.column-' . esc_attr(self::COLUMN_KEY) . '{width:16rem}</style>';
    }
}
