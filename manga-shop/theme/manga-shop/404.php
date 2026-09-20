<?php
/**
 * Not-found template.
 *
 * @package MangaShop
 */

declare(strict_types=1);

get_header();
?>
<main class="site-main content-shell" id="main">
    <div class="manga-container">
        <h1 class="entry-title"><?php esc_html_e('Page not found', 'manga-shop'); ?></h1>
        <p><?php esc_html_e('The page may have moved. Try searching or return to the shop.', 'manga-shop'); ?></p>
        <?php get_search_form(); ?>
    </div>
</main>
<?php
get_footer();
