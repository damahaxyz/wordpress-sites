<?php
/**
 * Default content template.
 *
 * @package MangaShop
 */

declare(strict_types=1);

get_header();
?>
<main class="site-main content-shell" id="main">
    <div class="manga-container">
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <h1 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h1>
                    <div class="entry-content"><?php the_excerpt(); ?></div>
                </article>
            <?php endwhile; ?>
            <?php the_posts_navigation(); ?>
        <?php else : ?>
            <p><?php esc_html_e('No content found.', 'manga-shop'); ?></p>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();
