<?php
/**
 * Site footer.
 *
 * @package TikoolShop
 */

?>
<footer class="site-footer">
    <div class="site-footer__main shop-container">
        <div class="site-footer__brand">
            <p class="site-footer__wordmark tikool-wordmark tikool-wordmark--footer" aria-label="Tikool">
                <span class="tikool-wordmark__text">TIKOOL</span>
                <span class="tikool-wordmark__accent" aria-hidden="true"></span>
            </p>
            <p><?php esc_html_e('Custom pet keepsakes made from the little details that make them unforgettable.', 'tikool'); ?></p>
            <a href="mailto:service@tikool.com">service@tikool.com</a>
        </div>
        <div>
            <h2><?php esc_html_e('Explore', 'tikool'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop')); ?>"><?php esc_html_e('Shop all', 'tikool'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/#how-it-works')); ?>"><?php esc_html_e('How it works', 'tikool'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/#memorial')); ?>"><?php esc_html_e('Pet memorials', 'tikool'); ?></a></li>
            </ul>
        </div>
        <div>
            <h2><?php esc_html_e('Customer care', 'tikool'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(home_url('/track-order/')); ?>"><?php esc_html_e('Track order', 'tikool'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Contact us', 'tikool'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/refund-policy/')); ?>"><?php esc_html_e('Refund policy', 'tikool'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>"><?php esc_html_e('Privacy policy', 'tikool'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/terms-of-service/')); ?>"><?php esc_html_e('Terms of service', 'tikool'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/shipping-policy/')); ?>"><?php esc_html_e('Shipping policy', 'tikool'); ?></a></li>
                <?php if (class_exists('WooCommerce')) : ?>
                    <li><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('My account', 'tikool'); ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="site-footer__promise">
            <h2><?php esc_html_e('Need a hand?', 'tikool'); ?></h2>
            <p><?php esc_html_e('Our customer-care team can help with product questions, delivery updates and returns.', 'tikool'); ?></p>
            <a class="text-link" href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Talk to us', 'tikool'); ?> <span aria-hidden="true">↗</span></a>
        </div>
    </div>
    <div class="site-footer__bottom shop-container">
        <span>
            <?php
            printf(
                esc_html__('© %1$s %2$s', 'tikool'),
                esc_html(wp_date('Y')),
                esc_html(get_bloginfo('name') ?: 'Tikool')
            );
            ?>
        </span>
        <span><?php esc_html_e('Secure payments · Thoughtful support', 'tikool'); ?></span>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
