<?php
/**
 * Site header.
 *
 * @package Aromamatrix
 */

$cart_count = class_exists('WooCommerce') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
$shop_url = class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/');
$shop_search_term = isset($_GET['am_search']) ? sanitize_text_field(wp_unslash($_GET['am_search'])) : '';
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111111">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#primary">
    <?php esc_html_e('Skip to content', 'aromamatrix'); ?>
</a>
<div class="site-notice">
    <?php esc_html_e('Designer Fragrances · Wholesale Pricing · US Warehouse · Fast Domestic Shipping', 'aromamatrix'); ?>
</div>
<header class="site-header">
    <div class="site-header__inner aroma-container">
        <a class="site-branding" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php esc_attr_e('13799.com home', 'aromamatrix'); ?>">
            <img
                class="site-branding__logo"
                src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/13799-logo-header.png'); ?>"
                width="2172"
                height="724"
                alt="<?php esc_attr_e('13799.com Perfume Wholesale', 'aromamatrix'); ?>"
                style="width: clamp(130px, 20vw, 200px); height: auto;"
            >
        </a>

        <div class="header-actions">
            <nav id="site-navigation" class="primary-navigation" aria-label="<?php esc_attr_e('Primary menu', 'aromamatrix'); ?>">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container'      => false,
                    'fallback_cb'    => 'aromamatrix_fallback_menu',
                    'depth'          => 1,
                ]);
                ?>
            </nav>

            <div class="header-utilities">
                <?php if (class_exists('WooCommerce')) : ?>
                    <?php if (is_user_logged_in()) : ?>
                        <a class="header-account header-account--icon no-prefetch" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" aria-label="<?php esc_attr_e('My account', 'aromamatrix'); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.25"></circle><circle cx="12" cy="9.25" r="2.25"></circle><path d="M7.8 17.1c.9-1.55 2.3-2.35 4.2-2.35s3.3.8 4.2 2.35"></path></svg>
                            <span class="screen-reader-text"><?php esc_html_e('My account', 'aromamatrix'); ?></span>
                        </a>
                    <?php else : ?>
                        <a class="header-account header-account--sign-in no-prefetch" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" data-account-modal-open>
                            <?php esc_html_e('Sign in', 'aromamatrix'); ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <button class="header-search-toggle" type="button" aria-expanded="false" aria-controls="header-product-search" data-header-search-open>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.75" cy="10.75" r="5.75"></circle><path d="m15.2 15.2 4.3 4.3"></path></svg>
                    <span class="screen-reader-text"><?php esc_html_e('Search products', 'aromamatrix'); ?></span>
                </button>

                <?php if (class_exists('WooCommerce')) : ?>
                    <?php if (is_user_logged_in()) : ?>
                        <a class="header-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>" aria-label="<?php esc_attr_e('View cart', 'aromamatrix'); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h2l1.5 10h9.8l1.7-7H7M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/></svg>
                            <span class="header-cart__count"><?php echo esc_html((string) $cart_count); ?></span>
                        </a>
                    <?php else : ?>
                        <a class="header-cart no-prefetch" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" data-account-modal-open aria-label="<?php esc_attr_e('Sign in to view cart', 'aromamatrix'); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h2l1.5 10h9.8l1.7-7H7M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 0 0 0 0 2Z"/></svg>
                            <span class="header-cart__count"><?php echo esc_html((string) $cart_count); ?></span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <button class="menu-toggle" type="button" aria-controls="site-navigation" aria-expanded="false">
                <span></span><span></span><span></span>
                <span class="screen-reader-text"><?php esc_html_e('Open menu', 'aromamatrix'); ?></span>
            </button>
        </div>
    </div>

    <div id="header-product-search" class="header-search-panel" hidden data-header-search-panel>
        <form class="header-search-form" action="<?php echo esc_url($shop_url); ?>" method="get" role="search">
            <label class="screen-reader-text" for="header-product-search-input"><?php esc_html_e('Search products by name or SKU', 'aromamatrix'); ?></label>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.75" cy="10.75" r="5.75"></circle><path d="m15.2 15.2 4.3 4.3"></path></svg>
            <input id="header-product-search-input" name="am_search" type="search" value="<?php echo esc_attr($shop_search_term); ?>" placeholder="<?php esc_attr_e('Search by product name or SKU', 'aromamatrix'); ?>" autocomplete="off">
            <button type="submit"><?php esc_html_e('Search', 'aromamatrix'); ?></button>
            <button class="header-search-form__close" type="button" aria-label="<?php esc_attr_e('Close search', 'aromamatrix'); ?>" data-header-search-close>×</button>
        </form>
    </div>
</header>
