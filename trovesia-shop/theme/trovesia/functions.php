<?php
/**
 * Theme setup, assets, and WooCommerce integration.
 *
 * @package TrovesiaShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

function trovesia_setup(): void
{
    load_theme_textdomain('trovesia', get_template_directory() . '/languages');

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

    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    add_theme_support('wc-product-gallery-zoom');

    register_nav_menus([
        'primary' => __('Primary menu', 'trovesia'),
        'footer'  => __('Footer menu', 'trovesia'),
    ]);
}
add_action('after_setup_theme', 'trovesia_setup');

function trovesia_enqueue_assets(): void
{
    $style_path = get_stylesheet_directory() . '/style.css';
    $woocommerce_path = get_template_directory() . '/assets/css/woocommerce.css';
    $script_path = get_template_directory() . '/assets/js/theme.js';

    wp_enqueue_style(
        'trovesia',
        get_stylesheet_uri(),
        [],
        is_file($style_path) ? (string) filemtime($style_path) : (string) wp_get_theme()->get('Version')
    );

    if (class_exists('WooCommerce') && is_file($woocommerce_path)) {
        wp_enqueue_style(
            'trovesia-woocommerce',
            get_template_directory_uri() . '/assets/css/woocommerce.css',
            ['trovesia'],
            (string) filemtime($woocommerce_path)
        );
    }

    if (is_file($script_path)) {
        wp_enqueue_script(
            'trovesia-theme',
            get_template_directory_uri() . '/assets/js/theme.js',
            [],
            (string) filemtime($script_path),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'trovesia_enqueue_assets');

function trovesia_fallback_menu(): void
{
    $shop_url = class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#products');
    ?>
    <ul class="menu">
        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'trovesia'); ?></a></li>
        <li><a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Shop', 'trovesia'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/about/')); ?>"><?php esc_html_e('About', 'trovesia'); ?></a></li>
        <li><a href="<?php echo esc_url(home_url('/contact/')); ?>"><?php esc_html_e('Contact', 'trovesia'); ?></a></li>
    </ul>
    <?php
}

function trovesia_cart_count(): int
{
    if (! class_exists('WooCommerce') || ! WC()->cart) {
        return 0;
    }

    return WC()->cart->get_cart_contents_count();
}

function trovesia_cart_fragments(array $fragments): array
{
    ob_start();
    ?>
    <span class="header-cart__count"><?php echo esc_html((string) trovesia_cart_count()); ?></span>
    <?php
    $fragments['.header-cart__count'] = (string) ob_get_clean();

    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'trovesia_cart_fragments');

add_action('wp', static function (): void {
    if (class_exists('WooCommerce')) {
        remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
    }
});

add_filter('loop_shop_columns', static fn (): int => 4);
add_filter('loop_shop_per_page', static fn (): int => 12);

add_filter('woocommerce_output_related_products_args', static function (array $args): array {
    $args['posts_per_page'] = 4;
    $args['columns'] = 4;

    return $args;
});
