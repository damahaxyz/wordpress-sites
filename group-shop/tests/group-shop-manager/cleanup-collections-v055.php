<?php
use GroupShop\Manager\{Brands,Stores,Collections};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()) {throw new RuntimeException('Private local QA only');}
$path='/gsm-private/collections-v055.json';$f=json_decode(file_get_contents($path),true);if(!preg_match('/^[a-f0-9]{6}$/D',$f['suffix'])){throw new RuntimeException('Invalid fixture');}
global $wpdb;
$terms=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->terms} WHERE name LIKE %s",'QA055 % '.$f['suffix'])));
$products=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_title LIKE %s",'QA055 % '.$f['suffix'])));
Brands::unscoped(static function()use($products){foreach(wc_get_orders(['limit'=>-1]) as $order){foreach($order->get_items() as $item){if(in_array((int)$item->get_product_id(),$products,true)){throw new RuntimeException('Fixture referenced by an order');}}}});
foreach($terms as $id){$t=get_term($id,'product_cat');if(!$t||is_wp_error($t)||!str_ends_with($t->name,$f['suffix'])){throw new RuntimeException('Fixture term mismatch');}}
Collections::defer(static function()use($terms,$products){Brands::unscoped(static function()use($products){foreach($products as $id){wc_get_product($id)?->delete(true);}});foreach(array_reverse($terms) as $id){wp_delete_term($id,'product_cat');}});
foreach(Stores::all() as $brand=>$_){Stores::within((int)$brand,static function()use($terms){$rules=Collections::collections();foreach($terms as $id){unset($rules[$id]);}update_option('gsm_collections',$rules,false);});}
$actualTerms=$wpdb->get_results("SELECT t.term_id,t.name,t.slug,tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING(term_id) WHERE tt.taxonomy IN ('product_cat','product_tag') ORDER BY t.term_id",ARRAY_A);
$actualRelations=$wpdb->get_results("SELECT tr.object_id,tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt USING(term_taxonomy_id) WHERE tt.taxonomy IN ('product_cat','product_tag') ORDER BY tr.object_id,tr.term_taxonomy_id",ARRAY_A);
if($actualTerms!==$f['before']['terms']||$actualRelations!==$f['before']['relations']){throw new RuntimeException('Original terms or membership changed');}
foreach($f['before']['collections'] as $brand=>$rules){if((Brands::raw_options((int)$brand)['gsm_collections']??[])!=$rules){throw new RuntimeException('Original collection rules changed');}}
Stores::purge();wp_cache_flush();unlink($path);
echo wp_json_encode(['version'=>GSM_VERSION,'temporary_products_removed'=>count($products),'temporary_terms_removed'=>count($terms),'original_terms_relations_and_rules_preserved'=>true],JSON_PRETTY_PRINT)."\n";
