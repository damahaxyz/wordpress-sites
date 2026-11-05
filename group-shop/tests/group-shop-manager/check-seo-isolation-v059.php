<?php
use GroupShop\Manager\{Brands,Stores,SeoIsolation,BrandScope};
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||DB_NAME!=='gsm_qa'||!Brands::active()||!Brands::root()||get_option('home')!=='http://www.main.local'){throw new RuntimeException('Private local QA only');}
$path='/gsm-private/seo-isolation-v059.json';$f=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$checks=[];
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}$checks[]=$name;};
$f['native_slugs']=[];foreach(['a','b','private_b','base','va','vb','va_variation','vb_variation','base_variation'] as $key){$f['native_slugs'][$key]=get_post_field('post_name',$f[$key]);}
file_put_contents($path,wp_json_encode($f));chmod($path,0600);
// Exercise legacy rich-text URLs in an actual page response, without changing
// original business content or rewriting the saved link in the database.
$_REQUEST['gsm_brand']=6;wp_update_post(['ID'=>$f['page_a'],'post_content'=>$f['marker_a'].'<p><a href="'.$f['urls']['private_page_b'].'">Legacy reference</a></p><iframe src="'.$f['urls']['private_page_b'].'"></iframe>']);unset($_REQUEST['gsm_brand']);
foreach(['a','b'] as $key){update_post_meta($f['post_'.$key],'_wp_old_slug','qa059-'.$f['suffix'].'-old-'.$key);}
foreach(['a'=>6,'b'=>7] as $key=>$brand){Stores::within($brand,static function()use($key,$brand,$f,$check){
 $foreign=$key==='a'?'b':'a';
 $check(SeoIsolation::post_allowed($f[$key])&&!SeoIsolation::post_allowed($f[$foreign])&&!SeoIsolation::post_allowed($f['base']),'Native object ownership '.$brand);
 $check(SeoIsolation::term_allowed($f['shared'],'product_cat')&&!SeoIsolation::term_allowed($f['collection_'.$foreign],'product_cat'),'Shared terms and private ownership '.$brand);
 foreach(['all','ids','count'] as $fields){$r=get_terms(['taxonomy'=>'product_cat','include'=>[$f['collection_'.$foreign]],'fields'=>$fields,'hide_empty'=>false]);$check($fields==='count'?(int)$r===0:$r===[],'Explicit foreign term query '.$fields.' '.$brand);}
 $q=new WP_Query(['post_type'=>'product','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_query'=>['relation'=>'OR',['key'=>'_gsm_brand_id','value'=>7],['key'=>'_gsm_brand_id','value'=>0]]]);$check(!array_filter($q->posts,static fn($id)=>BrandScope::product_brand((int)$id)!==$brand),'OR conditions cannot bypass brand '.$brand);
 $stats=SeoIsolation::term_stats($f['shared'],'product_cat');$check($stats===['count'=>$key==='a'?2:3,'review_count'=>$key==='a'?1:2],'Scoped category counts '.$brand);
 $menu=wp_nav_menu(['menu'=>$f['menu_'.$foreign],'echo'=>false,'fallback_cb'=>false]);$check(!wp_get_nav_menu_object($f['menu_'.$foreign])&&!str_contains((string)$menu,$f['marker_'.$foreign]),'Foreign menu is rejected; native fallback can only use local menus '.$brand);
 $ours=wp_nav_menu(['menu'=>$f['menu_'.$key],'echo'=>false,'fallback_cb'=>false]);$check(!str_contains($ours,$f['marker_'.$foreign]),'Own menu removes malformed foreign entries '.$brand);
 $check(!wp_get_canonical_url($f[$foreign]),'Foreign canonical is suppressed '.$brand);
 $html='<a href="'.$f['urls']['private_page_b'].'">reference</a><img src="http://www.brandb.local/wp-content/uploads/shared.jpg">';
 if($brand===6){$filtered=SeoIsolation::content_links($html);$check(!str_contains($filtered,'href=')&&str_contains($filtered,'src="http://www.brandb.local/wp-content/uploads/shared.jpg"'),'Legacy cross-store rich-text links are removed while shared images remain');}
 $raw=Brands::unscoped(static fn()=>get_terms(['taxonomy'=>'product_cat','include'=>[$f['collection_'.$foreign]],'hide_empty'=>false]));$check(count($raw)===1,'Trusted internal reads remain possible '.$brand);
 $tree=get_terms(['taxonomy'=>'product_cat','fields'=>'id=>parent','get'=>'all']);$check(isset($tree[$f['collection_a']],$tree[$f['collection_b']]),'Global taxonomy hierarchy cache stays complete '.$brand);
});}
$check(Brands::id()===1,'Scope restored after cross-brand checks');
foreach(['a'=>6,'b'=>7] as $key=>$brand){Stores::within($brand,static function()use($key,$brand,$f,$check){
 $foreign=$key==='a'?'b':'a';
 foreach(['/wc/v3/products/'.$f[$foreign],'/wc/v3/products/categories/'.$f['collection_'.$foreign],'/wc/v3/products/'.$f['v'.$key].'/variations/'.$f['v'.$foreign.'_variation'],'/wp/v2/comments/'.$f['comments'][$key==='a'?1:0],'/wc/v3/products/reviews/'.$f['comments'][$key==='a'?1:0]] as $route){$r=rest_do_request(new WP_REST_Request('GET',$route));$check($r->get_status()===404,'REST callback direct ownership '.$brand.' '.$route);}
 $r=rest_do_request(new WP_REST_Request('GET','/wc/v3/products/'.$f[$key]));$check($r->get_status()===200&&$r->get_data()['id']===$f[$key],'Authorized native Woo REST product remains readable '.$brand);
 $r=rest_do_request(new WP_REST_Request('GET','/wc/v3/products/categories/'.$f['shared']));$check($r->get_status()===200&&$r->get_data()['count']===($brand===6?2:3),'Internal REST subrequest category counts are scoped '.$brand);
});}
// Warm exactly the same query shape across scopes in one PHP process to catch
// FilterData's persistent cache path, which ordinary product-list tests miss.
$provider=wc_get_container()->get(\Automattic\WooCommerce\Internal\ProductFilters\FilterDataProvider::class)->with(new \Automattic\WooCommerce\StoreApi\Utilities\ProductQuery());
$vars=['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1];
foreach([6,7,6] as $brand){Stores::within($brand,static function()use($brand,$f,$provider,$vars,$check){
 $counts=$provider->get_taxonomy_counts($vars,'product_cat');$foreign=$f['collection_'.($brand===6?'b':'a')];$check(!isset($counts[$foreign]),'Aggregate cache remains scoped '.$brand);
 $scoped=new WP_Query($vars);$stock=$provider->get_stock_status_counts($scoped->query_vars,array_keys(wc_get_product_stock_status_options()));$check(array_sum($stock)===($brand===6?2:3),'Native scoped query preserves stock aggregate cache '.$brand);
});}
if(WC()->session){remove_action('shutdown',[WC()->session,'save_data'],20);WC()->session->destroy_session();}
$report=['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks];file_put_contents('/gsm-private/seo-v059/runtime.json',wp_json_encode($report,JSON_PRETTY_PRINT));echo wp_json_encode($report,JSON_PRETTY_PRINT)."\n";
