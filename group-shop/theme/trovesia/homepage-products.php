<?php
/**
 * Configurable homepage product showcase.
 *
 * @package TrovesiaShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string, mixed>
 */
function trovesia_home_products_defaults(): array
{
    return [
        'enabled'     => '1',
        'kicker'      => __('Christmas Collection', 'trovesia'),
        'heading'     => __('Festive finds for every celebration.', 'trovesia'),
        'description' => __('Explore seasonal gifts and celebration essentials selected for Christmas.', 'trovesia'),
        'tag'         => 'christmas',
        'columns'     => 3,
    ];
}

/**
 * @return array<string, mixed>
 */
function trovesia_get_home_products_data(int $post_id = 0): array
{
    $defaults = trovesia_home_products_defaults();
    $values   = [];

    foreach ($defaults as $key => $default) {
        $meta_key = '_trovesia_home_products_' . $key;
        $values[$key] = $post_id > 0 && metadata_exists('post', $post_id, $meta_key)
            ? get_post_meta($post_id, $meta_key, true)
            : $default;
    }

    $values['enabled'] = '0' !== (string) $values['enabled'];
    $values['columns'] = min(4, max(1, absint($values['columns'])));
    $values['tag'] = sanitize_title((string) $values['tag']) ?: 'christmas';

    return $values;
}

/**
 * Add a product showcase panel to the selected homepage.
 */
function trovesia_add_home_products_meta_box(WP_Post $post): void
{
    if ($post->ID < 1 || $post->ID !== (int) get_option('page_on_front')) {
        return;
    }

    add_meta_box(
        'trovesia-homepage-products',
        __('Trovesia homepage products', 'trovesia'),
        'trovesia_render_home_products_meta_box',
        'page',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_page', 'trovesia_add_home_products_meta_box');

/**
 * Render homepage product showcase fields.
 */
function trovesia_render_home_products_meta_box(WP_Post $post): void
{
    $values = trovesia_get_home_products_data($post->ID);
    wp_nonce_field('trovesia_save_home_products', 'trovesia_home_products_nonce');
    ?>
    <div class="trovesia-banner-settings trovesia-products-settings">
        <p class="trovesia-banner-intro"><?php esc_html_e('Choose the text and product tag used by the homepage collection section. Every published, catalogue-visible product with the selected tag is included automatically.', 'trovesia'); ?></p>

        <label class="trovesia-banner-toggle">
            <input type="hidden" name="trovesia_home_products[enabled]" value="0">
            <input type="checkbox" name="trovesia_home_products[enabled]" value="1" <?php checked((bool) $values['enabled']); ?>>
            <strong><?php esc_html_e('Display product section', 'trovesia'); ?></strong>
        </label>

        <div class="trovesia-banner-grid">
            <?php trovesia_home_products_text_input('kicker', __('Small heading', 'trovesia'), (string) $values['kicker']); ?>
            <?php trovesia_home_products_text_input('heading', __('Main heading', 'trovesia'), (string) $values['heading']); ?>
        </div>

        <p class="trovesia-banner-field">
            <label for="trovesia-products-description"><?php esc_html_e('Description', 'trovesia'); ?></label>
            <textarea id="trovesia-products-description" name="trovesia_home_products[description]" rows="3"><?php echo esc_textarea((string) $values['description']); ?></textarea>
        </p>

        <div class="trovesia-banner-grid trovesia-products-grid">
            <p class="trovesia-banner-field">
                <label for="trovesia-products-tag"><?php esc_html_e('Product tag', 'trovesia'); ?></label>
                <input id="trovesia-products-tag" name="trovesia_home_products[tag]" type="text" value="<?php echo esc_attr((string) $values['tag']); ?>" placeholder="christmas">
                <span class="description"><?php esc_html_e('Use the tag slug. Add or remove this tag on a product to control whether it appears here.', 'trovesia'); ?></span>
            </p>

            <p class="trovesia-banner-field">
                <label for="trovesia-products-columns"><?php esc_html_e('Desktop columns', 'trovesia'); ?></label>
                <select id="trovesia-products-columns" name="trovesia_home_products[columns]">
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
function trovesia_home_products_text_input(string $key, string $label, string $value): void
{
    $id = 'trovesia-products-' . str_replace('_', '-', $key);
    ?>
    <p class="trovesia-banner-field">
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
        <input id="<?php echo esc_attr($id); ?>" type="text" name="trovesia_home_products[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
    </p>
    <?php
}

/**
 * Save homepage product showcase fields.
 */
function trovesia_save_home_products(int $post_id): void
{
    if (
        ! isset($_POST['trovesia_home_products_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['trovesia_home_products_nonce'])), 'trovesia_save_home_products')
        || ! current_user_can('edit_post', $post_id)
        || wp_is_post_autosave($post_id)
        || wp_is_post_revision($post_id)
        || $post_id !== (int) get_option('page_on_front')
    ) {
        return;
    }

    $raw = isset($_POST['trovesia_home_products']) && is_array($_POST['trovesia_home_products'])
        ? wp_unslash($_POST['trovesia_home_products'])
        : [];

    update_post_meta($post_id, '_trovesia_home_products_enabled', ! empty($raw['enabled']) ? '1' : '0');
    update_post_meta($post_id, '_trovesia_home_products_kicker', sanitize_text_field((string) ($raw['kicker'] ?? '')));
    update_post_meta($post_id, '_trovesia_home_products_heading', sanitize_text_field((string) ($raw['heading'] ?? '')));
    update_post_meta($post_id, '_trovesia_home_products_description', sanitize_textarea_field((string) ($raw['description'] ?? '')));
    update_post_meta($post_id, '_trovesia_home_products_tag', sanitize_title((string) ($raw['tag'] ?? '')) ?: 'christmas');
    delete_post_meta($post_id, '_trovesia_home_products_product_ids');
    update_post_meta($post_id, '_trovesia_home_products_columns', min(4, max(1, absint($raw['columns'] ?? 3))));
}
add_action('save_post_page', 'trovesia_save_home_products');
