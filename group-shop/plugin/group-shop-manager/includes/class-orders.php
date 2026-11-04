<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
final class Orders {
    public static function boot(): void {
        add_action('woocommerce_checkout_create_order', [self::class, 'stamp'], 20);
        add_action('woocommerce_store_api_checkout_update_order_meta', [self::class, 'stamp'], 20);
        add_action('woocommerce_new_order', static function ($id, $order = null): void {
            $order = $order instanceof \WC_Order ? $order : wc_get_order($id);
            if ($order) { self::stamp($order); $order->save_meta_data(); }
        }, 20, 2);
        add_action('woocommerce_before_order_object_save',static function ($order): void {
            if (Identities::is_internal()) { return; }
            $id=$order->get_customer_id(); $identity=$id?Identities::get($id):null;
            if ($identity&&$identity['kind']==='customer'&&(int)$identity['store_id']!==get_current_blog_id()) {
                Schema::audit('order_customer_scope_denied',get_current_blog_id(),$order->get_id(),['reason'=>'foreign_customer']);
                throw new \WC_Data_Exception('gsm_customer_store','订单顾客不属于当前店铺。');
            }
        });
        // WC_Order::save catches exceptions and logs its entire order object. Keep this denial ID-only.
        add_filter('woocommerce_logger_log_message',static function ($message,$level,$context) {
            $error=$context['error']??null;
            return $error instanceof \WC_Data_Exception&&$error->getErrorCode()==='gsm_customer_store'?null:$message;
        },20,3);
        add_filter('manage_woocommerce_page_wc-orders_columns', [self::class, 'columns']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [self::class, 'column'], 20, 2);
        add_filter('woocommerce_order_is_visible', static function ($visible, $order): bool {
            return (bool)$visible && self::belongs_here($order);
        }, 20, 2);
        add_filter('user_has_cap', [self::class, 'protect_customer_order'], 20, 4);
    }
    public static function stamp(\WC_Order $order): void {
        if ($order->get_meta('_gsm_store_id')) { return; }
        $settings = Stores::settings();
        $order->update_meta_data('_gsm_store_id', get_current_blog_id());
        $order->update_meta_data('_gsm_store_uuid', $settings['uuid'] ?? '');
        $order->update_meta_data('_gsm_store_name', get_bloginfo('name'));
        $order->update_meta_data('_gsm_origin_url', home_url('/'));
    }
    public static function belongs_here(mixed $order): bool {
        if (!$order instanceof \WC_Order) { return false; }
        $origin = (int)$order->get_meta('_gsm_store_id');
        return !$origin || $origin === get_current_blog_id();
    }
    public static function protect_customer_order(array $caps, array $requested, array $args, \WP_User $user): array {
        if (!in_array($args[0] ?? '', ['view_order', 'pay_for_order', 'order_again'], true) || empty($args[2]) || user_can($user, 'manage_woocommerce')) { return $caps; }
        $order = wc_get_order((int)$args[2]);
        if ($order && !self::belongs_here($order)) { foreach ($requested as $cap) { $caps[$cap] = false; } }
        return $caps;
    }
    public static function columns(array $columns): array { $columns['gsm_store'] = '店铺来源'; return $columns; }
    public static function column(string $column, mixed $order): void {
        if ($column === 'gsm_store' && $order instanceof \WC_Order) { echo esc_html($order->get_meta('_gsm_store_name') ?: get_bloginfo('name')); }
    }
    /** Resolve the native order screen using the destination store's storage mode. */
    public static function admin_url(int $store): string {
        Stores::authorize();
        return Stores::within($store, static function () use ($store): string {
            $path = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
                ? 'admin.php?page=wc-orders'
                : 'edit.php?post_type=shop_order';
            return get_admin_url($store, $path);
        });
    }
}
