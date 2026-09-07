<?php
/**
 * Internal factory model management for WooCommerce products.
 *
 * @package AromamatrixPlugin
 */

declare(strict_types=1);

namespace Aromamatrix\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class FactoryModel
{
    public const META_KEY = '_aromamatrix_factory_reference';

    private const COLUMN_KEY = 'aromamatrix_factory_model';

    public function register(): void
    {
        add_action('woocommerce_product_options_sku', [$this, 'render_product_field']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_field']);

        add_filter('manage_edit-product_columns', [$this, 'add_product_column'], 20);
        add_action('manage_product_posts_custom_column', [$this, 'render_product_column'], 10, 2);
        add_action('admin_head-edit.php', [$this, 'style_product_column']);

        add_filter(
            'woocommerce_product_pre_search_products',
            [$this, 'search_products_by_factory_model'],
            10,
            6
        );
    }

    public function render_product_field(): void
    {
        global $post;

        if (! $post instanceof \WP_Post || ! function_exists('woocommerce_wp_text_input')) {
            return;
        }

        woocommerce_wp_text_input([
            'id'          => self::META_KEY,
            'label'       => __('Factory Model', 'aromamatrix-plugin'),
            'value'       => get_post_meta($post->ID, self::META_KEY, true),
            'placeholder' => 'QY1001-1',
            'desc_tip'    => true,
            'description' => __(
                'Internal factory reference used for supplier communication. Never shown to customers.',
                'aromamatrix-plugin'
            ),
        ]);
    }

    public function save_product_field(\WC_Product $product): void
    {
        if (! isset($_POST[self::META_KEY])) {
            return;
        }

        $factory_model = sanitize_text_field(wp_unslash($_POST[self::META_KEY]));

        if ($factory_model === '') {
            $product->delete_meta_data(self::META_KEY);
            return;
        }

        $product->update_meta_data(self::META_KEY, $factory_model);
    }

    /**
     * @param array<string, string> $columns Product list columns.
     * @return array<string, string>
     */
    public function add_product_column(array $columns): array
    {
        $column = [self::COLUMN_KEY => __('Factory Model', 'aromamatrix-plugin')];
        $sku_position = array_search('sku', array_keys($columns), true);

        if ($sku_position === false) {
            return $columns + $column;
        }

        return array_slice($columns, 0, $sku_position + 1, true)
            + $column
            + array_slice($columns, $sku_position + 1, null, true);
    }

    public function render_product_column(string $column_name, int $product_id): void
    {
        if ($column_name !== self::COLUMN_KEY) {
            return;
        }

        $factory_model = (string) get_post_meta($product_id, self::META_KEY, true);
        echo $factory_model !== '' ? esc_html($factory_model) : '&ndash;';
    }

    public function style_product_column(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen || $screen->id !== 'edit-product') {
            return;
        }

        echo '<style>.column-' . esc_attr(self::COLUMN_KEY) . '{width:9rem}</style>';
    }

    /**
     * Let the standard Products search box find an internal factory model.
     *
     * The lookup is restricted to the authenticated WooCommerce product list,
     * so protected factory references never become a customer-facing search key.
     *
     * @param mixed       $results            Search short-circuit value.
     * @param string      $term               Search term.
     * @param string      $type               Product type restriction.
     * @param bool        $include_variations Whether variations are included.
     * @param bool        $all_statuses       Whether all product statuses are included.
     * @param int|null    $limit              Maximum result count.
     * @return mixed
     */
    public function search_products_by_factory_model(
        mixed $results,
        string $term,
        string $type,
        bool $include_variations,
        bool $all_statuses,
        ?int $limit
    ): mixed {
        if (is_array($results) || ! $this->is_product_list_request()) {
            return $results;
        }

        $query = [
            'post_type'              => 'product',
            'post_status'            => $all_statuses ? 'any' : ['publish', 'private'],
            'fields'                 => 'ids',
            'posts_per_page'         => $limit ?? -1,
            'no_found_rows'          => true,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => self::META_KEY,
                    'value'   => sanitize_text_field($term),
                    'compare' => 'LIKE',
                ],
            ],
        ];

        $factory_model_ids = get_posts($query);

        return $factory_model_ids === [] ? $results : array_map('absint', $factory_model_ids);
    }

    private function is_product_list_request(): bool
    {
        global $pagenow;

        if (! is_admin() || ! current_user_can('edit_products') || $pagenow !== 'edit.php') {
            return false;
        }

        $post_type = isset($_GET['post_type'])
            ? sanitize_key(wp_unslash($_GET['post_type']))
            : '';

        return $post_type === 'product';
    }
}
