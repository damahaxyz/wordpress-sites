<?php
/**
 * WooCommerce wrapper.
 *
 * @package MangaShop
 */

declare(strict_types=1);

get_header();
?>
<?php if (! is_product() && ! manga_shop_is_catalogue_view()) : ?>
    <section class="archive-hero<?php echo is_shop() ? ' archive-hero--shop' : ''; ?>">
        <div class="manga-container">
            <?php woocommerce_breadcrumb(); ?>
            <p class="section-kicker"><?php esc_html_e('Curated for anime fans', 'manga-shop'); ?></p>
            <h1><?php echo esc_html(woocommerce_page_title(false)); ?></h1>
            <?php if (is_product_taxonomy()) : ?>
                <div class="archive-hero__description"><?php echo wp_kses_post(term_description()); ?></div>
            <?php else : ?>
                <p class="archive-hero__description"><?php esc_html_e('Explore accessories, collectibles and gifts across every series in our store.', 'manga-shop'); ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<main class="site-main content-shell <?php echo is_product() ? 'product-shell' : 'archive-shell'; ?><?php echo manga_shop_is_catalogue_view() ? ' archive-shell--shop' : ''; ?>" id="main">
    <div class="manga-container">
        <?php if (is_product()) : ?><div class="product-breadcrumb"><?php woocommerce_breadcrumb(); ?></div><?php endif; ?>
        <?php if (manga_shop_is_catalogue_view()) : ?>
            <div class="shop-catalogue">
                <?php get_template_part('template-parts/shop-filters'); ?>
                <div class="shop-catalogue__content">
                    <?php manga_shop_render_active_filters(); ?>
                    <div class="woocommerce">
                        <?php woocommerce_content(); ?>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <?php woocommerce_content(); ?>
        <?php endif; ?>
    </div>
</main>
<?php
get_footer();
