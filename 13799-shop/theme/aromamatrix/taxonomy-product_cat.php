<?php
/**
 * Product-category archive.
 *
 * Parent categories act as a catalogue index. Each direct child can be
 * expanded to preview eight products; the child archive retains WooCommerce's
 * normal paginated listing for the complete collection.
 *
 * @package Aromamatrix
 */

$term = get_queried_object();
$children = $term instanceof WP_Term ? aromamatrix_get_child_product_categories($term) : [];

get_header();
?>
<main id="primary" class="site-main shop-main<?php echo $term instanceof WP_Term && $children ? ' shop-main--category-index' : ''; ?>">
    <?php if ($children && $term instanceof WP_Term) : ?>
        <?php get_template_part('template-parts/product-category-index', null, ['term' => $term, 'children' => $children]); ?>
    <?php else : ?>
        <div class="woocommerce aromamatrix-product-cards">
            <?php wc_set_loop_prop('aromamatrix_category_preview', true); ?>
            <?php woocommerce_content(); ?>
            <?php wc_set_loop_prop('aromamatrix_category_preview', false); ?>
        </div>
    <?php endif; ?>
</main>
<?php
get_footer();
