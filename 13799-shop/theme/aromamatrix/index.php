<?php
/**
 * Fallback template.
 *
 * @package Aromamatrix
 */

get_header();
?>
<main id="primary" class="site-main">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : ?>
            <?php the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('content-card'); ?>>
                <h1 class="entry-title">
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                </h1>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>

        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <section class="content-card">
            <h1 class="entry-title"><?php esc_html_e('Nothing found', 'aromamatrix'); ?></h1>
        </section>
    <?php endif; ?>
</main>
<?php
get_footer();
