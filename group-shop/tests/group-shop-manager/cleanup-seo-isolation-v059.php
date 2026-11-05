<?php
use GroupShop\Manager\{Brands,Stores};
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||DB_NAME!=='gsm_qa'||!Brands::active()||!Brands::root()||get_option('home')!=='http://www.main.local'){throw new RuntimeException('Private local QA only');}
$path='/gsm-private/seo-isolation-v059.json';$f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!preg_match('/^[a-f0-9]{8}$/D',$f['suffix'])){throw new RuntimeException('Invalid private fixture');}global $wpdb;
$ids=array_values(array_unique(array_map('intval',$f['posts'])));$prefix='QA059 '.$f['suffix'].' ';
foreach($ids as $id){$post=get_post($id);if(!$post){continue;}$menu=$post->post_type==='nav_menu_item';if(!$menu&&!str_starts_with($post->post_title,$prefix)){throw new RuntimeException('Unexpected post cleanup target');}if($menu&&!in_array((int)get_post_meta($id,'_gsm_brand_id',true),[6,7],true)){throw new RuntimeException('Unexpected menu cleanup target');}}
foreach($f['terms'] as $r){$t=get_term($r['id'],$r['tax']);if($t instanceof WP_Term&&!str_starts_with($t->name,$prefix)){throw new RuntimeException('Unexpected term cleanup target');}}
foreach(Brands::unscoped(static fn()=>wc_get_orders(['limit'=>-1])) as $order){foreach($order->get_items() as $item){if(array_intersect($ids,[(int)$item->get_product_id(),(int)$item->get_variation_id()])){throw new RuntimeException('Actual order references fixture; inspect before cleanup');}}}
foreach($f['comments'] as $id){$c=get_comment($id);if($c&&!in_array((int)$c->comment_post_ID,$ids,true)){throw new RuntimeException('Unexpected review cleanup target');}}
// Validate restored business banks before writing them; concurrent user edits
// outside our three test settings must never be overwritten by a cleanup.
$runtime=['trovesia_page_cache_generation','trovesia_page_cache_last_purged_at','trovesia_page_cache_last_reason','gsm_collections_built_at'];
$restore=[];
foreach($f['banks'] as $brand=>$old){
 $now=Brands::raw_options((int)$brand);$wanted=$now;
 if(in_array((int)$brand,[6,7],true)){
  $k=$brand==6?'a':'b';
  if((int)($now['blog_public']??-1)!==1){throw new RuntimeException('Indexing setting changed during QA');}
  if(array_key_exists('blog_public',$old)){$wanted['blog_public']=$old['blog_public'];}else{unset($wanted['blog_public']);}
  foreach($f['terms'] as $term){unset($wanted['gsm_collections'][$term['id']]);}
  if(!array_key_exists('gsm_collections',$old)&&empty($wanted['gsm_collections'])){unset($wanted['gsm_collections']);}
  $theme='theme_mods_'.get_option('stylesheet');$locations=$now[$theme]['nav_menu_locations']??[];
  if($locations!==['primary'=>$f['menu_'.$k],'footer'=>$f['menu_'.$k]]){throw new RuntimeException('Menu positions changed during QA');}
  if(isset($old[$theme]['nav_menu_locations'])){$wanted[$theme]['nav_menu_locations']=$old[$theme]['nav_menu_locations'];}else{unset($wanted[$theme]['nav_menu_locations']);}
  if(!array_key_exists($theme,$old)){
   // set_theme_mod may materialize the shared default theme options. Only our
   // menu binding was edited; the bank itself did not previously exist.
   unset($wanted[$theme]);
  }
 }
 $compare=$wanted;$before=$old;foreach($runtime as $key){unset($compare[$key],$before[$key]);}
 if($compare!==$before){throw new RuntimeException('Business bank differs beyond fixture settings for brand '.$brand);}$restore[$brand]=$wanted;
}
Brands::unscoped(static function()use($f,$ids){foreach($f['comments'] as $id){wp_delete_comment($id,true);}foreach(array_reverse($ids) as $id){$post=get_post($id);if(!$post){continue;}if(in_array($post->post_type,['product','product_variation'],true)){$p=wc_get_product($id);if($p){$p->delete(true);}}else{wp_delete_post($id,true);}}foreach(array_reverse($f['terms']) as $r){if($r['tax']==='nav_menu'){wp_delete_nav_menu($r['id']);}else{wp_delete_term($r['id'],$r['tax']);}}});
foreach($restore as $brand=>$bank){update_option('gsm_brand_options_'.$brand,$bank,false);}
$verified=[];foreach($f['before'] as $table=>$rows){$now=$wpdb->get_results("SELECT * FROM {$wpdb->$table} ORDER BY 1,2",ARRAY_A);if($now!==$rows){throw new RuntimeException('Original table snapshot changed: '.$table);}$verified[]=$table;}
if(Brands::registry()!==$f['brands']){throw new RuntimeException('Registry changed');}
foreach($f['banks'] as $brand=>$old){$now=Brands::raw_options((int)$brand);foreach($runtime as $key){unset($now[$key],$old[$key]);}if($old!==$now){throw new RuntimeException('Bank restore mismatch');}}
if(WC()->session){remove_action('shutdown',[WC()->session,'save_data'],20);WC()->session->destroy_session();}
$result=['version'=>GSM_VERSION,'temporary_posts_removed'=>count($ids),'temporary_terms_removed'=>count($f['terms']),'temporary_reviews_removed'=>count($f['comments']),'original_tables_verified'=>$verified,'brands'=>count(Brands::registry()),'brand_business_banks_restored'=>true,'parent_products'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'")];
file_put_contents('/gsm-private/seo-v059/cleanup.json',wp_json_encode($result,JSON_PRETTY_PRINT));unlink($path);echo wp_json_encode($result,JSON_PRETTY_PRINT)."\n";
