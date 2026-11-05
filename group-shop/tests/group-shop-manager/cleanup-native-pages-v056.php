<?php
use GroupShop\Manager\{Brands,Stores};
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()){throw new RuntimeException('Private local QA only');}
$path='/gsm-private/native-pages-v056.json';$f=json_decode(file_get_contents($path),true);if(!preg_match('/^[a-f0-9]{6}$/D',$f['suffix'])){throw new RuntimeException('Invalid fixture');}global $wpdb;
$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_title LIKE %s",'QA056 % '.$f['suffix'])));$ids=array_values(array_unique(array_merge($ids,array_map('intval',$f['ids']))));
foreach($ids as $id){if(get_post_type($id)!=='page'||!str_ends_with(get_post_field('post_title',$id),$f['suffix'])){throw new RuntimeException('Unexpected cleanup target');}}
Brands::unscoped(static function()use($ids){foreach(array_reverse($ids) as $id){wp_delete_post($id,true);}});
$actual=$wpdb->get_results("SELECT ID,post_title,post_content,post_excerpt,post_status,post_parent,post_name,post_author FROM {$wpdb->posts} WHERE post_type='page' ORDER BY ID",ARRAY_A);if($actual!==$f['before']){throw new RuntimeException('Original pages changed');}
if(Brands::registry()!=$f['brands']){throw new RuntimeException('Brand registry changed');}$cache=['trovesia_page_cache_generation','trovesia_page_cache_last_purged_at','trovesia_page_cache_last_reason'];foreach($f['banks'] as $id=>$before){$bank=Brands::raw_options((int)$id);foreach($cache as $key){unset($before[$key],$bank[$key]);}if($before!==$bank){throw new RuntimeException('Business settings changed');}}
Stores::purge();wp_cache_flush();unlink($path);echo wp_json_encode(['version'=>GSM_VERSION,'temporary_pages_removed'=>count($ids),'original_pages_privacy_settings_and_brands_preserved'=>true,'brands'=>count(Brands::registry())],JSON_PRETTY_PRINT)."\n";
