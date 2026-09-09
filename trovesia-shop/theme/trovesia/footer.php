<?php
/**
 * Site footer.
 *
 * @package TrovesiaShop
 */

$privacy_url = get_privacy_policy_url();
?>
<footer class="site-footer">
    <div class="site-footer__main shop-container">
        <div class="site-footer__brand">
            <p class="site-footer__wordmark">TROVESIA</p>
            <p><?php esc_html_e('A quieter, more considered approach to botanical hair care—selected for everyday rituals that feel good to keep.', 'trovesia'); ?></p>
            <a href="mailto:hello@trovesia.com">hello@trovesia.com</a>
        </div>
        <div>
            <h2><?php esc_html_e('Explore', 'trovesia'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop')); ?>"><?php esc_html_e('Shop all', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/#ritual')); ?>"><?php esc_html_e('The ritual', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/#ingredients')); ?>"><?php esc_html_e('Ingredients', 'trovesia'); ?></a></li>
            </ul>
        </div>
        <div>
            <h2><?php esc_html_e('Customer care', 'trovesia'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(home_url('/track-order/')); ?>"><?php esc_html_e('Track order', 'trovesia'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Contact us', 'trovesia'); ?></a></li>
                <?php if ($privacy_url) : ?>
                    <li><a href="<?php echo esc_url($privacy_url); ?>"><?php esc_html_e('Privacy policy', 'trovesia'); ?></a></li>
                <?php endif; ?>
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
