<?php
/**
 * Site header.
 *
 * @package Shop13799
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#172554">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#primary">
    <?php esc_html_e('Skip to content', 'shop-13799'); ?>
</a>
<div class="site-notice">
    <?php esc_html_e('Welcome to our online shop', 'shop-13799'); ?>
</div>
<header class="site-header">
    <div class="site-header__inner shop-container">
        <a class="site-branding" href="<?php echo esc_url(home_url('/')); ?>">
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
                <span class="site-branding__mark" aria-hidden="true">13</span>
            <?php endif; ?>
            <span class="site-branding__copy">
                <span class="site-branding__name"><?php bloginfo('name'); ?></span>
                <?php if (get_bloginfo('description')) : ?>
                    <span class="site-branding__tagline"><?php bloginfo('description'); ?></span>
                <?php endif; ?>
            </span>
        </a>

        <div class="header-actions">
            <nav id="site-navigation" class="primary-navigation" aria-label="<?php esc_attr_e('Primary menu', 'shop-13799'); ?>">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container'      => false,
                    'fallback_cb'    => 'shop13799_fallback_menu',
                    'depth'          => 1,
                ]);
                ?>
            </nav>

            <?php if (class_exists('WooCommerce')) : ?>
                <a class="header-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>" aria-label="<?php esc_attr_e('View cart', 'shop-13799'); ?>">
                    <span aria-hidden="true">○</span>
                    <span class="header-cart__count"><?php echo esc_html((string) shop13799_cart_count()); ?></span>
                </a>
            <?php endif; ?>

            <button class="menu-toggle" type="button" aria-controls="site-navigation" aria-expanded="false">
                <span></span><span></span><span></span>
                <span class="screen-reader-text"><?php esc_html_e('Open menu', 'shop-13799'); ?></span>
            </button>
        </div>
    </div>
</header>
