<?php
use GroupShop\Manager\{Brands,Stores};
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()){throw new RuntimeException('Private local QA only');}
$path='/gsm-private/native-menus-v057.json';$f=json_decode(file_get_contents($path),true);if(!preg_match('/^[a-f0-9]{6}$/D',$f['suffix'])){throw new RuntimeException('Invalid fixture');}global $wpdb;
$menus=[];foreach($f['terms'] as $id){$term=get_term((int)$id);if(!$term instanceof WP_Term){continue;}if(!str_starts_with($term->name,'QA057 ')||!str_ends_with($term->name,$f['suffix'])){throw new RuntimeException('Unexpected term cleanup target');}if($term->taxonomy==='nav_menu'){$menus[]=(int)$id;}}
$items=[];if($menus){$items=array_map('intval',$wpdb->get_col("SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt USING(term_taxonomy_id) WHERE tt.taxonomy='nav_menu' AND tt.term_id IN (".implode(',',$menus).')'));}
$posts=array_values(array_unique(array_merge(array_map('intval',$f['posts']),$items)));
foreach($posts as $id){$p=get_post($id);if(!$p){continue;}if(in_array($id,$items,true)&&$p->post_type==='nav_menu_item'){continue;}if(!str_contains($p->post_title,'QA057 ')||!str_ends_with($p->post_title,$f['suffix'])){throw new RuntimeException('Unexpected post cleanup target');}}
// Never silently overwrite unrelated business changes made during a QA run.
$cache=['gsm_collections_built_at','trovesia_page_cache_generation','trovesia_page_cache_last_purged_at','trovesia_page_cache_last_reason'];$allowed=['nav_menu_options','theme_mods_'.get_stylesheet(),'gsm_collections'];
foreach($f['banks'] as $id=>$old){$now=Brands::raw_options((int)$id);foreach(array_merge($cache,$allowed) as $key){unset($old[$key],$now[$key]);}if($old!==$now){throw new RuntimeException('Unrelated business settings changed');}}
Brands::unscoped(static function()use($posts,$f){foreach(array_reverse($posts) as $id){if(get_post($id)){wp_delete_post($id,true);}}foreach($f['terms'] as $id){$term=get_term((int)$id);if($term instanceof WP_Term){wp_delete_term((int)$id,$term->taxonomy);}}});
foreach($f['banks'] as $id=>$old){$now=Brands::raw_options((int)$id);foreach($allowed as $key){if(array_key_exists($key,$old)){$now[$key]=$old[$key];}else{unset($now[$key]);}}update_option('gsm_brand_options_'.(int)$id,$now,false);}
$actual=$wpdb->get_results("SELECT ID,post_title,post_content,post_status,post_parent,post_name FROM {$wpdb->posts} WHERE post_type IN ('page','nav_menu_item') ORDER BY ID",ARRAY_A);if($actual!==$f['before_posts']){throw new RuntimeException('Original pages or menu items changed');}
$actual=$wpdb->get_results("SELECT t.*,tt.* FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING(term_id) WHERE tt.taxonomy='nav_menu' ORDER BY t.term_id",ARRAY_A);if($actual!==$f['before_menus']){throw new RuntimeException('Original menus changed');}
foreach($f['banks'] as $id=>$old){$now=Brands::raw_options((int)$id);foreach($cache as $key){unset($old[$key],$now[$key]);}if($old!==$now){throw new RuntimeException('Original business settings changed');}}
if(Brands::registry()!==$f['brands']){throw new RuntimeException('Original brands changed');}
Stores::purge();wp_cache_flush();unlink($path);echo wp_json_encode(['version'=>GSM_VERSION,'temporary_posts_removed'=>count($posts),'temporary_terms_tracked'=>count($f['terms']),'original_pages_menus_business_settings_and_brands_preserved'=>true,'brands'=>count(Brands::registry())],JSON_PRETTY_PRINT)."\n";
