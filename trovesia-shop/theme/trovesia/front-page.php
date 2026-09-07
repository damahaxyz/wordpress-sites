<?php
/**
 * Storefront landing page.
 *
 * @package TrovesiaShop
 */

get_header();

$woocommerce_ready = class_exists('WooCommerce');
$shop_url = $woocommerce_ready ? wc_get_page_permalink('shop') : home_url('/#products');
$categories = [];

if ($woocommerce_ready) {
    $categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'number'     => 3,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ]);

    if (is_wp_error($categories)) {
        $categories = [];
    }
}
?>
<main id="primary" class="site-main site-main--wide">
    <section class="home-hero">
        <div class="home-hero__inner shop-container">
            <div class="home-hero__copy">
                <span class="section-kicker"><?php esc_html_e('Trovesia · Curated Fragrance', 'trovesia'); ?></span>
                <h1><?php esc_html_e('Rare scents, thoughtfully discovered.', 'trovesia'); ?></h1>
                <p><?php esc_html_e('Explore a considered collection of fine fragrance, chosen for character, craft, and the stories they leave behind.', 'trovesia'); ?></p>
                <div class="button-row">
                    <a class="shop-button" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Browse products', 'trovesia'); ?></a>
                    <a class="shop-button shop-button--secondary" href="<?php echo esc_url(home_url('/contact/')); ?>"><?php esc_html_e('Contact us', 'trovesia'); ?></a>
                </div>
            </div>
            <div class="home-hero__visual" aria-hidden="true">
                <span>T</span>
            </div>
        </div>
    </section>

    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : ?>
            <?php the_post(); ?>
            <?php if (trim((string) get_the_content()) !== '') : ?>
                <section class="home-section home-editor-content shop-container">
                    <?php the_content(); ?>
                </section>
            <?php endif; ?>
        <?php endwhile; ?>
    <?php endif; ?>

    <?php if ($categories) : ?>
        <section class="home-section shop-container">
            <div class="section-heading">
                <div>
                    <span class="section-kicker"><?php esc_html_e('Collections', 'trovesia'); ?></span>
                    <h2><?php esc_html_e('Shop by category', 'trovesia'); ?></h2>
                </div>
            </div>
            <div class="category-grid">
                <?php foreach ($categories as $category) : ?>
                    <?php
                    $thumbnail_id = (int) get_term_meta($category->term_id, 'thumbnail_id', true);
                    $image = $thumbnail_id ? wp_get_attachment_image($thumbnail_id, 'large') : '';
                    ?>
                    <a class="category-card" href="<?php echo esc_url(get_term_link($category)); ?>">
                        <span class="category-card__media">
                            <?php echo $image ? wp_kses_post($image) : '<span class="category-card__placeholder">T</span>'; ?>
                        </span>
                        <span class="category-card__content">
                            <strong><?php echo esc_html($category->name); ?></strong>
                            <span>
                                <?php
                                printf(
                                    esc_html(_n('%s product', '%s products', $category->count, 'trovesia')),
                                    esc_html(number_format_i18n($category->count))
                                );
                                ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section id="products" class="home-section home-section--muted">
        <div class="shop-container">
            <div class="section-heading">
                <div>
                    <span class="section-kicker"><?php esc_html_e('New arrivals', 'trovesia'); ?></span>
                    <h2><?php esc_html_e('Latest products', 'trovesia'); ?></h2>
                </div>
                <a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('View all →', 'trovesia'); ?></a>
            </div>
            <?php if ($woocommerce_ready) : ?>
                <?php echo do_shortcode('[products limit="8" columns="4" orderby="date" order="DESC"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else : ?>
                <div class="empty-state">
                    <h3><?php esc_html_e('Install WooCommerce to display products.', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('The custom theme already includes storefront and product-page styling.', 'trovesia'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php
get_footer();
