<?php
use GroupShop\Manager\{Stores,Orders,Admin};
use Automattic\WooCommerce\Utilities\OrderUtil;
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || get_home_url(Stores::main_id()) !== 'http://localhost:18087') { throw new RuntimeException('Local isolated QA only'); }
wp_set_current_user(get_user_by('login','gsm-admin')->ID);
$checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$main=Stores::main_id();
$a=(int)get_site_by_path(get_network()->domain,'/brand-a/')->blog_id;
$b=(int)get_site_by_path(get_network()->domain,'/brand-b/')->blog_id;
$fixtures=[];
foreach ([$main,$a,$b] as $store) {
    $fixtures[$store]=Stores::within($store,static function () use ($store,$assert): array {
        $hpos=OrderUtil::custom_orders_table_usage_is_enabled();
        $url=Orders::admin_url($store);
        $assert($url===get_admin_url($store,$hpos?'admin.php?page=wc-orders':'edit.php?post_type=shop_order'),'Native link uses destination storage mode: '.$store);
        $order=wc_create_order(['status'=>'pending','created_via'=>'gsm-native-orders-qa']);
        $assert((int)$order->get_meta('_gsm_store_id')===$store && $order->get_meta('_gsm_store_uuid')===Stores::settings()['uuid'],'Native creation stamps store ID and UUID: '.$store);
        $assert(Orders::belongs_here($order),'Current-store order remains visible: '.$store);
        $snapshot=$order->get_meta('_gsm_store_name');
        Orders::stamp($order);
        $assert($order->get_meta('_gsm_store_name')===$snapshot,'Stamp preserves existing origin snapshot: '.$store);
        $order->update_status('on-hold');
        $note=$order->add_order_note('QA native order internal note',false,true);
        $fresh=wc_get_order($order->get_id());
        $assert($fresh->get_status()==='on-hold' && (bool)$note,'Native CRUD status and internal notes remain available: '.$store);
        return ['id'=>$order->get_id(),'url'=>$url,'hpos'=>$hpos,'path'=>get_site($store)->path];
    });
}
$assert(get_current_blog_id()===$main,'Native links and order operations restore original context');
// Both URL branches are exercised without changing persisted Woo storage configuration.
foreach (['yes'=>'admin.php?page=wc-orders','no'=>'edit.php?post_type=shop_order'] as $mode=>$path) {
    $filter=static fn()=>$mode;
    Stores::within($a,static function () use ($a,$path,$filter,$assert): void {
        add_filter('pre_option_woocommerce_custom_orders_table_enabled',$filter);
        try { $assert(Orders::admin_url($a)===get_admin_url($a,$path),'Native link supports storage option '.$path); }
        finally { remove_filter('pre_option_woocommerce_custom_orders_table_enabled',$filter); }
    });
}
$customer=wp_insert_user(['user_login'=>'gsm-native-order-qa-'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(32),'role'=>'customer']);
if (is_wp_error($customer)) { throw new RuntimeException('Unable to create synthetic customer'); }
try {
    Stores::within($a,static function () use ($a,$b,$customer,$fixtures,$assert): void {
        $order=wc_get_order($fixtures[$a]['id']);
        $order->set_customer_id($customer);
        $order->update_meta_data('_gsm_store_id',$b);
        $order->save();
        $assert(!Orders::belongs_here($order) && !apply_filters('woocommerce_order_is_visible',true,$order),'Mismatched origin is rejected by visibility protection');
        wp_set_current_user($customer);
        foreach (['view_order','pay_for_order','order_again'] as $cap) { $assert(!current_user_can($cap,$order->get_id()),'Customer cannot access mismatched origin: '.$cap); }
        wp_set_current_user(get_user_by('login','gsm-admin')->ID);
        $order->update_meta_data('_gsm_store_id',$a); $order->set_customer_id(0); $order->save();
        $assert(Orders::belongs_here(wc_get_order($order->get_id())),'Restoring correct origin restores ownership');
    });
    wp_set_current_user($customer);
    $failed=false; try { Orders::admin_url($a); } catch (Throwable $e) { $failed=true; }
    $assert($failed,'Customer cannot resolve central native-order entry');
} finally {
    wp_set_current_user(get_user_by('login','gsm-admin')->ID);
    require_once ABSPATH.'wp-admin/includes/user.php';
    // A network user must be removed through the network API.
    require_once ABSPATH.'wp-admin/includes/ms.php';
    wpmu_delete_user($customer);
}
$_GET=['page'=>'group-shop-manager','tab'=>'overview'];
ob_start(); Admin::render(); $html=ob_get_clean();
$assert(!str_contains($html,'统一订单') && !str_contains($html,'tab=orders'),'Manager no longer contains central order tab or links');
foreach ($fixtures as $store=>$fixture) { $assert(str_contains($html,esc_url($fixture['url'])),'Overview links to correct store native orders: '.$store); }
$_GET=['page'=>'group-shop-manager','tab'=>'orders','store'=>$a,'order'=>$fixtures[$a]['id']];
ob_start(); Admin::render(); $retired=ob_get_clean();
$assert(str_contains($retired,'<h2>店铺入口</h2>') && !str_contains($retired,'name="kind" value="order_'),'Old central URL safely falls back to overview');
$assert(Orders::columns([])['gsm_store']==='店铺来源','Native HPOS source column remains registered');
$assert(get_current_blog_id()===$main,'All origin and permission checks restore main context');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'fixtures'=>$fixtures],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
