<?php
/**
 * Fallback template.
 *
 * @package Shop13799
 */

get_header();
?>
<main id="primary" class="site-main shop-container">
    <?php if (have_posts()) : ?>
        <div class="content-grid">
            <?php while (have_posts()) : ?>
                <?php the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('content-card'); ?>>
                    <?php if (has_post_thumbnail()) : ?>
                        <a class="content-card__image" href="<?php the_permalink(); ?>"><?php the_post_thumbnail('large'); ?></a>
                    <?php endif; ?>
                    <h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <div class="entry-summary"><?php the_excerpt(); ?></div>
                </article>
            <?php endwhile; ?>
        </div>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <section class="content-card">
            <h1 class="entry-title"><?php esc_html_e('Nothing found', 'shop-13799'); ?></h1>
        </section>
    <?php endif; ?>
</main>
<?php
get_footer();
