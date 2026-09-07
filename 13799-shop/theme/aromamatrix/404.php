<?php
/**
 * Not-found template.
 *
 * @package Aromamatrix
 */

get_header();
?>
<main id="primary" class="site-main">
    <section class="content-card">
        <h1 class="entry-title"><?php esc_html_e('Page not found', 'aromamatrix'); ?></h1>
        <p><?php esc_html_e('The page may have moved or no longer exists.', 'aromamatrix'); ?></p>
        <?php get_search_form(); ?>
    </section>
</main>
<?php
get_footer();
