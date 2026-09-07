<?php
/**
 * Archive template.
 *
 * @package Shop13799
 */

get_header();
?>
<main id="primary" class="site-main shop-container">
    <header class="archive-header">
        <?php the_archive_title('<h1 class="entry-title">', '</h1>'); ?>
        <?php the_archive_description('<div class="archive-description">', '</div>'); ?>
    </header>
    <?php if (have_posts()) : ?>
        <div class="content-grid">
            <?php while (have_posts()) : ?>
                <?php the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('content-card'); ?>>
                    <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <?php the_excerpt(); ?>
                </article>
            <?php endwhile; ?>
        </div>
        <?php the_posts_pagination(); ?>
    <?php endif; ?>
</main>
<?php
get_footer();
