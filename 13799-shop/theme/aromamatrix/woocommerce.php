<?php
/**
 * WooCommerce wrapper.
 *
 * @package Aromamatrix
 */

$term = is_product_category() ? get_queried_object() : null;
$children = $term instanceof WP_Term ? aromamatrix_get_child_product_categories($term) : [];
$use_catalog_cards = true;
$is_shop_catalogue = is_shop();

get_header();
?>
<main id="primary" class="site-main shop-main<?php echo $term instanceof WP_Term && $children ? ' shop-main--category-index' : ''; ?>">
    <?php if ($term instanceof WP_Term && $children) : ?>
        <?php get_template_part('template-parts/product-category-index', null, ['term' => $term, 'children' => $children]); ?>
    <?php elseif ($is_shop_catalogue) : ?>
        <div class="shop-catalogue">
            <?php get_template_part('template-parts/shop-filters'); ?>
            <div class="shop-catalogue__content">
                <div class="woocommerce<?php echo $use_catalog_cards ? ' aromamatrix-product-cards' : ''; ?>">
                    <?php if ($use_catalog_cards) : ?>
                        <?php wc_set_loop_prop('aromamatrix_category_preview', true); ?>
                    <?php endif; ?>
                    <?php aromamatrix_render_active_shop_filters(); ?>
                    <?php woocommerce_content(); ?>
                    <?php if ($use_catalog_cards) : ?>
                        <?php wc_set_loop_prop('aromamatrix_category_preview', false); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else : ?>
        <div class="woocommerce<?php echo $use_catalog_cards ? ' aromamatrix-product-cards' : ''; ?>">
            <?php if ($use_catalog_cards) : ?>
                <?php wc_set_loop_prop('aromamatrix_category_preview', true); ?>
            <?php endif; ?>
            <?php woocommerce_content(); ?>
            <?php if ($use_catalog_cards) : ?>
                <?php wc_set_loop_prop('aromamatrix_category_preview', false); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<?php
get_footer();
