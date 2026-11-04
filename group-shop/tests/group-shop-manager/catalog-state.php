<?php
use GroupShop\Manager\{Stores,Schema};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || get_home_url(Stores::main_id())!=='http://www.main.local') { throw new RuntimeException('Mapped local synthetic QA only'); }
wp_set_current_user(get_user_by('login','gsm-admin')->ID);
$stores=[];
foreach (Stores::all() as $store=>$name) {
    $stores[$store]=Stores::within((int)$store,static function (): array {
        $ids=get_posts(['post_type'=>['product','product_variation'],'post_status'=>['publish','draft','private','pending','future','trash'],'numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
        $products=[];
        foreach ($ids as $id) { $p=wc_get_product($id); if ($p) { $products[$id]=$p->get_data(); } }
        $images=get_posts(['post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
        $media=[];
        foreach ($images as $id) { $media[$id]=['post'=>get_post($id),'meta'=>get_post_meta($id)]; }
        return ['products'=>count($products),'product_digest'=>hash('sha256',wp_json_encode($products)),'attachments'=>count($media),'media_digest'=>hash('sha256',wp_json_encode($media))];
    });
}
global $wpdb;
$map=$wpdb->get_results('SELECT * FROM '.Schema::table('catalog').' ORDER BY id',ARRAY_A);
echo wp_json_encode(['stores'=>$stores,'mappings'=>count($map),'mapping_digest'=>hash('sha256',wp_json_encode($map))],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
