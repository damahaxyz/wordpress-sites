<?php
/**
 * Storefront landing page.
 *
 * @package MangaShop
 */

declare(strict_types=1);

$heroId = manga_shop_media_id('banner0');
$categorySlugs = ['one-piece', 'naruto', 'demon-slayer', 'dragen-ball-d', 'others'];
$categories = [];
foreach ($categorySlugs as $slug) {
    $term = get_term_by('slug', $slug, 'product_cat');
    if ($term instanceof WP_Term && $term->count > 0) {
        $categories[] = $term;
    }
}

get_header();
?>
<main class="site-main" id="main">
    <section class="home-hero">
        <div class="home-hero__media" aria-hidden="true">
            <?php if ($heroId > 0) : ?>
                <?php echo wp_get_attachment_image($heroId, 'full', false, ['fetchpriority' => 'high', 'loading' => 'eager', 'sizes' => '100vw']); ?>
            <?php endif; ?>
        </div>
        <div class="home-hero__shade"></div>
        <div class="manga-container home-hero__content" data-reveal>
            <p class="hero-kicker"><?php esc_html_e('Collect your favorite worlds', 'manga-shop'); ?></p>
            <h1><?php esc_html_e('Anime finds with main-character energy.', 'manga-shop'); ?></h1>
            <p class="home-hero__lead"><?php esc_html_e('Discover expressive accessories, gifts and collectibles inspired by the stories you keep close.', 'manga-shop'); ?></p>
            <div class="hero-actions">
                <a class="manga-button manga-button--light" href="<?php echo esc_url(class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop')); ?>">
                    <?php esc_html_e('Explore all products', 'manga-shop'); ?><?php echo manga_shop_icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </a>
                <a class="manga-button manga-button--glass" href="<?php echo esc_url(home_url('/product-category/one-piece/')); ?>"><?php esc_html_e('Shop One Piece', 'manga-shop'); ?></a>
            </div>
        </div>
        <div class="hero-number" aria-hidden="true">01</div>
    </section>

    <section class="trust-rail" aria-label="<?php esc_attr_e('Store benefits', 'manga-shop'); ?>">
        <div class="manga-container trust-rail__grid">
            <div><span>01</span><strong><?php esc_html_e('Free shipping', 'manga-shop'); ?></strong><small><?php esc_html_e('Across the whole store', 'manga-shop'); ?></small></div>
            <div><span>02</span><strong><?php esc_html_e('Fan-first selection', 'manga-shop'); ?></strong><small><?php esc_html_e('Fresh drops and familiar heroes', 'manga-shop'); ?></small></div>
            <div><span>03</span><strong><?php esc_html_e('90-day service', 'manga-shop'); ?></strong><small><?php esc_html_e('Support after your order arrives', 'manga-shop'); ?></small></div>
            <div><span>04</span><strong><?php esc_html_e('Protected checkout', 'manga-shop'); ?></strong><small><?php esc_html_e('Pay securely with PayPal', 'manga-shop'); ?></small></div>
        </div>
    </section>

    <?php if ($categories !== []) : ?>
        <section class="shop-section series-section" aria-labelledby="series-title">
            <div class="manga-container">
                <div class="section-heading" data-reveal>
                    <div><p class="section-kicker"><?php esc_html_e('Find your universe', 'manga-shop'); ?></p><h2 id="series-title"><?php esc_html_e('Shop by series', 'manga-shop'); ?></h2></div>
                    <a class="text-link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('View the full collection', 'manga-shop'); ?><?php echo manga_shop_icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
                </div>
                <div class="series-grid">
                    <?php foreach ($categories as $index => $category) : ?>
                        <?php $thumbnailId = (int) get_term_meta($category->term_id, 'thumbnail_id', true); ?>
                        <a class="series-card series-card--<?php echo esc_attr((string) ($index + 1)); ?>" href="<?php echo esc_url(get_term_link($category)); ?>" data-reveal>
                            <?php if ($thumbnailId > 0) : ?>
                                <?php echo wp_get_attachment_image($thumbnailId, 'manga-category-card', false, ['loading' => 'lazy']); ?>
                            <?php endif; ?>
                            <span class="series-card__scrim"></span>
                            <span class="series-card__content"><small><?php echo esc_html(sprintf(_n('%s product', '%s products', $category->count, 'manga-shop'), number_format_i18n($category->count))); ?></small><strong><?php echo esc_html($category->name); ?></strong><?php echo manga_shop_icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="shop-section products-section" id="shop" aria-labelledby="new-title">
        <div class="manga-container">
            <div class="section-heading" data-reveal>
                <div><p class="section-kicker"><?php esc_html_e('Just landed', 'manga-shop'); ?></p><h2 id="new-title"><?php esc_html_e('New arrivals', 'manga-shop'); ?></h2></div>
                <a class="text-link" href="<?php echo esc_url(add_query_arg('orderby', 'date', wc_get_page_permalink('shop'))); ?>"><?php esc_html_e('See what is new', 'manga-shop'); ?><?php echo manga_shop_icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
            </div>
            <?php echo do_shortcode('[products limit="4" columns="4" orderby="date" order="DESC"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
    </section>

    <section class="editorial-banner">
        <div class="manga-container editorial-banner__inner" data-reveal>
            <div>
                <p class="section-kicker"><?php esc_html_e('Built for fans', 'manga-shop'); ?></p>
                <h2><?php esc_html_e('Small details. Big fandom energy.', 'manga-shop'); ?></h2>
            </div>
            <p><?php esc_html_e('From desk companions to daily accessories, every collection is arranged to make finding a thoughtful anime gift feel effortless.', 'manga-shop'); ?></p>
            <a class="circle-link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" aria-label="<?php esc_attr_e('Browse the collection', 'manga-shop'); ?>"><?php echo manga_shop_icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
        </div>
    </section>

    <section class="shop-section products-section products-section--warm" aria-labelledby="popular-title">
        <div class="manga-container">
            <div class="section-heading" data-reveal>
                <div><p class="section-kicker"><?php esc_html_e('Fan favorites', 'manga-shop'); ?></p><h2 id="popular-title"><?php esc_html_e('Popular right now', 'manga-shop'); ?></h2></div>
                <a class="text-link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Shop everything', 'manga-shop'); ?><?php echo manga_shop_icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
            </div>
            <?php echo do_shortcode('[products limit="8" columns="4" orderby="popularity"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
    </section>

    <section class="closing-cta">
        <div class="manga-container closing-cta__inner" data-reveal>
            <p class="section-kicker"><?php esc_html_e('Your shelf is waiting', 'manga-shop'); ?></p>
            <h2><?php esc_html_e('Bring your favorite story home.', 'manga-shop'); ?></h2>
            <a class="manga-button manga-button--dark" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Start exploring', 'manga-shop'); ?><?php echo manga_shop_icon('arrow'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
        </div>
    </section>
</main>
<?php
get_footer();
