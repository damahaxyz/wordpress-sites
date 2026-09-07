<?php
/**
 * Page template.
 *
 * @package Shop13799
 */

get_header();
?>
<main id="primary" class="site-main shop-container">
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('content-card content-card--page'); ?>>
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <div class="entry-content"><?php the_content(); ?></div>
        </article>
    <?php endwhile; ?>
</main>
<?php
get_footer();
