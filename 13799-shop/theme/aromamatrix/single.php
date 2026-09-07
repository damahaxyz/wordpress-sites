<?php
/**
 * Single post template.
 *
 * @package Aromamatrix
 */

get_header();
?>
<main id="primary" class="site-main">
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('content-card'); ?>>
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <div class="entry-content">
                <?php the_content(); ?>
            </div>
        </article>
    <?php endwhile; ?>
</main>
<?php
get_footer();
