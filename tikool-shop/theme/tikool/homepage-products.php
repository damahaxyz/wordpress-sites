<?php
/**
 * Configurable homepage product showcase.
 *
 * @package TikoolShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Products used before an editor makes an explicit selection.
 *
 * @return int[]
 */
function tikool_home_products_default_ids(): array
{
    if (! post_type_exists('product')) {
        return [];
    }

    return array_map(
        'absint',
        get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
            'orderby'        => 'menu_order date',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ])
    );
}

/**
 * @return array<string, mixed>
 */
function tikool_home_products_defaults(): array
{
    return [
        'enabled'     => '1',
        'kicker'      => __('Made for their place in your home', 'tikool'),
        'heading'     => __('Custom gifts that feel like them.', 'tikool'),
        'description' => __('Start with a favorite photo and choose the keepsake that belongs in your everyday life.', 'tikool'),
        'product_ids' => tikool_home_products_default_ids(),
        'columns'     => 3,
    ];
}

/**
 * @return array<string, mixed>
 */
function tikool_get_home_products_data(int $post_id = 0): array
{
    $defaults = tikool_home_products_defaults();
    $values   = [];

    foreach ($defaults as $key => $default) {
        $meta_key = '_tikool_home_products_' . $key;
        $values[$key] = $post_id > 0 && metadata_exists('post', $post_id, $meta_key)
            ? get_post_meta($post_id, $meta_key, true)
            : $default;
    }

    $values['enabled'] = '0' !== (string) $values['enabled'];
    $values['columns'] = min(4, max(1, absint($values['columns'])));
    $values['product_ids'] = array_values(array_filter(array_map('absint', (array) $values['product_ids'])));

    return $values;
}

/**
 * Add a product showcase panel to the selected homepage.
 */
function tikool_add_home_products_meta_box(WP_Post $post): void
{
    if ($post->ID < 1 || $post->ID !== (int) get_option('page_on_front')) {
        return;
    }

    add_meta_box(
        'tikool-homepage-products',
        __('Tikool homepage products', 'tikool'),
        'tikool_render_home_products_meta_box',
        'page',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_page', 'tikool_add_home_products_meta_box');

/**
 * Render homepage product showcase fields.
 */
function tikool_render_home_products_meta_box(WP_Post $post): void
{
    $values = tikool_get_home_products_data($post->ID);
    wp_nonce_field('tikool_save_home_products', 'tikool_home_products_nonce');
    ?>
    <div class="tikool-banner-settings tikool-products-settings">
        <p class="tikool-banner-intro"><?php esc_html_e('Choose the products and text shown in the homepage collection section. Drag selected products inside the field to change their display order.', 'tikool'); ?></p>

        <label class="tikool-banner-toggle">
            <input type="hidden" name="tikool_home_products[enabled]" value="0">
            <input type="checkbox" name="tikool_home_products[enabled]" value="1" <?php checked((bool) $values['enabled']); ?>>
            <strong><?php esc_html_e('Display product section', 'tikool'); ?></strong>
        </label>

        <div class="tikool-banner-grid">
            <?php tikool_home_products_text_input('kicker', __('Small heading', 'tikool'), (string) $values['kicker']); ?>
            <?php tikool_home_products_text_input('heading', __('Main heading', 'tikool'), (string) $values['heading']); ?>
        </div>

        <p class="tikool-banner-field">
            <label for="tikool-products-description"><?php esc_html_e('Description', 'tikool'); ?></label>
            <textarea id="tikool-products-description" name="tikool_home_products[description]" rows="3"><?php echo esc_textarea((string) $values['description']); ?></textarea>
        </p>

        <div class="tikool-banner-grid tikool-products-grid">
            <p class="tikool-banner-field">
                <label for="tikool-products-selection"><?php esc_html_e('Products to display', 'tikool'); ?></label>
                <select
                    id="tikool-products-selection"
                    class="wc-product-search"
                    name="tikool_home_products[product_ids][]"
                    multiple="multiple"
                    data-placeholder="<?php esc_attr_e('Search for products…', 'tikool'); ?>"
                    data-action="woocommerce_json_search_products"
                    data-sortable="true"
                    style="width:100%;"
                >
                    <?php foreach ($values['product_ids'] as $product_id) : ?>
                        <?php $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null; ?>
                        <?php if ($product) : ?>
                            <option value="<?php echo esc_attr((string) $product_id); ?>" selected><?php echo esc_html(wp_strip_all_tags($product->get_formatted_name())); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e('If empty, the first three published products are used automatically.', 'tikool'); ?></span>
            </p>

            <p class="tikool-banner-field">
                <label for="tikool-products-columns"><?php esc_html_e('Desktop columns', 'tikool'); ?></label>
                <select id="tikool-products-columns" name="tikool_home_products[columns]">
                    <?php foreach ([1, 2, 3, 4] as $column_count) : ?>
                        <option value="<?php echo esc_attr((string) $column_count); ?>" <?php selected((int) $values['columns'], $column_count); ?>><?php echo esc_html((string) $column_count); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Render a homepage product text field.
 */
function tikool_home_products_text_input(string $key, string $label, string $value): void
{
    $id = 'tikool-products-' . str_replace('_', '-', $key);
    ?>
    <p class="tikool-banner-field">
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
        <input id="<?php echo esc_attr($id); ?>" type="text" name="tikool_home_products[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
    </p>
    <?php
}

/**
 * Save homepage product showcase fields.
 */
function tikool_save_home_products(int $post_id): void
{
    if (
        ! isset($_POST['tikool_home_products_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tikool_home_products_nonce'])), 'tikool_save_home_products')
        || ! current_user_can('edit_post', $post_id)
        || wp_is_post_autosave($post_id)
        || wp_is_post_revision($post_id)
        || $post_id !== (int) get_option('page_on_front')
    ) {
        return;
    }

    $raw = isset($_POST['tikool_home_products']) && is_array($_POST['tikool_home_products'])
        ? wp_unslash($_POST['tikool_home_products'])
        : [];

    $product_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($raw['product_ids'] ?? [])))));
    $product_ids = array_values(array_filter($product_ids, static function (int $product_id): bool {
        return 'product' === get_post_type($product_id) && 'publish' === get_post_status($product_id);
    }));

    update_post_meta($post_id, '_tikool_home_products_enabled', ! empty($raw['enabled']) ? '1' : '0');
    update_post_meta($post_id, '_tikool_home_products_kicker', sanitize_text_field((string) ($raw['kicker'] ?? '')));
    update_post_meta($post_id, '_tikool_home_products_heading', sanitize_text_field((string) ($raw['heading'] ?? '')));
    update_post_meta($post_id, '_tikool_home_products_description', sanitize_textarea_field((string) ($raw['description'] ?? '')));
    update_post_meta($post_id, '_tikool_home_products_product_ids', $product_ids);
    update_post_meta($post_id, '_tikool_home_products_columns', min(4, max(1, absint($raw['columns'] ?? 3))));
}
add_action('save_post_page', 'tikool_save_home_products');

/**
 * Load WooCommerce's searchable product picker on the homepage editor.
 */
function tikool_home_products_admin_assets(string $hook_suffix): void
{
    if (! in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
        return;
    }

    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($post_id < 1 || $post_id !== (int) get_option('page_on_front')) {
        return;
    }

    if (wp_script_is('wc-enhanced-select', 'registered')) {
        wp_enqueue_script('wc-enhanced-select');
    }

    if (wp_style_is('woocommerce_admin_styles', 'registered')) {
        wp_enqueue_style('woocommerce_admin_styles');
    }
}
add_action('admin_enqueue_scripts', 'tikool_home_products_admin_assets', 20);
