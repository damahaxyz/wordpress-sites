<?php
/** Included by the guarded local runner. Simulates upgrade records without loading retired code. */
use GroupShop\Manager\{CategoryMigration,Collections};
$run($a,static function () use ($mapped_root,$mapped_leaf,$mapped_anime,$mapped_gift,$root,$leaf,$anime,$gift,$pa): void {
    foreach ([$mapped_root=>$root,$mapped_leaf=>$leaf,$mapped_anime=>$anime,$mapped_gift=>$gift] as $local=>$source_id) {
        update_term_meta($local,'_gsm_template_id',$source_id);
        update_term_meta($local,'_gsm_template_store',get_main_site_id());
        update_term_meta($local,'_gsm_overrides',['name'=>true,'description'=>true]);
    }
    update_term_meta($mapped_leaf,'thumbnail_id',wc_get_product($pa)->get_image_id());
    update_term_meta($mapped_leaf,'qa_unrelated_meta','preserve-native-seo');
});
$native_before=$run($a,static function () use ($mapped_leaf,$pa): array {
    $term=get_term($mapped_leaf,'product_cat');
    return ['name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent'=>$term->parent,'image'=>get_term_meta($mapped_leaf,'thumbnail_id',true),'seo'=>get_term_meta($mapped_leaf,'qa_unrelated_meta',true),'uuid'=>get_option('gsm_store_settings')['uuid'],'base_assigned'=>has_term($mapped_leaf,'product_cat',$pa)];
});
$legacy=Collections::save_collection($a,0,['name'=>'QA Legacy mapped '.$suffix,'mode'=>'rule','categories'=>[$mapped_root],'tags'=>[$mapped_anime],'enabled'=>1,'navigation'=>1]);
$broken=Collections::save_collection($a,0,['name'=>'QA Legacy missing '.$suffix,'mode'=>'rule','categories'=>[$mapped_root],'include'=>[$pa],'enabled'=>1]);
$manual_legacy=Collections::save_collection($a,0,['name'=>'QA Legacy manual '.$suffix,'mode'=>'manual','include'=>[$extra],'enabled'=>1]);
$run($a,static function () use ($legacy,$broken,$manual_legacy,$root,$anime): void {
    $all=Collections::collections();
    foreach ([$legacy,$broken,$manual_legacy] as $id) { unset($all[$id]['taxonomy_scope']); }
    $all[$legacy]['categories']=[$root]; $all[$legacy]['tags']=[$anime];
    $all[$broken]['categories']=[99999999];
    update_option('gsm_collections',$all,false);
});
$tables_before=$run($a,static function (): array { global $wpdb; return $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'"); });
$result=CategoryMigration::store($a);
$assert($result['metadata_removed']===12&&$result['collections_converted']===3,'Upgrade removes all three legacy metadata keys after converting old rule IDs');
$spec=$run($a,fn()=>Collections::collections()[$legacy]);
$assert($spec['taxonomy_scope']==='local'&&$spec['categories']===[$mapped_root]&&$spec['tags']===[$mapped_anime],'Legacy rule conditions become actual local taxonomy IDs before maps are deleted');
$assert($run($a,fn()=>has_term($legacy,'product_cat',$pa)),'A converted local rule retains its expected materialized members');
$broken_spec=$run($a,fn()=>Collections::collections()[$broken]);
$assert(!$broken_spec['enabled']&&!empty($broken_spec['needs_review'])&&!$run($a,fn()=>has_term($broken,'product_cat',$pa)),'An unresolved rule is disabled without accidentally broadening selection or applying manual additions');
$assert($run($a,fn()=>has_term($manual_legacy,'product_cat',$extra)&&Collections::collections()[$manual_legacy]['enabled']),'Legacy manual collections keep their local product selections');
$native_after=$run($a,static function () use ($mapped_leaf,$pa): array {
    $term=get_term($mapped_leaf,'product_cat');
    return ['name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent'=>$term->parent,'image'=>get_term_meta($mapped_leaf,'thumbnail_id',true),'seo'=>get_term_meta($mapped_leaf,'qa_unrelated_meta',true),'uuid'=>get_option('gsm_store_settings')['uuid'],'base_assigned'=>has_term($mapped_leaf,'product_cat',$pa)];
});
$assert($native_before===$native_after,'Native category identity, text, hierarchy, image, unrelated metadata and product membership survive cleanup');
$left=$run($a,static function (): int { global $wpdb; return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key IN ('_gsm_template_id','_gsm_template_store','_gsm_overrides')"); });
$assert($left===0,'No retired template or override metadata remains in the migrated store');
$tables_after=$run($a,static function (): array { global $wpdb; return $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'"); });
$assert($tables_before===$tables_after,'Cleanup creates or drops no tables and preserves native WooCommerce tables');
$assert(CategoryMigration::store($a)['metadata_removed']===0&&CategoryMigration::store($a)['collections_converted']===0,'Cleanup safely retries without remapping already-local rule IDs');
$repair=['name'=>'QA Legacy repaired '.$suffix,'mode'=>'rule','categories'=>[$mapped_root],'tags'=>[$mapped_anime],'enabled'=>1];
Collections::save_collection($a,$broken,$repair);
$assert(!$run($a,fn()=>get_option('gsm_collections_review'))&&$run($a,fn()=>has_term($broken,'product_cat',$pa)),'Saving corrected native conditions clears review status and restores membership');
CategoryMigration::run();
$assert(CategoryMigration::run()===[],'Network upgrade skips stores already migrated');
wp_set_current_user($customer);
$throws(fn()=>CategoryMigration::store($a),'A customer cannot remove cross-store metadata');
wp_set_current_user($admin->ID);
$assert(get_current_blog_id()===$main,'Upgrade and failure cases restore the main store context');
