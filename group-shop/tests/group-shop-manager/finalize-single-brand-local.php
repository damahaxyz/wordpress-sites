<?php
use GroupShop\Manager\{Brands,Stores,Identities,Collections};
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||!Brands::active()) { throw new RuntimeException('Migrated local single-site only'); }
$count=Identities::internal(static fn()=>Brands::unscoped(static function (): int {
    $ids=get_posts(['post_type'=>['product','product_variation'],'post_status'=>['publish','draft','private','pending','future','trash'],'numberposts'=>-1,'fields'=>'ids']);
    foreach ($ids as $id) { $p=wc_get_product($id); if (!$p) { continue; } if (!$p->is_type('variation')) { $p->set_category_ids(array_values(array_filter($p->get_category_ids(),static fn($term)=>!get_term_meta($term,'_gsm_collection',true)))); $p->save(); } }
    return count($ids);
}));
foreach (Stores::all() as $brand=>$_) { Collections::refresh_brand((int)$brand); }
flush_rewrite_rules(false); wc_delete_product_transients();
echo wp_json_encode(['multisite'=>is_multisite(),'brands'=>count(Stores::all()),'native_products_and_variations'=>$count,'collections_rebuilt'=>true])."\n";
