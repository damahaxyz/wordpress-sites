<?php
/**
 * Site footer.
 *
 * @package Shop13799
 */
?>
<footer class="site-footer">
    <div class="site-footer__inner shop-container">
        <div>
            <h2><?php bloginfo('name'); ?></h2>
            <?php if (get_bloginfo('description')) : ?>
                <p><?php bloginfo('description'); ?></p>
            <?php endif; ?>
        </div>
        <nav aria-label="<?php esc_attr_e('Footer menu', 'shop-13799'); ?>">
            <?php
            wp_nav_menu([
                'theme_location' => 'footer',
                'container'      => false,
                'fallback_cb'    => false,
                'depth'          => 1,
            ]);
            ?>
        </nav>
    </div>
    <div class="site-footer__bottom shop-container">
        <?php
        printf(
            esc_html__('© %1$s %2$s. All rights reserved.', 'shop-13799'),
            esc_html(wp_date('Y')),
            esc_html(get_bloginfo('name'))
        );
        ?>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
