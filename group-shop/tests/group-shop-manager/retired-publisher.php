<?php
use GroupShop\Manager\{Stores,Catalog,Admin,Schema};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local' || get_home_url(Stores::main_id())!=='http://www.main.local') { throw new RuntimeException('Mapped local synthetic QA only'); }
wp_set_current_user(get_user_by('login','gsm-admin')->ID);
$checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$throws=static function ($fn,$name) use ($assert): void { $failed=false; try { $fn(); } catch (Throwable $error) { $failed=true; } $assert($failed,$name); };
$main=Stores::main_id(); $store=6;
foreach (['publish','publish_locked','snapshot','attribute_snapshot','variation_attributes','import_media','mapping'] as $method) {
    $assert(!method_exists(Catalog::class,$method),'Cross-store publication method removed: '.$method);
}
$assert(!defined(Catalog::class.'::FIELDS'),'Retired field-copy allowlist is removed');
global $wpdb;
$before=$wpdb->get_results('SELECT * FROM '.Schema::table('catalog').' ORDER BY id',ARRAY_A);
$created=[];
try {
    [$simple,$variable,$children,$image]=Stores::within($store,static function () use (&$created): array {
        $p=new WC_Product_Simple(); $p->set_name('QA local editor retirement check'); $p->set_regular_price('30'); $p->set_status('draft'); $simple=$p->save(); $created[]=$simple;
        $attr=new WC_Product_Attribute(); $attr->set_name('Pack'); $attr->set_options(['One','Two']); $attr->set_variation(true);
        $p=new WC_Product_Variable(); $p->set_name('QA local variants retirement check'); $p->set_attributes([$attr]); $p->set_status('draft'); $variable=$p->save(); $created[]=$variable;
        $children=[];
        foreach (['One','Two'] as $option) { $v=new WC_Product_Variation(); $v->set_parent_id($variable); $v->set_attributes(['pack'=>$option]); $v->set_regular_price('40'); $v->set_status('publish'); $children[]=$v->save(); }
        WC_Product_Variable::sync($variable);
        $images=get_posts(['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','numberposts'=>1,'fields'=>'ids']);
        if (!$images) { throw new RuntimeException('Existing QA store image required'); }
        return [$simple,$variable,$children,(int)$images[0]];
    });
    Catalog::save_edition($store,$simple,['name'=>'QA edited local item','description'=>'<h2>Local editor</h2><p><strong>Rich text</strong></p>','short_description'=>'<p>Local summary</p>','regular_price'=>'35','sale_price'=>'25','image_ids'=>(string)$image]);
    $p=Stores::within($store,static fn()=>wc_get_product($simple));
    $assert($p->get_name()==='QA edited local item' && $p->get_regular_price()==='35' && $p->get_sale_price()==='25','Local simple-product name and prices still save');
    $assert(str_contains($p->get_description(),'<strong>Rich text</strong>') && $p->get_short_description()==='<p>Local summary</p>','Local rich descriptions still save');
    $assert((int)$p->get_image_id()===$image,'Local existing media selection still saves');
    Catalog::set_status($store,$simple,'publish','draft');
    $assert(Stores::within($store,static fn()=>wc_get_product($simple)->get_status())==='publish','Local quick publish remains available');
    $throws(static fn()=>Catalog::set_status($store,$simple,'draft','draft'),'Stale local status request remains rejected');
    Catalog::set_status($store,$simple,'draft','publish');
    $assert(Stores::within($store,static fn()=>wc_get_product($simple)->get_status())==='draft','Local quick disable remains available');
    $throws(static fn()=>Catalog::save_edition($store,$simple,['regular_price'=>'10','sale_price'=>'20']),'Invalid local prices remain rejected');
    $throws(static fn()=>Catalog::save_edition($store,$simple,['image_ids'=>'99999999']),'Invalid local media remains rejected');
    Catalog::save_edition($store,$variable,['variations'=>[$children[0]=>['regular_price'=>'55','sale_price'=>'45','status'=>'publish','description'=>'One edited'],$children[1]=>['regular_price'=>'75','sale_price'=>'','status'=>'private']]]);
    $assert(Stores::within($store,static fn()=>wc_get_product($children[0])->get_regular_price())==='55' && Stores::within($store,static fn()=>wc_get_product($children[1])->get_status())==='private','Local variation prices and enabled state still save');
    $assert(Stores::within($store,static fn()=>wc_get_product($variable)->get_price())==='45','Variable parent price still synchronizes');
    $throws(static fn()=>Catalog::save_edition($store,$variable,['name'=>'Must not save','variations'=>[$children[0]=>['regular_price'=>'90'],$children[1]=>['regular_price'=>'10','sale_price'=>'20']]]),'Invalid later variation still rejects the batch');
    $assert(Stores::within($store,static fn()=>wc_get_product($variable)->get_name())==='QA local variants retirement check' && Stores::within($store,static fn()=>wc_get_product($children[0])->get_regular_price())==='55','Invalid batch leaves parent and earlier row untouched');
    $assert(Stores::within($store,static fn()=>!wc_get_product($simple)->get_manage_stock() && wc_get_product($children[0])->get_stock_status()==='instock'),'Unlimited stock policy remains intact');
} finally {
    Stores::within($store,static function () use ($created): void { foreach (array_reverse($created) as $id) { $p=wc_get_product($id); if ($p) { $p->delete(true); } } });
}
$assert($wpdb->get_results('SELECT * FROM '.Schema::table('catalog').' ORDER BY id',ARRAY_A)===$before,'Local edits create no publication mapping');
$_GET=['page'=>'muti-storefront-plugin','tab'=>'catalog','store'=>$store]; ob_start(); Admin::render(); $html=ob_get_clean();
$assert(!str_contains($html,'主商品发布') && !str_contains($html,'catalog_publish') && !str_contains($html,'tab=catalog') && str_contains($html,'<h2>店铺入口</h2>'),'Old catalog tab falls back to overview with no publication UI');
$assert(substr_count($html,'class="nav-tab ')===6,'Six manager tabs remain');
$assert(get_current_blog_id()===$main,'Local edits and failures restore original context');
$source=wc_get_products(['limit'=>1,'status'=>'publish','return'=>'ids']);
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'fixtures'=>['main_product'=>(int)$source[0],'target_store'=>$store,'edit_product'=>(int)Stores::within($store,static fn()=>wc_get_products(['limit'=>1,'status'=>'publish','return'=>'ids'])[0])]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
