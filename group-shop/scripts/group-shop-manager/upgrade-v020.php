<?php
/** Explicit migration on the authorized group-shop network. No mail, orders or payment actions. */
use GroupShop\Manager\{Stores,Schema,Availability,Taxonomies,Admin};
if (!defined('WP_CLI')||!WP_CLI||!is_multisite()||GSM_VERSION!=='0.2.0'||get_home_url(Stores::main_id())!=='https://www.animecats.online'||get_home_url(2)!=='https://test.animecats.online') { throw new RuntimeException('Expected production network and plugin version required.'); }
Stores::authorize();
add_filter('pre_wp_mail','__return_false',PHP_INT_MAX);
Schema::install();
$checks=[]; $assert=static function ($ok,string $name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$snapshot=static function (): array {
    $products=get_posts(['post_type'=>['product','product_variation'],'post_status'=>['publish','draft','private','pending','future'],'posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC']);
    $rows=[];
    foreach ($products as $post) { $rows[]=[$post->ID,$post->post_title,$post->post_status,get_post_meta($post->ID,'_regular_price',true),get_post_meta($post->ID,'_sale_price',true)]; }
    $orders=wc_get_orders(['limit'=>1,'paginate'=>true]);
    return ['products'=>hash('sha256',wp_json_encode($rows)),'orders'=>(int)$orders->total,'users'=>count_users()['total_users'],'uuid'=>Stores::settings()['uuid']??''];
};
$stores=array_keys(Stores::all()); $migrations=[];
foreach ($stores as $store) {
    $before=Stores::within($store,$snapshot);
    $migrations[$store]=Availability::migrate($store);
    if ($store!==Stores::main_id()) { Taxonomies::sync_store($store); }
    $after=Stores::within($store,$snapshot);
    $assert($before===$after,'Store '.$store.': product names/prices/statuses, order/user counts and permanent identity preserved');
    $violations=Stores::within($store,static function (): int {
        global $wpdb;
        $ids=get_posts(['post_type'=>['product','product_variation'],'post_status'=>['publish','draft','private','pending','future'],'posts_per_page'=>-1,'fields'=>'ids']);
        $bad=0;
        foreach ($ids as $id) {
            if (get_post_meta($id,'_manage_stock',true)!=='no'||get_post_meta($id,'_stock_status',true)!=='instock') { $bad++; }
            $lookup=$wpdb->get_var($wpdb->prepare("SELECT stock_status FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",$id));
            if ($lookup!=='instock') { $bad++; }
        }
        return $bad;
    });
    $assert($violations===0,'Store '.$store.': every product and variation has native unlimited stock with synchronized lookup');
}
$assert(get_blog_option(2,'blog_public')=='0'&&!get_blog_option(2,'gsm_store_settings')['mail_enabled'],'Second store remains noindex with mail blocked');
$assert(get_home_url(2)==='https://test.animecats.online','Mapped second-store domain preserved');
foreach (['overview','stores','templates','categories','collections','catalog','products','orders','customers','reviews','audit','help'] as $tab) {
    $_GET=['page'=>'group-shop-manager','tab'=>$tab,'store'=>2]; ob_start(); Admin::render(); $html=ob_get_clean();
    $assert(str_contains($html,'v0.2.0')&&!str_contains($html,'notice-error')&&!str_contains($html,'批量库存'),'Production admin screen: '.$tab);
}
$assert(get_current_blog_id()===Stores::main_id(),'Production blog context restored');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'migrated_products_and_variations'=>$migrations,'mail_sent'=>false,'payments_executed'=>false,'orders_created'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
