<?php
/**
 * Storefront landing page.
 *
 * @package Aromamatrix
 */

get_header();

$woocommerce_ready = class_exists('WooCommerce');
$shop_url = $woocommerce_ready ? wc_get_page_permalink('shop') : '#products';
$designer_fragrances_url = home_url('/product-category/perfumes/');
$premium_products = [];
$featured_products = [];
$new_products = [];

if ($woocommerce_ready) {
    $premium_products = wc_get_products([
        'status'  => 'publish',
        'tag'     => 'premium',
        'limit'   => 8,
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);

    $featured_products = wc_get_products([
        'status'  => 'publish',
        'tag'     => 'featured',
        'limit'   => 8,
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);

    $new_products = wc_get_products([
        'status'  => 'publish',
        'limit'   => 8,
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);

}

$render_product_cards = static function (array $products): void {
    ?>
    <div class="woocommerce">
        <div class="aromamatrix-product-cards">
        <?php if ($products) : ?>
            <?php wc_set_loop_prop('columns', 4); ?>
            <?php wc_set_loop_prop('aromamatrix_category_preview', true); ?>
            <?php woocommerce_product_loop_start(); ?>
            <?php foreach ($products as $product) : ?>
                <?php
                $product_post = get_post($product->get_id());

                if (! $product_post instanceof WP_Post) {
                    continue;
                }

                $GLOBALS['post'] = $product_post;
                $GLOBALS['product'] = $product;
                setup_postdata($product_post);
                wc_get_template_part('content', 'product');
                ?>
            <?php endforeach; ?>
            <?php woocommerce_product_loop_end(); ?>
            <?php wp_reset_postdata(); ?>
            <?php wc_set_loop_prop('aromamatrix_category_preview', false); ?>
        <?php else : ?>
            <div class="catalog-empty">
                <h3><?php esc_html_e('New fragrances are arriving soon.', 'aromamatrix'); ?></h3>
                <p><?php esc_html_e('Check back shortly for the latest wholesale fragrance arrivals.', 'aromamatrix'); ?></p>
            </div>
        <?php endif; ?>
        </div>
    </div>
    <?php
};
?>
<main id="primary" class="site-main site-main--wide">
    <section class="home-hero">
        <div class="home-hero__inner aroma-container">
            <div class="home-hero__copy">
                <span class="section-kicker"><?php esc_html_e('Wholesale Designer Fragrances', 'aromamatrix'); ?></span>
                <h1><?php esc_html_e('Stock the fragrances your customers ask for.', 'aromamatrix'); ?></h1>
                <p class="home-hero__lead">
                    <?php esc_html_e('Shop a wholesale-ready selection of designer fragrances, with US warehouse inventory and dependable support for retailers, resellers and fragrance businesses.', 'aromamatrix'); ?>
                </p>
                <div class="home-hero__actions">
                    <a class="aroma-button aroma-button--light aroma-button--arrow" href="<?php echo esc_url($designer_fragrances_url); ?>">
                        <?php esc_html_e('Shop Designer Fragrances', 'aromamatrix'); ?>
                    </a>
                    <a class="aroma-button" href="<?php echo esc_url(home_url('/contact-us/')); ?>">
                        <?php esc_html_e('Contact Us', 'aromamatrix'); ?>
                    </a>
                </div>
                <div class="home-hero__proof">
                    <span><?php esc_html_e('Wholesale-ready assortment', 'aromamatrix'); ?></span>
                    <span><?php esc_html_e('US warehouse inventory', 'aromamatrix'); ?></span>
                    <span><?php esc_html_e('Fast domestic dispatch', 'aromamatrix'); ?></span>
                </div>
            </div>
        </div>
    </section>

    <section class="trust-strip" aria-label="<?php esc_attr_e('Our service guarantees', 'aromamatrix'); ?>">
        <div class="trust-strip__inner aroma-container">
            <div class="trust-item"><strong><?php esc_html_e('Service Quality', 'aromamatrix'); ?></strong><span><?php esc_html_e('Responsive wholesale support', 'aromamatrix'); ?></span></div>
            <div class="trust-item"><strong><?php esc_html_e('Product Quality', 'aromamatrix'); ?></strong><span><?php esc_html_e('Carefully selected inventory', 'aromamatrix'); ?></span></div>
            <div class="trust-item"><strong><?php esc_html_e('Fast Logistics', 'aromamatrix'); ?></strong><span><?php esc_html_e('Efficient order dispatch', 'aromamatrix'); ?></span></div>
            <div class="trust-item"><strong><?php esc_html_e('Affordable Price', 'aromamatrix'); ?></strong><span><?php esc_html_e('Wholesale value for every order', 'aromamatrix'); ?></span></div>
        </div>
    </section>

    <section id="premium-products" class="home-section home-product-section">
        <div class="aroma-container">
            <div class="section-heading">
                <div>
                    <span class="section-kicker"><?php esc_html_e('Premium Products', 'aromamatrix'); ?></span>
                    <h2><?php esc_html_e('Premium fragrances for your wholesale selection.', 'aromamatrix'); ?></h2>
                </div>
                <p><?php esc_html_e('Discover our premium fragrance selection for retailers, resellers and high-turnover fragrance shelves.', 'aromamatrix'); ?></p>
                <a class="aroma-button aroma-button--outline aroma-button--arrow" href="<?php echo esc_url($shop_url); ?>">
                    <?php esc_html_e('View More', 'aromamatrix'); ?>
                </a>
            </div>
            <?php $render_product_cards($premium_products); ?>
        </div>
    </section>

    <section id="featured-products" class="home-section home-section--white home-product-section">
        <div class="aroma-container">
            <div class="section-heading">
                <div>
                    <span class="section-kicker"><?php esc_html_e('Featured Products', 'aromamatrix'); ?></span>
                    <h2><?php esc_html_e('Wholesale picks worth featuring.', 'aromamatrix'); ?></h2>
                </div>
                <p><?php esc_html_e('A considered selection of fragrances chosen for retailers, resellers and high-turnover fragrance shelves.', 'aromamatrix'); ?></p>
                <a class="aroma-button aroma-button--outline aroma-button--arrow" href="<?php echo esc_url($shop_url); ?>">
                    <?php esc_html_e('View More', 'aromamatrix'); ?>
                </a>
            </div>
            <?php $render_product_cards($featured_products); ?>
        </div>
    </section>

    <section id="new-arrivals" class="home-section home-section--white home-product-section">
        <div class="aroma-container">
            <div class="section-heading">
                <div>
                    <span class="section-kicker"><?php esc_html_e('New Arrivals', 'aromamatrix'); ?></span>
                    <h2><?php esc_html_e('Just added to the wholesale catalogue.', 'aromamatrix'); ?></h2>
                </div>
                <p><?php esc_html_e('Discover the latest fragrances available for your next wholesale order.', 'aromamatrix'); ?></p>
                <a class="aroma-button aroma-button--outline aroma-button--arrow" href="<?php echo esc_url($shop_url); ?>">
                    <?php esc_html_e('Explore New Arrivals', 'aromamatrix'); ?>
                </a>
            </div>
            <?php $render_product_cards($new_products); ?>
        </div>
    </section>

    <section id="us-warehouse" class="service-banner service-banner--why-choose-us">
        <div class="service-banner__media" role="img" aria-label="<?php esc_attr_e('Premium fragrance wholesale selection', 'aromamatrix'); ?>"></div>
        <div class="service-banner__content">
            <span class="section-kicker"><?php esc_html_e('Why Choose Us', 'aromamatrix'); ?></span>
            <h2><?php esc_html_e('Wholesale fragrance made simple and reliable.', 'aromamatrix'); ?></h2>
            <p><?php esc_html_e('From the products you select to the support behind your order, we focus on giving fragrance businesses a dependable wholesale experience.', 'aromamatrix'); ?></p>
            <ul class="service-list">
                <li><?php esc_html_e('Service quality guarantee', 'aromamatrix'); ?></li>
                <li><?php esc_html_e('Product quality assurance', 'aromamatrix'); ?></li>
                <li><?php esc_html_e('Fast and reliable logistics', 'aromamatrix'); ?></li>
                <li><?php esc_html_e('Affordable wholesale pricing', 'aromamatrix'); ?></li>
            </ul>
            <div>
                <a class="aroma-button aroma-button--light aroma-button--arrow" href="<?php echo esc_url($shop_url); ?>">
                    <?php esc_html_e('Explore Products', 'aromamatrix'); ?>
                </a>
            </div>
        </div>
    </section>
</main>
<?php
get_footer();
