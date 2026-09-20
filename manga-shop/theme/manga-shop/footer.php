<?php
/**
 * Site footer.
 *
 * @package MangaShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$policyLinks = [
    __('Privacy policy', 'manga-shop') => home_url('/privacy-policy/'),
    __('Terms and conditions', 'manga-shop') => home_url('/terms-and-conditions/'),
    __('Returns and refunds', 'manga-shop') => home_url('/return-refund-policy/'),
    __('Shipping information', 'manga-shop') => home_url('/shipping-information/'),
];
?>
<footer class="site-footer">
    <div class="manga-container footer-grid">
        <div class="footer-brand">
            <div class="site-branding"><?php manga_shop_brand(); ?></div>
            <p><?php esc_html_e('Expressive anime-inspired finds for collectors, gift-givers and everyday fans.', 'manga-shop'); ?></p>
            <div class="social-links">
                <a href="https://twitter.com/mangafunshop1" rel="noopener noreferrer" target="_blank">X / Twitter</a>
                <a href="https://facebook.com/mangafunshop/" rel="noopener noreferrer" target="_blank">Facebook</a>
            </div>
        </div>
        <div>
            <p class="footer-title"><?php esc_html_e('Shop', 'manga-shop'); ?></p>
            <ul>
                <li><a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('All products', 'manga-shop'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/product-category/one-piece/')); ?>">One Piece</a></li>
                <li><a href="<?php echo esc_url(home_url('/product-category/naruto/')); ?>">Naruto</a></li>
                <li><a href="<?php echo esc_url(home_url('/product-category/demon-slayer/')); ?>">Demon Slayer</a></li>
            </ul>
        </div>
        <div>
            <p class="footer-title"><?php esc_html_e('Customer care', 'manga-shop'); ?></p>
            <ul>
                <?php foreach ($policyLinks as $label => $url) : ?><li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a></li><?php endforeach; ?>
            </ul>
        </div>
        <div class="footer-contact">
            <p class="footer-title"><?php esc_html_e('Let us help', 'manga-shop'); ?></p>
            <p><?php esc_html_e('Questions about a product or order?', 'manga-shop'); ?></p>
            <a class="footer-email" href="mailto:support@mangaproduct.com">support@mangaproduct.com</a>
            <small><?php esc_html_e('Support days: Monday–Friday', 'manga-shop'); ?></small>
        </div>
    </div>
    <div class="manga-container footer-bottom">
        <p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('All rights reserved.', 'manga-shop'); ?></p>
        <p><?php esc_html_e('Secure payments powered by PayPal', 'manga-shop'); ?></p>
    </div>
</footer>

<?php if (class_exists('WooCommerce') && function_exists('woocommerce_mini_cart')) : ?>
    <div class="mini-cart-backdrop" data-mini-cart-close aria-hidden="true"></div>
    <aside class="mini-cart-panel" id="site-mini-cart" role="dialog" aria-modal="true" aria-labelledby="mini-cart-title" aria-hidden="true" inert>
        <div class="mini-cart-panel__header">
            <div>
                <span><?php esc_html_e('Your selection', 'manga-shop'); ?></span>
                <h2 id="mini-cart-title"><?php esc_html_e('Shopping cart', 'manga-shop'); ?></h2>
            </div>
            <button class="mini-cart-panel__close" type="button" data-mini-cart-close aria-label="<?php esc_attr_e('Close shopping cart', 'manga-shop'); ?>">
                <?php echo manga_shop_icon('close'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </button>
        </div>
        <div class="mini-cart-panel__content widget_shopping_cart_content">
            <?php woocommerce_mini_cart(); ?>
        </div>
    </aside>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
