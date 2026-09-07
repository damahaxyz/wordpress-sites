<?php
/**
 * Storefront landing page.
 *
 * @package Shop13799
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
                <span class="section-kicker"><?php esc_html_e('13799 Shop', 'shop-13799'); ?></span>
                <h1><?php esc_html_e('A storefront ready to become your own.', 'shop-13799'); ?></h1>
                <p><?php esc_html_e('Replace this introductory copy, colors, products, and calls to action directly in the custom theme.', 'shop-13799'); ?></p>
                <div class="button-row">
                    <a class="shop-button" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Browse products', 'shop-13799'); ?></a>
                    <a class="shop-button shop-button--secondary" href="<?php echo esc_url(home_url('/contact/')); ?>"><?php esc_html_e('Contact us', 'shop-13799'); ?></a>
                </div>
            </div>
            <div class="home-hero__visual" aria-hidden="true">
                <span>13799</span>
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
                    <span class="section-kicker"><?php esc_html_e('Collections', 'shop-13799'); ?></span>
                    <h2><?php esc_html_e('Shop by category', 'shop-13799'); ?></h2>
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
                            <?php echo $image ? wp_kses_post($image) : '<span class="category-card__placeholder">13799</span>'; ?>
                        </span>
                        <span class="category-card__content">
                            <strong><?php echo esc_html($category->name); ?></strong>
                            <span>
                                <?php
                                printf(
                                    esc_html(_n('%s product', '%s products', $category->count, 'shop-13799')),
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
                    <span class="section-kicker"><?php esc_html_e('New arrivals', 'shop-13799'); ?></span>
                    <h2><?php esc_html_e('Latest products', 'shop-13799'); ?></h2>
                </div>
                <a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('View all →', 'shop-13799'); ?></a>
            </div>
            <?php if ($woocommerce_ready) : ?>
                <?php echo do_shortcode('[products limit="8" columns="4" orderby="date" order="DESC"]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else : ?>
                <div class="empty-state">
                    <h3><?php esc_html_e('Install WooCommerce to display products.', 'shop-13799'); ?></h3>
                    <p><?php esc_html_e('The custom theme already includes storefront and product-page styling.', 'shop-13799'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php
get_footer();
