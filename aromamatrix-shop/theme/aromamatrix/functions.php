<?php
/**
 * Theme setup, assets, and storefront presentation.
 *
 * @package Aromamatrix
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', static function (): void {
    load_theme_textdomain('aromamatrix', get_template_directory() . '/languages');

    add_theme_support('automatic-feed-links');
    add_theme_support('custom-logo', [
        'height'      => 52,
        'width'       => 52,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    add_theme_support('html5', [
        'caption',
        'comment-form',
        'comment-list',
        'gallery',
        'search-form',
        'script',
        'style',
    ]);
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('title-tag');
    add_theme_support('wp-block-styles');
    add_theme_support('align-wide');

    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    add_theme_support('wc-product-gallery-zoom');

    register_nav_menus([
        'primary' => __('Primary menu', 'aromamatrix'),
        'footer'  => __('Footer menu', 'aromamatrix'),
    ]);
});

add_action('wp_enqueue_scripts', static function (): void {
    $stylesheet_path = get_stylesheet_directory() . '/style.css';
    $woocommerce_path = get_template_directory() . '/assets/css/woocommerce.css';
    $script_path = get_template_directory() . '/assets/js/theme.js';
    $category_accordion_path = get_template_directory() . '/assets/js/category-accordion.js';

    wp_enqueue_style(
        'aromamatrix',
        get_stylesheet_uri(),
        [],
        is_file($stylesheet_path) ? (string) filemtime($stylesheet_path) : (string) wp_get_theme()->get('Version')
    );

    if (class_exists('WooCommerce') && is_file($woocommerce_path)) {
        wp_enqueue_style(
            'aromamatrix-woocommerce',
            get_template_directory_uri() . '/assets/css/woocommerce.css',
            ['aromamatrix'],
            (string) filemtime($woocommerce_path)
        );
    }

    if (is_file($script_path)) {
        wp_enqueue_script(
            'aromamatrix-theme',
            get_template_directory_uri() . '/assets/js/theme.js',
            [],
            (string) filemtime($script_path),
            true
        );
    }

    if (function_exists('is_product_category') && (is_product_category() || is_shop()) && is_file($category_accordion_path)) {
        wp_enqueue_script(
            'aromamatrix-category-accordion',
            get_template_directory_uri() . '/assets/js/category-accordion.js',
            [],
            (string) filemtime($category_accordion_path),
            true
        );
    }
});

/**
 * Render a useful English navigation before a WordPress menu is assigned.
 */
function aromamatrix_fallback_menu(): void
{
    $designer_fragrances_url = home_url('/product-category/perfumes/');
    ?>
    <ul class="menu">
        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'aromamatrix'); ?></a></li>
        <li><a href="<?php echo esc_url($designer_fragrances_url); ?>"><?php esc_html_e('Designer Fragrances', 'aromamatrix'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/#us-warehouse')); ?>"><?php esc_html_e('US Warehouse', 'aromamatrix'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Contact Us', 'aromamatrix'); ?></a></li>
    </ul>
    <?php
}

/**
 * Return the editable public contact details used across the theme.
 *
 * @return array{email: string, whatsapp: string, whatsapp_url: string}
 */
function aromamatrix_get_contact_details(): array
{
    $email = sanitize_email((string) get_option('aromamatrix_contact_email', 'sales@aromamatrix.com'));
    $whatsapp = trim((string) get_option('aromamatrix_contact_whatsapp', '+86 131 3512 3123'));
    $whatsapp_url = preg_replace('/\D+/', '', $whatsapp);

    return [
        'email'        => $email ?: 'sales@aromamatrix.com',
        'whatsapp'     => $whatsapp ?: '+86 131 3512 3123',
        'whatsapp_url' => $whatsapp_url ?: '8613135123123',
    ];
}

add_action('admin_menu', static function (): void {
    add_theme_page(
        __('Contact Settings', 'aromamatrix'),
        __('Contact Settings', 'aromamatrix'),
        'manage_options',
        'aromamatrix-contact-settings',
        'aromamatrix_render_contact_settings_page'
    );
});

add_action('admin_init', static function (): void {
    register_setting('aromamatrix_contact_settings', 'aromamatrix_contact_email', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_email',
        'default'           => 'sales@aromamatrix.com',
    ]);
    register_setting('aromamatrix_contact_settings', 'aromamatrix_contact_whatsapp', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '+86 131 3512 3123',
    ]);
});

/**
 * Render Appearance → Contact Settings.
 */
function aromamatrix_render_contact_settings_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $contact = aromamatrix_get_contact_details();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Contact Settings', 'aromamatrix'); ?></h1>
        <p><?php esc_html_e('These details appear on the Contact Us page and in the site footer.', 'aromamatrix'); ?></p>
        <form action="options.php" method="post">
            <?php settings_fields('aromamatrix_contact_settings'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="aromamatrix_contact_email"><?php esc_html_e('Wholesale email', 'aromamatrix'); ?></label></th>
                        <td><input class="regular-text" id="aromamatrix_contact_email" name="aromamatrix_contact_email" type="email" value="<?php echo esc_attr($contact['email']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aromamatrix_contact_whatsapp"><?php esc_html_e('WhatsApp number', 'aromamatrix'); ?></label></th>
                        <td>
                            <input class="regular-text" id="aromamatrix_contact_whatsapp" name="aromamatrix_contact_whatsapp" type="text" value="<?php echo esc_attr($contact['whatsapp']); ?>">
                            <p class="description"><?php esc_html_e('Use the full international number, for example +86 131 3512 3123.', 'aromamatrix'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Save contact settings', 'aromamatrix')); ?>
        </form>
    </div>
    <?php
}

/**
 * Return the direct non-empty child categories for a product category.
 *
 * @return WP_Term[]
 */
function aromamatrix_get_child_product_categories(WP_Term $term): array
{
    $children = get_terms([
        'taxonomy'   => 'product_cat',
        'parent'     => $term->term_id,
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    return is_wp_error($children) ? [] : $children;
}

add_filter('woocommerce_enqueue_styles', static function (array $styles): array {
    unset($styles['woocommerce-general']);
    unset($styles['woocommerce-layout']);
    unset($styles['woocommerce-smallscreen']);

    return $styles;
});

/**
 * Return the visible custom product attributes that can be used as shop facets.
 *
 * @return array<string, array{label: string, values: string[]}>
 */
function aromamatrix_get_filterable_product_attributes(): array
{
    // Version the cached facets so a schema/data cleanup can take effect immediately.
    $cache_key = 'aromamatrix_shop_filter_attributes_v2';
    $cached_attributes = get_transient($cache_key);

    if (is_array($cached_attributes)) {
        return $cached_attributes;
    }

    $attributes = [];
    $product_ids = wc_get_products([
        'status' => 'publish',
        'limit'  => -1,
        'return' => 'ids',
    ]);

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);

        if (! $product instanceof WC_Product) {
            continue;
        }

        foreach ($product->get_attributes() as $attribute) {
            if (! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible()) {
                continue;
            }

            $label = trim(wc_attribute_label($attribute->get_name()));
            $slug = sanitize_title($label);

            if ('' === $label || '' === $slug) {
                continue;
            }

            if (! isset($attributes[$slug])) {
                $attributes[$slug] = [
                    'label'  => $label,
                    'values' => [],
                ];
            }

            foreach ($attribute->get_options() as $option) {
                $value = is_scalar($option) ? trim((string) $option) : '';

                if ('' !== $value) {
                    $attributes[$slug]['values'][] = $value;
                }
            }
        }
    }

    foreach ($attributes as $slug => $attribute) {
        $values = array_values(array_unique($attribute['values']));
        natcasesort($values);

        if (count($values) < 2) {
            unset($attributes[$slug]);
            continue;
        }

        $attributes[$slug]['values'] = array_values($values);
    }

    uasort($attributes, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));

    set_transient($cache_key, $attributes, 6 * HOUR_IN_SECONDS);

    return $attributes;
}

/**
 * Return only the product attributes enabled for the Shop filter sidebar.
 *
 * @return array<string, array{label: string, values: string[]}>
 */
function aromamatrix_get_enabled_shop_filter_attributes(): array
{
    $attributes = aromamatrix_get_filterable_product_attributes();
    $enabled_attributes = get_option('aromamatrix_shop_filter_attributes', null);

    if (! is_array($enabled_attributes)) {
        return $attributes;
    }

    $enabled_attributes = array_map('sanitize_title', $enabled_attributes);

    return array_intersect_key($attributes, array_flip($enabled_attributes));
}

/**
 * Register a simple admin screen for choosing which attributes are filterable.
 */
add_action('admin_menu', static function (): void {
    add_theme_page(
        __('Shop Filters', 'aromamatrix'),
        __('Shop Filters', 'aromamatrix'),
        'manage_options',
        'aromamatrix-shop-filters',
        'aromamatrix_render_shop_filter_settings_page'
    );
});

add_action('admin_init', static function (): void {
    register_setting('aromamatrix_shop_filter_settings', 'aromamatrix_shop_filter_attributes', [
        'type'              => 'array',
        'sanitize_callback' => static function ($value): array {
            $available_attributes = aromamatrix_get_filterable_product_attributes();
            $requested_attributes = is_array($value) ? array_map('sanitize_title', $value) : [];

            return array_values(array_intersect(array_keys($available_attributes), $requested_attributes));
        },
        'default'           => null,
    ]);
});

/**
 * Render Appearance → Shop Filters.
 */
function aromamatrix_render_shop_filter_settings_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $attributes = aromamatrix_get_filterable_product_attributes();
    $saved_attributes = get_option('aromamatrix_shop_filter_attributes', null);
    $enabled_attributes = is_array($saved_attributes) ? $saved_attributes : array_keys($attributes);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Shop Filters', 'aromamatrix'); ?></h1>
        <p><?php esc_html_e('Choose which product attributes customers can use to filter the Shop catalogue.', 'aromamatrix'); ?></p>
        <form action="options.php" method="post">
            <?php settings_fields('aromamatrix_shop_filter_settings'); ?>
            <input type="hidden" name="aromamatrix_shop_filter_attributes[]" value="">
            <table class="form-table" role="presentation">
                <tbody>
                    <?php foreach ($attributes as $slug => $attribute) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($attribute['label']); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="aromamatrix_shop_filter_attributes[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $enabled_attributes, true)); ?>>
                                    <?php esc_html_e('Show this attribute as a Shop filter', 'aromamatrix'); ?>
                                </label>
                                <p class="description"><?php echo esc_html(sprintf(_n('%s available value', '%s available values', count($attribute['values']), 'aromamatrix'), number_format_i18n(count($attribute['values'])))); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Save filter settings', 'aromamatrix')); ?>
        </form>
    </div>
    <?php
}

/**
 * Clear the cached shop attribute facets when a product is updated.
 */
function aromamatrix_flush_shop_filter_attributes(): void
{
    delete_transient('aromamatrix_shop_filter_attributes_v2');
}

add_action('save_post_product', 'aromamatrix_flush_shop_filter_attributes');
add_action('woocommerce_product_set_attributes', 'aromamatrix_flush_shop_filter_attributes');
add_action('woocommerce_rest_insert_product_object', 'aromamatrix_flush_shop_filter_attributes', 10, 3);

/**
 * Return selected shop filters after validating them against the catalogue.
 *
 * @return array{
 *     categories: array<int, array{slug: string, label: string}>,
 *     tags: array<int, array{slug: string, label: string}>,
 *     attributes: array<string, array{label: string, values: string[]}>
 * }
 */
function aromamatrix_get_shop_filter_state(): array
{
    static $state = null;

    if (is_array($state)) {
        return $state;
    }

    $state = [
        'categories' => [],
        'tags'       => [],
        'attributes' => [],
    ];

    $requested_categories = isset($_GET['am_category']) ? (array) wp_unslash($_GET['am_category']) : [];
    foreach (array_unique(array_map('sanitize_title', $requested_categories)) as $slug) {
        $term = get_term_by('slug', $slug, 'product_cat');

        if ($term instanceof WP_Term) {
            $state['categories'][] = [
                'slug'  => $term->slug,
                'label' => $term->name,
            ];
        }
    }

    $requested_tags = isset($_GET['am_tag']) ? (array) wp_unslash($_GET['am_tag']) : [];
    foreach (array_unique(array_map('sanitize_title', $requested_tags)) as $slug) {
        $term = get_term_by('slug', $slug, 'product_tag');

        if ($term instanceof WP_Term) {
            $state['tags'][] = [
                'slug'  => $term->slug,
                'label' => $term->name,
            ];
        }
    }

    $available_attributes = aromamatrix_get_enabled_shop_filter_attributes();
    $requested_attributes = isset($_GET['am_attribute']) && is_array($_GET['am_attribute'])
        ? wp_unslash($_GET['am_attribute'])
        : [];

    foreach ($requested_attributes as $requested_slug => $requested_values) {
        $slug = sanitize_title((string) $requested_slug);

        if (! isset($available_attributes[$slug])) {
            continue;
        }

        $values = array_unique(array_map('sanitize_text_field', (array) $requested_values));
        $values = array_values(array_intersect($available_attributes[$slug]['values'], $values));

        if ($values) {
            $state['attributes'][$slug] = [
                'label'  => $available_attributes[$slug]['label'],
                'values' => $values,
            ];
        }
    }

    return $state;
}

/**
 * Build a canonical Shop URL while optionally removing individual filters.
 *
 * @param string[] $excluded_filters Filter identifiers, such as "tag:featured".
 */
function aromamatrix_get_shop_filter_url(array $excluded_filters = []): string
{
    $state = aromamatrix_get_shop_filter_state();
    $arguments = [];
    $search_term = aromamatrix_get_shop_search_term();

    if ('' !== $search_term && ! in_array('search', $excluded_filters, true)) {
        $arguments['am_search'] = $search_term;
    }

    foreach ($state['categories'] as $category) {
        if (! in_array('category:' . $category['slug'], $excluded_filters, true)) {
            $arguments['am_category'][] = $category['slug'];
        }
    }

    foreach ($state['tags'] as $tag) {
        if (! in_array('tag:' . $tag['slug'], $excluded_filters, true)) {
            $arguments['am_tag'][] = $tag['slug'];
        }
    }

    foreach ($state['attributes'] as $slug => $attribute) {
        foreach ($attribute['values'] as $value) {
            if (! in_array('attribute:' . $slug . ':' . $value, $excluded_filters, true)) {
                $arguments['am_attribute'][$slug][] = $value;
            }
        }
    }

    if (isset($_GET['orderby'])) {
        $arguments['orderby'] = wc_clean(wp_unslash($_GET['orderby']));
    }

    return add_query_arg($arguments, wc_get_page_permalink('shop'));
}

/**
 * Return the current header product-search term.
 */
function aromamatrix_get_shop_search_term(): string
{
    return isset($_GET['am_search'])
        ? sanitize_text_field(wp_unslash($_GET['am_search']))
        : '';
}

/**
 * Search the Shop catalogue by product title, excerpt, or SKU.
 */
add_filter('posts_where', static function (string $where, WP_Query $query): string {
    if (is_admin() || ! is_shop() || ! $query->is_main_query()) {
        return $where;
    }

    $search_term = aromamatrix_get_shop_search_term();

    if ('' === $search_term) {
        return $where;
    }

    global $wpdb;
    $like = '%' . $wpdb->esc_like($search_term) . '%';

    return $where . $wpdb->prepare(
        " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS aromamatrix_search_sku WHERE aromamatrix_search_sku.post_id = {$wpdb->posts}.ID AND aromamatrix_search_sku.meta_key = '_sku' AND aromamatrix_search_sku.meta_value LIKE %s))",
        $like,
        $like,
        $like
    );
}, 10, 2);

/**
 * Apply the selected category, tag, and custom-attribute facets to Shop results.
 */
add_action('woocommerce_product_query', static function (WP_Query $query): void {
    if (is_admin() || ! is_shop() || ! $query->is_main_query()) {
        return;
    }

    $state = aromamatrix_get_shop_filter_state();
    $tax_query = (array) $query->get('tax_query');
    $meta_query = (array) $query->get('meta_query');

    if ($state['categories']) {
        $tax_query[] = [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => wp_list_pluck($state['categories'], 'slug'),
            'operator' => 'IN',
        ];
    }

    if ($state['tags']) {
        $tax_query[] = [
            'taxonomy' => 'product_tag',
            'field'    => 'slug',
            'terms'    => wp_list_pluck($state['tags'], 'slug'),
            'operator' => 'IN',
        ];
    }

    if (count($tax_query) > 1 && ! isset($tax_query['relation'])) {
        $tax_query['relation'] = 'AND';
    }

    foreach ($state['attributes'] as $attribute) {
        $attribute_query = ['relation' => 'OR'];

        foreach ($attribute['values'] as $value) {
            $attribute_query[] = [
                'key'     => '_product_attributes',
                'value'   => '"' . $value . '"',
                'compare' => 'LIKE',
            ];
        }

        $meta_query[] = $attribute_query;
    }

    if ($tax_query) {
        $query->set('tax_query', $tax_query);
    }

    if ($meta_query) {
        $query->set('meta_query', $meta_query);
    }
});

/**
 * Render the selected filter chips directly above the Shop results.
 */
function aromamatrix_render_active_shop_filters(): void
{
    if (! is_shop()) {
        return;
    }

    $state = aromamatrix_get_shop_filter_state();
    $search_term = aromamatrix_get_shop_search_term();
    $has_filters = $search_term || $state['categories'] || $state['tags'] || $state['attributes'];

    if (! $has_filters) {
        return;
    }
    ?>
    <div class="shop-active-filters" aria-label="<?php esc_attr_e('Active filters', 'aromamatrix'); ?>">
        <span class="shop-active-filters__label"><?php esc_html_e('Active filters:', 'aromamatrix'); ?></span>
        <ul class="shop-active-filters__list">
            <?php if ('' !== $search_term) : ?>
                <li><a href="<?php echo esc_url(aromamatrix_get_shop_filter_url(['search'])); ?>" aria-label="<?php esc_attr_e('Clear product search', 'aromamatrix'); ?>"><?php echo esc_html(sprintf(__('Search: %s', 'aromamatrix'), $search_term)); ?><span aria-hidden="true">×</span></a></li>
            <?php endif; ?>
            <?php foreach ($state['categories'] as $category) : ?>
                <li><a href="<?php echo esc_url(aromamatrix_get_shop_filter_url(['category:' . $category['slug']])); ?>" aria-label="<?php echo esc_attr(sprintf(__('Remove category filter %s', 'aromamatrix'), $category['label'])); ?>"><?php echo esc_html(sprintf(__('Category: %s', 'aromamatrix'), $category['label'])); ?><span aria-hidden="true">×</span></a></li>
            <?php endforeach; ?>
            <?php foreach ($state['tags'] as $tag) : ?>
                <li><a href="<?php echo esc_url(aromamatrix_get_shop_filter_url(['tag:' . $tag['slug']])); ?>" aria-label="<?php echo esc_attr(sprintf(__('Remove tag filter %s', 'aromamatrix'), $tag['label'])); ?>"><?php echo esc_html(sprintf(__('Tag: %s', 'aromamatrix'), $tag['label'])); ?><span aria-hidden="true">×</span></a></li>
            <?php endforeach; ?>
            <?php foreach ($state['attributes'] as $slug => $attribute) : ?>
                <?php foreach ($attribute['values'] as $value) : ?>
                    <li><a href="<?php echo esc_url(aromamatrix_get_shop_filter_url(['attribute:' . $slug . ':' . $value])); ?>" aria-label="<?php echo esc_attr(sprintf(__('Remove %1$s filter %2$s', 'aromamatrix'), $attribute['label'], $value)); ?>"><?php echo esc_html($attribute['label'] . ': ' . $value); ?><span aria-hidden="true">×</span></a></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
        <a class="shop-active-filters__clear" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Clear all', 'aromamatrix'); ?></a>
    </div>
    <?php
}


/**
 * The Cart block exposes the product short description through the Store API.
 * Show the catalogue SKU there instead, while preserving descriptions everywhere
 * else on the storefront.
 */
function aromamatrix_is_store_api_cart_request(): bool
{
    if (! defined('REST_REQUEST') || ! REST_REQUEST) {
        return false;
    }

    $route = '';

    if (isset($GLOBALS['wp']) && isset($GLOBALS['wp']->query_vars['rest_route'])) {
        $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
    }

    if ('' === $route && isset($_GET['rest_route'])) {
        $route = (string) wp_unslash($_GET['rest_route']);
    }

    if ('' === $route && isset($_SERVER['REQUEST_URI'])) {
        $route = (string) parse_url((string) wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
    }

    return false !== strpos($route, '/wc/store/v1/cart');
}

add_filter('woocommerce_product_get_short_description', static function (string $short_description, WC_Product $product): string {
    if (! aromamatrix_is_store_api_cart_request()) {
        return $short_description;
    }

    $sku = $product->get_sku();

    if ('' === $sku) {
        return '';
    }

    return sprintf(
        '<span class="aromamatrix-cart-sku"><span>%1$s</span><strong>%2$s</strong></span>',
        esc_html__('SKU', 'woocommerce'),
        esc_html($sku)
    );
}, 10, 2);

add_action('wp', static function (): void {
    if (! class_exists('WooCommerce')) {
        return;
    }

    remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
    add_action('woocommerce_single_product_summary', 'aromamatrix_template_product_eyebrow', 4);
    add_action('woocommerce_single_product_summary', 'aromamatrix_template_product_purchase_feedback', 31);
    remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10);
    add_action('woocommerce_after_single_product_summary', 'aromamatrix_template_single_product_details', 10);
});

/**
 * Resolve the presentation type for fragrance and bottle catalog products.
 */
function aromamatrix_get_product_kind(WC_Product $product): string
{
    $terms = get_the_terms($product->get_id(), 'product_cat');

    if (! is_array($terms)) {
        return 'product';
    }

    foreach ($terms as $term) {
        if ('perfume-bottle' === $term->slug) {
            return 'bottle';
        }

        $ancestors = get_ancestors($term->term_id, 'product_cat', 'taxonomy');
        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term($ancestor_id, 'product_cat');
            if ($ancestor instanceof WP_Term && 'perfume-bottle' === $ancestor->slug) {
                return 'bottle';
            }
        }
    }

    return 'fragrance';
}

/**
 * Add the catalogue SKU above the product title.
 */
function aromamatrix_template_product_eyebrow(): void
{
    global $product;

    if (! $product instanceof WC_Product) {
        return;
    }

    ?>
    <div class="product-summary-identifiers">
        <?php if ($product->get_sku()) : ?>
            <span class="product-sku-badge">
                <span><?php esc_html_e('SKU', 'woocommerce'); ?></span>
                <strong><?php echo esc_html($product->get_sku()); ?></strong>
            </span>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Explain unavailable products and show the quantity-driven total for products
 * that can be added to the cart.
 */
function aromamatrix_template_product_purchase_feedback(): void
{
    global $product;

    if (! $product instanceof WC_Product) {
        return;
    }

    if (! $product->is_purchasable() || ! $product->is_in_stock()) {
        ?>
        <div class="aromamatrix-purchase-notice" role="status">
            <strong><?php esc_html_e('Online ordering is currently unavailable for this item.', 'aromamatrix'); ?></strong>
            <span><?php esc_html_e('Please contact us for availability and a quote.', 'aromamatrix'); ?></span>
        </div>
        <?php
        return;
    }

    $is_variable = $product->is_type('variable');
    $unit_price = $is_variable ? '' : (string) $product->get_price();
    $initial_total = '' !== $unit_price
        ? wc_price((float) $unit_price * $product->get_min_purchase_quantity())
        : '';
    ?>
    <output
        class="aromamatrix-product-total<?php echo '' === $unit_price ? ' is-awaiting-variation' : ''; ?>"
        data-unit-price="<?php echo esc_attr($unit_price); ?>"
        data-currency="<?php echo esc_attr(get_woocommerce_currency()); ?>"
        data-decimals="<?php echo esc_attr((string) wc_get_price_decimals()); ?>"
        <?php echo '' === $unit_price ? 'hidden' : ''; ?>
    >
        <span><?php esc_html_e('Total', 'aromamatrix'); ?></span>
        <strong data-product-total><?php echo wp_kses_post($initial_total); ?></strong>
    </output>
    <?php
}

/**
 * Explain the sample purchase action, or show its setup state when no price exists.
 */
function aromamatrix_template_sample_purchase_panel(): void
{
    global $product;

    if (! $product instanceof WC_Product) {
        return;
    }

    $kind = aromamatrix_get_product_kind($product);
    $is_bottle = 'bottle' === $kind;
    ?>
    <div class="sample-purchase-panel<?php echo $product->is_purchasable() ? '' : ' sample-purchase-panel--pending'; ?>">
        <span class="sample-purchase-panel__label">
            <?php echo esc_html($is_bottle ? __('Bottle Sample', 'aromamatrix') : __('Fragrance Sample', 'aromamatrix')); ?>
        </span>
        <strong>
            <?php
            echo esc_html(
                $product->is_purchasable()
                    ? ($is_bottle ? __('Review the component before bulk selection.', 'aromamatrix') : __('Evaluate the scent before project development.', 'aromamatrix'))
                    : __('Online sample ordering is being prepared.', 'aromamatrix')
            );
            ?>
        </strong>
        <p>
            <?php
            echo esc_html(
                $is_bottle
                    ? __('One empty bottle sample is supplied per cart item unless stated otherwise.', 'aromamatrix')
                    : __('One standard fragrance evaluation sample is supplied per cart item.', 'aromamatrix')
            );
            ?>
        </p>
        <?php if (! $product->is_purchasable()) : ?>
            <a class="sample-purchase-panel__link" href="https://www.aromamatrix.com/contact?request=samples#inquiry-form">
                <?php esc_html_e('Request Sample Availability →', 'aromamatrix'); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Present the description and fragrance attributes as one continuous section.
 */
function aromamatrix_template_single_product_details(): void
{
    global $product;

    if (! $product instanceof WC_Product) {
        return;
    }

    $description = $product->get_description();
    $has_attributes = $product->has_attributes();
    $kind = aromamatrix_get_product_kind($product);
    $details_title = 'bottle' === $kind ? __('Bottle Details', 'aromamatrix') : __('Fragrance Details', 'aromamatrix');
    $attributes_title = 'bottle' === $kind ? __('Bottle Specifications', 'aromamatrix') : __('Fragrance Attributes', 'aromamatrix');

    if (! $description && ! $has_attributes) {
        return;
    }
    ?>
    <section class="aromamatrix-product-details aromamatrix-product-details--<?php echo esc_attr($kind); ?>" aria-labelledby="aromamatrix-product-details-title">
        <div class="aromamatrix-product-details__heading">
            <span><?php esc_html_e('Product Evaluation', 'aromamatrix'); ?></span>
            <h2 id="aromamatrix-product-details-title"><?php echo esc_html($details_title); ?></h2>
        </div>

        <div class="aromamatrix-product-details__grid">
            <?php if ($description) : ?>
                <div class="aromamatrix-product-details__description">
                    <?php echo wp_kses_post(apply_filters('the_content', $description)); ?>
                </div>
            <?php endif; ?>

            <?php if ($has_attributes) : ?>
                <aside class="aromamatrix-product-details__attributes" aria-label="<?php echo esc_attr($attributes_title); ?>">
                    <h3><?php echo esc_html($attributes_title); ?></h3>
                    <?php wc_display_product_attributes($product); ?>
                </aside>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

add_filter('woocommerce_product_single_add_to_cart_text', static function (string $text): string {
    return __('Add to Cart', 'aromamatrix');
});

add_filter('woocommerce_product_add_to_cart_text', static function (string $text, WC_Product $product): string {
    if (! $product->is_purchasable()) {
        return __('View Details', 'aromamatrix');
    }

    return __('Add to Cart', 'aromamatrix');
}, 10, 2);

/**
 * Let buyers choose a quantity directly from expandable category previews.
 */
add_filter('woocommerce_loop_add_to_cart_link', static function (string $link, WC_Product $product): string {
    if (
        ! wc_get_loop_prop('aromamatrix_category_preview')
        || ! $product->is_type('simple')
        || ! $product->is_purchasable()
        || ! $product->is_in_stock()
    ) {
        return $link;
    }

    $quantity = woocommerce_quantity_input([
        'min_value'   => $product->get_min_purchase_quantity(),
        'max_value'   => $product->get_max_purchase_quantity(),
        'input_value' => $product->get_min_purchase_quantity(),
    ], $product, false);

    return sprintf(
        '<form class="aromamatrix-loop-cart" action="%1$s" method="post" enctype="multipart/form-data"><div class="aromamatrix-loop-quantity"><button class="aromamatrix-loop-quantity__button" type="button" data-quantity-action="decrease" aria-label="%2$s">−</button>%3$s<button class="aromamatrix-loop-quantity__button" type="button" data-quantity-action="increase" aria-label="%4$s">+</button></div><button type="submit" name="add-to-cart" value="%5$d" class="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.1 10.1h10.6l2-7H7.5M10 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/></svg><span>%6$s</span></button></form>',
        esc_url($product->add_to_cart_url()),
        esc_attr__('Decrease quantity', 'aromamatrix'),
        $quantity,
        esc_attr__('Increase quantity', 'aromamatrix'),
        $product->get_id(),
        esc_html__('Add to Cart', 'aromamatrix')
    );
}, 10, 2);

/**
 * A zero price is a placeholder for the B2B sample workflow, not a retail
 * price. Suppress it on bottle detail pages so it is not mistaken for a free
 * product; the sample call-to-action remains directly below the selector.
 */
add_filter('woocommerce_get_price_html', static function (string $price_html, WC_Product $product): string {
    if (
        is_product()
        && 'bottle' === aromamatrix_get_product_kind($product)
        && '' !== $product->get_price()
        && 0.0 === (float) $product->get_price()
    ) {
        return '';
    }

    return $price_html;
}, 10, 2);

add_filter('woocommerce_add_to_cart_fragments', static function (array $fragments): array {
    ob_start();
    ?>
    <span class="header-cart__count"><?php echo esc_html((string) WC()->cart->get_cart_contents_count()); ?></span>
    <?php
    $fragments['.header-cart__count'] = (string) ob_get_clean();

    return $fragments;
});

add_filter('loop_shop_columns', static fn (): int => 4);
add_filter('loop_shop_per_page', static fn (): int => 16);

add_filter('single_product_archive_thumbnail_size', static function (string $size): string {
    return wc_get_loop_prop('aromamatrix_category_preview') ? 'full' : $size;
});

add_filter('woocommerce_product_get_image', static function (string $image, WC_Product $product): string {
    if (
        ! wc_get_loop_prop('aromamatrix_category_preview')
        || '' === $product->get_sku()
    ) {
        return $image;
    }

    return sprintf(
        '<span class="aromamatrix-preview-media">%1$s<span class="aromamatrix-preview-media__sku">%2$s</span></span>',
        $image,
        esc_html(sprintf(__('SKU: %s', 'aromamatrix'), $product->get_sku()))
    );
}, 10, 2);

remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);
add_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_price', 20);

add_action('woocommerce_before_shop_loop_item_title', static function (): void {
    global $product;

    if (! $product instanceof WC_Product || wc_get_loop_prop('aromamatrix_category_preview')) {
        return;
    }

    $kind = aromamatrix_get_product_kind($product);
    $label = 'bottle' === $kind ? __('Bottle', 'aromamatrix') : __('Fragrance', 'aromamatrix');
    ?>
    <span class="loop-product-kind loop-product-kind--<?php echo esc_attr($kind); ?>"><?php echo esc_html($label); ?></span>
    <?php
}, 9);

add_action('woocommerce_after_shop_loop_item_title', static function (): void {
    global $product;

    if (! $product instanceof WC_Product || wc_get_loop_prop('aromamatrix_category_preview')) {
        return;
    }

    $sku = $product->get_sku();

    if ('' === $sku) {
        return;
    }
    ?>
    <span class="loop-product-sku"><?php echo esc_html(sprintf(__('SKU: %s', 'aromamatrix'), $sku)); ?></span>
    <?php
}, 7);

add_filter('woocommerce_output_related_products_args', static function (array $args): array {
    $args['posts_per_page'] = 4;
    $args['columns'] = 4;

    return $args;
});

/**
 * Related and upsell products are rendered by WooCommerce in the single
 * product summary. Give those loops the same card markup as every catalogue
 * page, including the image SKU badge and quantity-aware cart control.
 */
add_action('woocommerce_after_single_product_summary', static function (): void {
    wc_set_loop_prop('aromamatrix_category_preview', true);
}, 14);

add_action('woocommerce_after_single_product_summary', static function (): void {
    wc_set_loop_prop('aromamatrix_category_preview', false);
}, 21);

add_filter('woocommerce_breadcrumb_defaults', static function (array $defaults): array {
    $defaults['delimiter'] = '<span class="breadcrumb-separator">/</span>';
    $defaults['wrap_before'] = '<nav class="woocommerce-breadcrumb" aria-label="' . esc_attr__('Breadcrumb', 'aromamatrix') . '">';
    $defaults['wrap_after'] = '</nav>';

    return $defaults;
});

add_filter('excerpt_more', static fn (): string => '…');

add_action('after_setup_theme', static function (): void {
    add_image_size('aromamatrix-product-card', 720, 860, true);
    add_image_size('aromamatrix-collection', 900, 1120, true);
});
