<?php
/**
 * Page template.
 *
 * @package TrovesiaShop
 */

get_header();
?>
<main id="primary" class="site-main shop-container">
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <?php $is_account = function_exists('is_account_page') && is_account_page(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('content-card content-card--page' . ($is_account ? ' trovesia-account-page' : '')); ?>>
            <?php if ($is_account) : ?>
                <header class="trovesia-account-heading">
                    <p class="trovesia-account-eyebrow"><?php esc_html_e('My Trovesia', 'trovesia'); ?></p>
                    <h1 class="entry-title"><?php the_title(); ?></h1>
                    <p><?php
                        if (is_wc_endpoint_url('lost-password')) {
                            esc_html_e('Let us help you get back to your account.', 'trovesia');
                        } elseif (is_user_logged_in()) {
                            esc_html_e('Your orders, personal details and everyday essentials, all in one place.', 'trovesia');
                        } else {
                            esc_html_e('A little space for your orders, saved details and next discovery.', 'trovesia');
                        }
                    ?></p>
                </header>
            <?php else : ?>
                <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php endif; ?>
            <div class="entry-content"><?php the_content(); ?></div>
        </article>
    <?php endwhile; ?>
</main>
<?php
get_footer();
