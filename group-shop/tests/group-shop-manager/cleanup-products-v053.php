<?php
use GroupShop\Manager\{Brands,Stores};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local') {throw new RuntimeException('Private local QA only');}
global $wpdb;
$ids=array_map('intval',$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_title REGEXP '^QA053 .+ [a-f0-9]{6}$'"));
$children=$ids?array_map('intval',$wpdb->get_col('SELECT ID FROM '.$wpdb->posts.' WHERE post_type=\'product_variation\' AND post_parent IN ('.implode(',',$ids).')')):[];
$all=array_merge($ids,$children);$removed=0;$jobs=0;
Brands::unscoped(static function()use($all):void{foreach(wc_get_orders(['limit'=>-1]) as $order){foreach($order->get_items() as $item){if(array_intersect($all,[(int)$item->get_product_id(),(int)$item->get_variation_id()])){throw new RuntimeException('QA fixture is referenced by an order');}}}});
// Only discard tasks whose entire selection is this reserved fixture family.
$names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like('_transient_gsm_bulk_').'%'));
foreach($names as $name){$key=substr($name,11);$job=get_transient($key);if(!is_array($job)||empty($job['ids'])||array_diff(array_map('intval',$job['ids']),array_merge($ids,[999999999]))){continue;}if((string)get_user_meta((int)$job['owner'],'_gsm_bulk_job',true)===$job['token']){delete_user_meta((int)$job['owner'],'_gsm_bulk_job');}delete_transient($key);++$jobs;}
Brands::unscoped(static function()use($children,$ids,&$removed):void{foreach(array_merge($children,$ids) as $id){$p=wc_get_product($id);if($p){$p->delete(true);++$removed;}}});
foreach([1244,1245,1246,1247,1250,1253] as $id){if(!wc_get_product($id)){throw new RuntimeException('Retained demo missing');}}
Stores::purge();wp_cache_flush();
echo wp_json_encode(['version'=>GSM_VERSION,'temporary_product_records_removed'=>$removed,'temporary_tasks_removed'=>$jobs,'remaining_QA053_parents'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_title REGEXP '^QA053 .+ [a-f0-9]{6}$'"),'parent_products_after'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft')"),'product_and_variation_records_after'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status NOT IN ('trash','auto-draft')"),'brands'=>count(Stores::all()),'multisite'=>is_multisite()],JSON_PRETTY_PRINT)."\n";
