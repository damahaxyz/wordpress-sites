<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Display-only extension of WooCommerce's current-store product Reviews table. */
final class ReviewAdmin {
    public static function boot(): void {
        add_filter('woocommerce_product_reviews_table_columns', [self::class, 'columns']);
        add_action('woocommerce_product_reviews_table_column_gsm_store', [self::class, 'column']);
    }
    public static function columns(array $columns): array {
        $columns['gsm_store'] = '所属品牌';
        return $columns;
    }
    public static function column(mixed $comment): void {
        if (!$comment instanceof \WP_Comment || get_post_type((int)$comment->comment_post_ID) !== 'product') { return; }
        // Reviews and their replies live in the current blog's comments table.
        // Numeric comment/product IDs cannot identify a store across the network.
        $brand=(int)get_comment_meta($comment->comment_ID,'_gsm_brand_id',true)?:BrandScope::product_brand((int)$comment->comment_post_ID); echo esc_html(Brands::name($brand).' (#'.$brand.')');
    }
}
