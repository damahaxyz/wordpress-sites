<?php
/**
 * Site header.
 *
 * @package TrovesiaShop
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#183c33">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#primary"><?php esc_html_e('Skip to content', 'trovesia'); ?></a>

<div class="announcement-bar" aria-label="<?php esc_attr_e('Store benefits', 'trovesia'); ?>">
    <div class="announcement-bar__track">
        <span><i aria-hidden="true">✦</i><?php esc_html_e('Complimentary shipping', 'trovesia'); ?></span>
        <span><i aria-hidden="true">↺</i><?php esc_html_e('30-day returns', 'trovesia'); ?></span>
        <span><i aria-hidden="true">◇</i><?php esc_html_e('Secure checkout', 'trovesia'); ?></span>
    </div>
</div>

<header class="site-header">
    <div class="site-header__inner shop-container">
        <a class="site-branding" href="<?php echo esc_url(home_url('/')); ?>" rel="home">
            <?php if (has_custom_logo()) : ?>
                <span class="site-branding__logo">
                    <?php
                    echo wp_kses_post(
                        wp_get_attachment_image(
                            (int) get_theme_mod('custom_logo'),
                            'full',
                            false,
                            ['class' => 'custom-logo']
                        )
                    );
                    ?>
                </span>
            <?php else : ?>
                <span class="site-branding__mark" aria-hidden="true">T</span>
            <?php endif; ?>
            <span class="site-branding__copy">
                <span class="site-branding__name">TROVESIA</span>
                <span class="site-branding__tagline"><?php esc_html_e('Botanical hair rituals', 'trovesia'); ?></span>
            </span>
        </a>

        <nav id="site-navigation" class="primary-navigation" aria-label="<?php esc_attr_e('Primary menu', 'trovesia'); ?>">
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container'      => false,
                'fallback_cb'    => 'trovesia_fallback_menu',
                'depth'          => 1,
            ]);
            ?>
        </nav>

        <div class="header-actions">
            <a class="header-icon header-search" href="<?php echo esc_url(home_url('/?s=')); ?>" aria-label="<?php esc_attr_e('Search', 'trovesia'); ?>">
                <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.75"></circle><path d="m16 16 4 4"></path></svg>
            </a>
            <?php if (class_exists('WooCommerce')) : ?>
                <a class="header-icon header-account" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" aria-label="<?php esc_attr_e('My account', 'trovesia'); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 20c.6-4 2.8-6 6.5-6s5.9 2 6.5 6"></path></svg>
                </a>
                <a class="header-icon header-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>" aria-label="<?php esc_attr_e('View cart', 'trovesia'); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6.5 8h11l-.8 12H7.3L6.5 8Z"></path><path d="M9 9V6a3 3 0 0 1 6 0v3"></path></svg>
                    <span class="header-cart__count"><?php echo esc_html((string) trovesia_cart_count()); ?></span>
                </a>
            <?php endif; ?>
            <button class="menu-toggle" type="button" aria-controls="site-navigation" aria-expanded="false">
                <span></span><span></span>
                <span class="screen-reader-text"><?php esc_html_e('Open menu', 'trovesia'); ?></span>
            </button>
        </div>
    </div>
</header>
