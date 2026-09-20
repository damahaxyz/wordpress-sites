<?php
/**
 * Site header.
 *
 * @package MangaShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#main"><?php esc_html_e('Skip to content', 'manga-shop'); ?></a>

<?php
$announcementMessages = apply_filters(
    'manga_shop_announcement_messages',
    [__('Free shipping on every order', 'manga-shop'), __('90-day after-sale service', 'manga-shop')]
);
$announcementPrimary = is_array($announcementMessages) ? trim((string) ($announcementMessages[0] ?? '')) : '';
$announcementSecondary = is_array($announcementMessages) ? trim((string) ($announcementMessages[1] ?? '')) : '';
?>
<?php if ($announcementPrimary !== '' || $announcementSecondary !== '') : ?>
    <div class="announcement-bar">
        <div class="manga-container announcement-bar__inner">
            <?php if ($announcementPrimary !== '') : ?>
                <span class="announcement-bar__item announcement-bar__item--primary"><?php echo manga_shop_icon('spark'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html($announcementPrimary); ?></span>
            <?php endif; ?>
            <?php if ($announcementSecondary !== '') : ?>
                <span class="announcement-bar__item announcement-bar__item--secondary"><?php echo esc_html($announcementSecondary); ?></span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<header class="site-header" data-site-header>
    <div class="manga-container site-header__inner">
        <div class="site-branding"><?php manga_shop_brand(); ?></div>

        <nav class="primary-navigation" id="primary-navigation" aria-label="<?php esc_attr_e('Primary menu', 'manga-shop'); ?>">
            <div class="mobile-menu__heading">
                <span><?php esc_html_e('Menu', 'manga-shop'); ?></span>
                <strong><?php esc_html_e('Explore the collection', 'manga-shop'); ?></strong>
            </div>
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container'      => false,
                'menu_class'     => 'primary-menu',
                'fallback_cb'    => 'manga_shop_fallback_menu',
            ]);
            ?>
        </nav>

        <div class="site-header__actions">
            <button class="icon-button search-toggle" type="button" aria-expanded="false" aria-controls="site-search-panel">
                <?php echo manga_shop_icon('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <span class="screen-reader-text"><?php esc_html_e('Search products', 'manga-shop'); ?></span>
            </button>
            <?php if (class_exists('WooCommerce')) : ?>
                <a class="icon-button header-account" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"<?php echo ! is_user_logged_in() ? ' data-manga-account-trigger="login" aria-haspopup="dialog" aria-controls="manga-account-modal" aria-expanded="false"' : ''; ?> aria-label="<?php echo esc_attr(is_user_logged_in() ? __('My account', 'manga-shop') : __('Log in or create an account', 'manga-shop')); ?>">
                    <?php echo manga_shop_icon('account'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </a>
                <a class="icon-button header-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>" aria-label="<?php esc_attr_e('Open shopping cart', 'manga-shop'); ?>" aria-controls="site-mini-cart" aria-expanded="false" data-mini-cart-open>
                    <?php echo manga_shop_icon('bag'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <span class="header-cart__count" aria-label="<?php esc_attr_e('Items in cart', 'manga-shop'); ?>"><?php echo esc_html((string) manga_shop_cart_count()); ?></span>
                </a>
            <?php endif; ?>
            <button class="icon-button menu-toggle" type="button" aria-expanded="false" aria-controls="primary-navigation">
                <span class="menu-toggle__open"><?php echo manga_shop_icon('menu'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span class="menu-toggle__close"><?php echo manga_shop_icon('close'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <span class="screen-reader-text"><?php esc_html_e('Menu', 'manga-shop'); ?></span>
            </button>
        </div>
    </div>

    <div class="site-search-panel" id="site-search-panel" hidden>
        <div class="manga-container site-search-panel__inner">
            <div class="site-search-panel__heading">
                <div>
                    <span><?php esc_html_e('Search the collection', 'manga-shop'); ?></span>
                    <strong><?php esc_html_e('What are you looking for?', 'manga-shop'); ?></strong>
                </div>
                <button class="search-close" type="button" aria-label="<?php esc_attr_e('Close search', 'manga-shop'); ?>">
                    <?php echo manga_shop_icon('close'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
            </div>
            <form class="site-product-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                <label class="screen-reader-text" for="site-product-search-field"><?php esc_html_e('Search for products', 'manga-shop'); ?></label>
                <span class="site-product-search__icon" aria-hidden="true"><?php echo manga_shop_icon('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <input id="site-product-search-field" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php esc_attr_e('Search products, characters or series…', 'manga-shop'); ?>" autocomplete="off">
                <input type="hidden" name="post_type" value="product">
                <button type="submit"><?php esc_html_e('Search', 'manga-shop'); ?></button>
            </form>
        </div>
    </div>
</header>
<button class="mobile-menu-backdrop" type="button" tabindex="-1" aria-hidden="true" aria-label="<?php esc_attr_e('Close menu', 'manga-shop'); ?>"></button>
