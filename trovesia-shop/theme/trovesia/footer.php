<?php
/**
 * Site footer.
 *
 * @package TrovesiaShop
 */

?>
<footer class="site-footer">
    <div class="site-footer__main shop-container">
        <div class="site-footer__brand">
            <p class="site-footer__wordmark trovesia-wordmark trovesia-wordmark--footer" aria-label="Trovesia">
                <span class="trovesia-wordmark__text">TROVESIA</span>
                <span class="trovesia-wordmark__accent" aria-hidden="true"></span>
            </p>
            <p><?php esc_html_e('Thoughtfully presented seasonal finds, clear product guidance and responsive support for every order.', 'trovesia'); ?></p>
            <a href="mailto:service@trovesia.com">service@trovesia.com</a>
        </div>
        <div>
            <h2><?php esc_html_e('Explore', 'trovesia'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop')); ?>"><?php esc_html_e('Shop all', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/#how-it-works')); ?>"><?php esc_html_e('How it works', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/#gift-set')); ?>"><?php esc_html_e('Inside the gift set', 'trovesia'); ?></a></li>
            </ul>
        </div>
        <div>
            <h2><?php esc_html_e('Customer care', 'trovesia'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(home_url('/track-order/')); ?>"><?php esc_html_e('Track order', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Contact us', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/refund-policy/')); ?>"><?php esc_html_e('Refund policy', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>"><?php esc_html_e('Privacy policy', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/terms-of-service/')); ?>"><?php esc_html_e('Terms of service', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/shipping-policy/')); ?>"><?php esc_html_e('Shipping policy', 'trovesia'); ?></a></li>
                <?php if (class_exists('WooCommerce')) : ?>
                    <li><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('My account', 'trovesia'); ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="site-footer__promise">
            <h2><?php esc_html_e('Need a hand?', 'trovesia'); ?></h2>
            <p><?php esc_html_e('Our customer-care team can help with product questions, delivery updates and returns.', 'trovesia'); ?></p>
            <a class="text-link" href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Talk to us', 'trovesia'); ?> <span aria-hidden="true">↗</span></a>
        </div>
    </div>
    <div class="site-footer__bottom shop-container">
        <span>
            <?php
            printf(
                esc_html__('© %1$s %2$s', 'trovesia'),
                esc_html(wp_date('Y')),
                esc_html(get_bloginfo('name') ?: 'Trovesia')
            );
            ?>
        </span>
        <span><?php esc_html_e('Secure payments · Thoughtful support', 'trovesia'); ?></span>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
