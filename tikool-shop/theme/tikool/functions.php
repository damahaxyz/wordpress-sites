<?php
/**
 * Theme setup, assets, and WooCommerce integration.
 *
 * @package TikoolShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once get_template_directory() . '/homepage-banner.php';
require_once get_template_directory() . '/homepage-products.php';

function tikool_setup(): void
{
    load_theme_textdomain('tikool', get_template_directory() . '/languages');

    add_theme_support('automatic-feed-links');
    add_theme_support('custom-logo', [
        'height'      => 64,
        'width'       => 64,
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
    // Keep enough pixels for wide product cards on high-density displays.
    add_image_size('tikool-product-card', 1080, 1166, true);

    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    add_theme_support('wc-product-gallery-zoom');

    register_nav_menus([
        'primary' => __('Primary menu', 'tikool'),
        'footer'  => __('Footer menu', 'tikool'),
    ]);
}
add_action('after_setup_theme', 'tikool_setup');

function tikool_enqueue_assets(): void
{
    $style_path = get_stylesheet_directory() . '/style.css';
    $woocommerce_path = get_template_directory() . '/assets/css/woocommerce.css';
    $script_path = get_template_directory() . '/assets/js/theme.js';

    wp_enqueue_style(
        'tikool-fonts',
        'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap',
        [],
        null
    );

    wp_enqueue_style(
        'tikool',
        get_stylesheet_uri(),
        ['tikool-fonts'],
        is_file($style_path) ? (string) filemtime($style_path) : (string) wp_get_theme()->get('Version')
    );

    if (class_exists('WooCommerce') && is_file($woocommerce_path)) {
        wp_enqueue_style(
            'tikool-woocommerce',
            get_template_directory_uri() . '/assets/css/woocommerce.css',
            ['tikool'],
            (string) filemtime($woocommerce_path)
        );
    }

    if (is_file($script_path)) {
        $script_dependencies = [];
        if (class_exists('WooCommerce') && wp_script_is('wc-cart-fragments', 'registered')) {
            wp_enqueue_script('wc-cart-fragments');
            $script_dependencies = ['jquery', 'wc-cart-fragments'];
        }

        wp_enqueue_script(
            'tikool-theme',
            get_template_directory_uri() . '/assets/js/theme.js',
            $script_dependencies,
            (string) filemtime($script_path),
            true
        );
        wp_localize_script('tikool-theme', 'tikoolCart', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tikool_cart_quantity'),
            'error'   => __('The cart could not be updated. Please try again.', 'tikool'),
        ]);
    }
}
add_action('wp_enqueue_scripts', 'tikool_enqueue_assets');

function tikool_fallback_menu(): void
{
    $shop_url = class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop');
    ?>
    <ul class="menu">
        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'tikool'); ?></a></li>
        <li><a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Shop all', 'tikool'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/#how-it-works')); ?>"><?php esc_html_e('How it works', 'tikool'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/#memorial')); ?>"><?php esc_html_e('Pet memorials', 'tikool'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/track-order/')); ?>"><?php esc_html_e('Track order', 'tikool'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Contact', 'tikool'); ?></a></li>
    </ul>
    <?php
}

function tikool_cart_count(): int
{
    if (! class_exists('WooCommerce') || ! WC()->cart) {
        return 0;
    }

    return WC()->cart->get_cart_contents_count();
}

function tikool_render_cart_count_badge(): void
{
    $count = tikool_cart_count();
    ?>
    <span class="header-cart__count" aria-hidden="true"<?php echo $count < 1 ? ' hidden' : ''; ?>><?php echo esc_html((string) $count); ?></span>
    <?php
}

function tikool_render_cart_drawer_content(): void
{
    $is_empty = ! WC()->cart || WC()->cart->is_empty();
    ?>
    <div class="tikool-mini-cart__content">
        <div class="tikool-mini-cart__body">
            <?php woocommerce_mini_cart(); ?>
        </div>
        <div class="tikool-mini-cart__actions">
            <a class="shop-button shop-button--dark<?php echo $is_empty ? ' is-disabled' : ''; ?>" href="<?php echo esc_url(wc_get_checkout_url()); ?>"<?php echo $is_empty ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><?php esc_html_e('Checkout', 'tikool'); ?></a>
            <a class="shop-button tikool-mini-cart__view-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('View cart', 'tikool'); ?></a>
        </div>
    </div>
    <?php
}

function tikool_cart_fragments(array $fragments): array
{
    ob_start();
    tikool_render_cart_count_badge();
    $fragments['.header-cart__count'] = (string) ob_get_clean();

    ob_start();
    tikool_render_cart_drawer_content();
    $fragments['.tikool-mini-cart__content'] = (string) ob_get_clean();

    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'tikool_cart_fragments');

add_action('wp', static function (): void {
    if (class_exists('WooCommerce')) {
        remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
    }
});

/**
 * Keep the gallery and summary inside one bounded layout so the sticky gallery
 * stops before the product tabs and related products.
 */
function tikool_product_layout_open(): void
{
    if (function_exists('is_product') && is_product()) {
        echo '<div class="tikool-product-layout">';
    }
}
add_action('woocommerce_before_single_product_summary', 'tikool_product_layout_open', 1);

function tikool_product_gallery_column_open(): void
{
    if (function_exists('is_product') && is_product()) {
        echo '<div class="tikool-product-gallery-column">';
    }
}
add_action('woocommerce_before_single_product_summary', 'tikool_product_gallery_column_open', 5);

function tikool_product_gallery_column_close(): void
{
    if (function_exists('is_product') && is_product()) {
        echo '</div>';
    }
}
add_action('woocommerce_before_single_product_summary', 'tikool_product_gallery_column_close', 25);

function tikool_product_layout_close(): void
{
    if (function_exists('is_product') && is_product()) {
        echo '</div>';
    }
}
add_action('woocommerce_after_single_product_summary', 'tikool_product_layout_close', 1);

function tikool_quantity_minus_button(): void
{
    if (function_exists('is_product') && is_product()) {
        printf(
            '<button type="button" class="tikool-qty-button tikool-qty-button--minus" data-tikool-qty-change="-1" aria-label="%s">&minus;</button>',
            esc_attr__('Decrease quantity', 'tikool')
        );
    }
}
add_action('woocommerce_before_quantity_input_field', 'tikool_quantity_minus_button');

function tikool_quantity_plus_button(): void
{
    if (function_exists('is_product') && is_product()) {
        printf(
            '<button type="button" class="tikool-qty-button tikool-qty-button--plus" data-tikool-qty-change="1" aria-label="%s">&plus;</button>',
            esc_attr__('Increase quantity', 'tikool')
        );
    }
}
add_action('woocommerce_after_quantity_input_field', 'tikool_quantity_plus_button');

add_filter('loop_shop_columns', static fn (): int => 3);
add_filter('loop_shop_per_page', static fn (): int => 12);

// WooCommerce defaults to a 300px square thumbnail in product loops. The
// storefront displays those images substantially wider, so use the dedicated
// high-resolution card crop instead of stretching the small thumbnail.
add_filter('single_product_archive_thumbnail_size', static fn (): string => 'tikool-product-card');

add_filter('woocommerce_output_related_products_args', static function (array $args): array {
    $args['posts_per_page'] = 3;
    $args['columns'] = 3;

    return $args;
});

add_filter('body_class', static function (array $classes): array {
    $classes[] = 'tikool-storefront';

    return $classes;
});
