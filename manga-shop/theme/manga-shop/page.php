<?php
/**
 * Page template.
 *
 * @package MangaShop
 */

declare(strict_types=1);

get_header();
?>
<main class="site-main content-shell" id="main">
    <div class="manga-container">
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <div class="entry-content"><?php the_content(); ?></div>
            </article>
        <?php endwhile; ?>
    </div>
</main>
<?php
get_footer();
