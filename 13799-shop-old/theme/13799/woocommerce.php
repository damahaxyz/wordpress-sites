<?php
/**
 * WooCommerce wrapper.
 *
 * @package Shop13799
 */

get_header();
?>
<main id="primary" class="site-main shop-main shop-container">
    <?php woocommerce_content(); ?>
</main>
<?php
get_footer();
