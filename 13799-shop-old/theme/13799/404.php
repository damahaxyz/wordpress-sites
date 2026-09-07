<?php
/**
 * Not-found template.
 *
 * @package Shop13799
 */

get_header();
?>
<main id="primary" class="site-main shop-container">
    <section class="content-card content-card--page empty-state">
        <span class="section-kicker">404</span>
        <h1 class="entry-title"><?php esc_html_e('Page not found', 'shop-13799'); ?></h1>
        <p><?php esc_html_e('The page may have moved or no longer exists.', 'shop-13799'); ?></p>
        <a class="shop-button" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Return home', 'shop-13799'); ?></a>
    </section>
</main>
<?php
get_footer();
