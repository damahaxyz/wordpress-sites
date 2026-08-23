<?php
/**
 * Site footer.
 *
 * @package Aromamatrix
 */

$shop_url = class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#products');
$contact = aromamatrix_get_contact_details();
?>
<footer id="contact" class="site-footer">
    <div class="site-footer__main aroma-container">
        <div class="footer-brand">
            <a href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php esc_attr_e('13799.com home', 'aromamatrix'); ?>">
                <img
                    class="footer-brand__logo"
                    src="<?php echo esc_url(get_template_directory_uri() . '/assets/images/13799-logo-header.png'); ?>"
                    width="2172"
                    height="724"
                    alt="<?php esc_attr_e('13799.com Perfume Wholesale', 'aromamatrix'); ?>"
                >
            </a>
            <p><?php esc_html_e('Wholesale designer fragrances for retailers, resellers and fragrance businesses, supported by US warehouse inventory and domestic fulfillment.', 'aromamatrix'); ?></p>
        </div>

        <div class="footer-column">
            <h2><?php esc_html_e('Shop', 'aromamatrix'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(home_url('/product-category/perfumes/')); ?>"><?php esc_html_e('Designer Fragrances', 'aromamatrix'); ?></a></li>
                <li><a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('All Fragrances', 'aromamatrix'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/#us-warehouse')); ?>"><?php esc_html_e('US Warehouse', 'aromamatrix'); ?></a></li>
            </ul>
        </div>

        <div class="footer-column">
            <h2><?php esc_html_e('Wholesale Support', 'aromamatrix'); ?></h2>
            <ul>
                <li><a href="<?php echo esc_url(home_url('/b2b-ordering-information/')); ?>"><?php esc_html_e('B2B Ordering Information', 'aromamatrix'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Contact Us', 'aromamatrix'); ?></a></li>
            </ul>
        </div>

        <div class="footer-column footer-column--contact">
            <h2><?php esc_html_e('Contact Us', 'aromamatrix'); ?></h2>
            <p><?php esc_html_e('Wholesale account and order support', 'aromamatrix'); ?></p>
            <p><a href="mailto:<?php echo esc_attr($contact['email']); ?>"><?php echo esc_html($contact['email']); ?></a></p>
            <p><a href="https://wa.me/<?php echo esc_attr($contact['whatsapp_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html('WhatsApp ' . $contact['whatsapp']); ?></a></p>
        </div>
    </div>

    <div class="site-footer__bottom aroma-container">
        <p>
            <?php
            printf(
                esc_html__('© %s 13799.com. All rights reserved.', 'aromamatrix'),
                esc_html(wp_date('Y'))
            );
            ?>
        </p>
        <p><?php esc_html_e('Designer Fragrance Wholesale · US Warehouse Inventory', 'aromamatrix'); ?></p>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
