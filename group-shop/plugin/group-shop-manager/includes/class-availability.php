<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Network policy: stock quantities are not managed; publication is still independent. */
final class Availability {
    public static function boot(): void {
        add_filter('pre_option_woocommerce_manage_stock', static fn() => 'no');
        add_action('woocommerce_before_product_object_save', [self::class, 'normalize'], 100);
        foreach (['woocommerce_product_get_manage_stock', 'woocommerce_product_variation_get_manage_stock'] as $hook) {
            add_filter($hook, '__return_false', 100);
        }
        foreach (['woocommerce_product_get_stock_status', 'woocommerce_product_variation_get_stock_status'] as $hook) {
            add_filter($hook, static fn() => 'instock', 100);
        }
        add_filter('woocommerce_product_is_in_stock', '__return_true', 100);
    }
    public static function normalize(\WC_Product $product): void {
        $product->set_manage_stock(false);
        $product->set_stock_quantity(null);
        $product->set_stock_status('instock');
        $product->set_backorders('no');
        $product->set_low_stock_amount('');
    }
    /** Explicit, resumable maintenance migration; never change prices or publication status. */
    public static function migrate(int $store): int {
        Stores::authorize();
        return Stores::within($store, static function () use ($store): int {
            update_option('woocommerce_manage_stock', 'no');
            $page = 1; $count = 0;
            do {
                $ids = get_posts(['post_type'=>['product','product_variation'], 'post_status'=>['publish','draft','private','pending','future'], 'posts_per_page'=>100, 'paged'=>$page++, 'fields'=>'ids', 'orderby'=>'ID', 'order'=>'ASC']);
                foreach ($ids as $id) {
                    $product = wc_get_product($id);
                    if (!$product) { continue; }
                    self::normalize($product); $product->save(); $count++;
                }
            } while (count($ids) === 100);
            update_option('gsm_availability_version', '0.2.0', false);
            Stores::purge();
            Schema::audit('unlimited_stock_applied', $store, 0, ['after'=>$count]);
            return $count;
        });
    }
}
