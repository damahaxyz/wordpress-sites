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

<?php
$announcement_text = class_exists('Trovesia\\Plugin\\Site_Experience')
    ? Trovesia\Plugin\Site_Experience::announcement_text()
    : '';
$announcement_url = class_exists('Trovesia\\Plugin\\Site_Experience')
    ? Trovesia\Plugin\Site_Experience::announcement_url()
    : '';
?>
<?php if ($announcement_text !== '') : ?>
    <div class="announcement-bar" aria-label="<?php esc_attr_e('Site announcement', 'trovesia'); ?>">
        <div class="announcement-bar__track">
            <?php if ($announcement_url !== '') : ?>
                <a class="announcement-bar__message" href="<?php echo esc_url($announcement_url); ?>">
                    <i aria-hidden="true">✦</i><span><?php echo esc_html($announcement_text); ?></span>
                </a>
            <?php else : ?>
                <p class="announcement-bar__message">
                    <i aria-hidden="true">✦</i><span><?php echo esc_html($announcement_text); ?></span>
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

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
                <span class="trovesia-wordmark trovesia-wordmark--header" aria-hidden="true">
                    <span class="trovesia-wordmark__text">TROVESIA</span>
                    <span class="trovesia-wordmark__accent"></span>
                </span>
                <span class="screen-reader-text"><?php echo esc_html(get_bloginfo('name') ?: 'Trovesia'); ?></span>
            <?php endif; ?>
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
            <?php if (class_exists('WooCommerce')) : ?>
                <a class="header-icon header-account" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"<?php echo ! is_user_logged_in() ? ' data-trovesia-account-trigger="login" aria-haspopup="dialog"' : ''; ?> aria-label="<?php echo esc_attr(is_user_logged_in() ? __('My account', 'trovesia') : __('Log in or create an account', 'trovesia')); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M5.5 20c.6-4 2.8-6 6.5-6s5.9 2 6.5 6"></path></svg>
                </a>
                <button class="header-icon header-cart" type="button" aria-controls="trovesia-cart-drawer" aria-expanded="false" aria-label="<?php esc_attr_e('Open shopping cart', 'trovesia'); ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6.5 8h11l-.8 12H7.3L6.5 8Z"></path><path d="M9 9V6a3 3 0 0 1 6 0v3"></path></svg>
                    <?php trovesia_render_cart_count_badge(); ?>
                </button>
            <?php endif; ?>
            <button class="menu-toggle" type="button" aria-controls="site-navigation" aria-expanded="false">
                <span></span><span></span>
                <span class="screen-reader-text"><?php esc_html_e('Open menu', 'trovesia'); ?></span>
            </button>
        </div>
    </div>
</header>

<?php if (class_exists('WooCommerce')) : ?>
    <button class="trovesia-cart-backdrop" type="button" data-cart-drawer-close aria-label="<?php esc_attr_e('Close shopping cart', 'trovesia'); ?>" tabindex="-1"></button>
    <aside class="trovesia-cart-drawer" id="trovesia-cart-drawer" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="trovesia-cart-drawer-title">
        <header class="trovesia-cart-drawer__header">
            <div>
                <p><?php esc_html_e('Your selection', 'trovesia'); ?></p>
                <h2 id="trovesia-cart-drawer-title"><?php esc_html_e('Shopping cart', 'trovesia'); ?></h2>
            </div>
            <button class="trovesia-cart-drawer__close" type="button" data-cart-drawer-close aria-label="<?php esc_attr_e('Close shopping cart', 'trovesia'); ?>">&times;</button>
        </header>
        <?php trovesia_render_cart_drawer_content(); ?>
    </aside>
<?php endif; ?>
