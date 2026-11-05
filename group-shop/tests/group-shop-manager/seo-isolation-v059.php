<?php
/** Fresh synthetic catalog for the public SEO boundary; never run against production. */
use GroupShop\Manager\{Brands,Stores,ProductVersions,Collections,SeoIsolation,BrandScope};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||DB_NAME!=='gsm_qa'||!Brands::active()||!Brands::root()||get_option('home')!=='http://www.main.local') { throw new RuntimeException('Private local SEO QA only'); }
foreach (Brands::registry() as $brand) { if (!str_ends_with($brand['domain'],'.local')||$brand['url']!=='http://'.$brand['domain']) { throw new RuntimeException('Local registry required'); } }
$path='/gsm-private/seo-isolation-v059.json';if (file_exists($path)) { throw new RuntimeException('Inspect and clean existing SEO fixture first'); }
global $wpdb;$f=['suffix'=>bin2hex(random_bytes(4)),'posts'=>[],'terms'=>[],'comments'=>[],'banks'=>[],'brands'=>Brands::registry(),'before'=>[]];
foreach (['posts','postmeta','terms','termmeta','term_taxonomy','term_relationships','comments','commentmeta'] as $table) { $f['before'][$table]=$wpdb->get_results("SELECT * FROM {$wpdb->$table} ORDER BY 1,2",ARRAY_A); }
foreach (Stores::all() as $brand=>$_) { $f['banks'][$brand]=Brands::raw_options((int)$brand); }
$save=static function()use(&$f,$path){file_put_contents($path,wp_json_encode($f));chmod($path,0600);};register_shutdown_function($save);$save();
$checks=[];$check=static function($ok,$name)use(&$checks){if (!$ok) { throw new RuntimeException('FAIL: '.$name); }$checks[]=$name;};
$prefix='QA059 '.$f['suffix'].' ';$slug='qa059-'.$f['suffix'];$f['marker_a']='SEO_A_'.$f['suffix'];$f['marker_b']='SEO_B_'.$f['suffix'];$f['marker_base']='SEO_BASE_'.$f['suffix'];
$term=static function($key,$tax,$parent=0)use(&$f,$save,$prefix){$r=wp_insert_term($prefix.$key,$tax,['parent'=>$parent]);if(is_wp_error($r)){throw new RuntimeException($r->get_error_message());}$f[$key]=(int)$r['term_id'];$f['terms'][]=['id'=>$f[$key],'tax'=>$tax];$save();return $f[$key];};
$shared=$term('shared','product_cat');$child=$term('shared_child','product_cat',$shared);$tag=$term('shared_tag','product_tag');
$base=static function($key,$variable=false)use(&$f,$save,$prefix,$slug,$child,$tag){
 $p=$variable?new WC_Product_Variable():new WC_Product_Simple();$p->set_name($prefix.$key);$p->set_slug($slug.'-'.$key);$p->set_description($f['marker_base']);$p->set_status('publish');$p->set_category_ids([$child]);$p->set_tag_ids([$tag]);
 if($variable){$a=new WC_Product_Attribute();$a->set_name('Size');$a->set_options(['Small']);$a->set_variation(true);$a->set_visible(true);$p->set_attributes([$a]);}else{$p->set_regular_price('50');}
 $p->save();$f[$key]=$p->get_id();$f['posts'][]=$p->get_id();$save();
 if($variable){$v=new WC_Product_Variation();$v->set_parent_id($p->get_id());$v->set_attributes(['size'=>'Small']);$v->set_regular_price('22');$v->save();$f['posts'][]=$v->get_id();$f['base_variation']=$v->get_id();$save();WC_Product_Variable::sync($p->get_id());}return $p->get_id();
};
$base('base');$base('private_base');$base('variable_base',true);
foreach(['a'=>[6,'base'],'b'=>[7,'base'],'private_b'=>[7,'private_base'],'va'=>[6,'variable_base'],'vb'=>[7,'variable_base']] as $key=>[$brand,$source]){
 $id=ProductVersions::ensure_edition($f[$source],$brand)['id'];$f[$key]=$id;$f['posts'][]=$id;$save();
 Stores::within($brand,static function()use($id,$brand,&$f,$prefix){$p=wc_get_product($id);$p->update_meta_data('_gsm_overrides',['name','description','short_description','price']);$p->set_name($prefix.($brand===6?$f['marker_a']:$f['marker_b']));$p->set_description($brand===6?$f['marker_a']:$f['marker_b']);$p->set_short_description($p->get_description());if($p->is_type('simple')){$p->set_regular_price($brand===6?'11':'99');}$p->set_status('publish');$p->save();});
 $children=Brands::unscoped(static fn()=>wc_get_product($id)->is_type('variable')?wc_get_product($id)->get_children():[]);if($children){$f[$key.'_variation']=$children[0];$f['posts']=array_merge($f['posts'],$children);$save();}
}
foreach(['a'=>6,'b'=>7] as $key=>$brand){
 $data=['name'=>$prefix.'collection_'.$key,'mode'=>'manual','include'=>[$f[$key]],'enabled'=>1,'navigation'=>1,'shop_filter'=>1];
 $id=Collections::save_collection($brand,0,$data);$f['collection_'.$key]=$id;$f['terms'][]=['id'=>$id,'tax'=>'product_cat'];$save();
}
foreach(['page_a'=>[6,'page'],'page_b'=>[7,'page'],'private_page_b'=>[7,'private-page'],'draft_b'=>[7,'draft-page'],'post_a'=>[6,'article'],'post_b'=>[7,'article']] as $key=>[$brand,$part]){
 $_REQUEST['gsm_brand']=$brand;
 $id=Stores::within($brand,static fn()=>wp_insert_post(['post_type'=>str_starts_with($key,'post_')?'post':'page','post_title'=>$prefix.$key,'post_content'=>$brand===6?$f['marker_a']:$f['marker_b'],'post_name'=>$slug.'-'.$part,'post_status'=>$key==='draft_b'?'draft':'publish'],true));
 if(is_wp_error($id)||!$id){throw new RuntimeException('Cannot create QA page');}$f[$key]=(int)$id;$f['posts'][]=(int)$id;update_post_meta($id,'_gsm_public_slug',$slug.'-'.$part);$save();
}
unset($_REQUEST['gsm_brand']);
foreach(['a'=>6,'b'=>7] as $key=>$brand){Stores::within($brand,static function()use($key,$brand,&$f,$save,$prefix){
 $id=wp_create_nav_menu($prefix.'menu_'.$key);if(is_wp_error($id)){throw new RuntimeException('Cannot create QA menu');}$f['menu_'.$key]=$id;$f['terms'][]=['id'=>$id,'tax'=>'nav_menu'];$save();
 foreach([['post_type','page',$f['page_'.$key]],['post_type','product',$f[$key]],['taxonomy','product_cat',$f['collection_'.$key]]] as [$type,$object,$object_id]){$item=wp_update_nav_menu_item($id,0,['menu-item-type'=>$type,'menu-item-object'=>$object,'menu-item-object-id'=>$object_id,'menu-item-status'=>'publish']);if(is_wp_error($item)){throw new RuntimeException('Cannot create QA menu item');}$f['posts'][]=$item;$save();}
 set_theme_mod('nav_menu_locations',['primary'=>$id,'footer'=>$id]);update_option('blog_public',1);
});}
// A malformed historical menu item must be hidden at render time, too.
$bad=Brands::unscoped(static fn()=>wp_update_nav_menu_item($f['menu_a'],0,['menu-item-type'=>'post_type','menu-item-object'=>'page','menu-item-object-id'=>$f['private_page_b'],'menu-item-title'=>$f['marker_b'],'menu-item-status'=>'publish']));$f['posts'][]=$bad;update_post_meta($bad,'_gsm_brand_id',6);$save();
foreach([['a',6],['b',7],['b',7]] as [$key,$brand]){$id=wp_insert_comment(['comment_post_ID'=>$f[$key],'comment_content'=>$brand===6?$f['marker_a']:$f['marker_b'],'comment_author'=>$prefix.'review','comment_type'=>'review','comment_approved'=>1]);$f['comments'][]=$id;update_comment_meta($id,'_gsm_brand_id',$brand);update_comment_meta($id,'rating','5');$save();}
foreach(['a'=>6,'b'=>7] as $key=>$brand){Stores::within($brand,static function()use($key,$brand,$f,$check){
 $check(SeoIsolation::post_allowed($f[$key])&&!SeoIsolation::post_allowed($f[$key==='a'?'b':'a'])&&!SeoIsolation::post_allowed($f['base']),'Post boundary for brand '.$brand);
 $check(SeoIsolation::term_allowed($f['shared'],'product_cat')&&!SeoIsolation::term_allowed($f['collection_'.($key==='a'?'b':'a')],'product_cat'),'Shared category and private collection boundary '.$brand);
 $query=new WP_Query(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'meta_query'=>['relation'=>'OR',['key'=>'_gsm_brand_id','value'=>7],['key'=>'_gsm_brand_id','value'=>0]]]);
 $check(!array_diff($query->posts,[$f[$key],$f[$key==='a'?'va':'vb'],$key==='b'?$f['private_b']:0]),'OR meta query cannot bypass brand '.$brand);
 $terms=get_terms(['taxonomy'=>'product_cat','include'=>[$f['collection_'.($key==='a'?'b':'a')]],'fields'=>'ids','hide_empty'=>false]);$check($terms===[],'Explicit foreign collection ID list is empty '.$brand);
 $stats=SeoIsolation::term_stats($f['shared'],'product_cat');$check($stats['count']===($key==='a'?2:3)&&$stats['review_count']===($key==='a'?1:2),'Brand-specific category statistics '.$brand);
 $check(!str_contains(wp_nav_menu(['menu'=>$f['menu_'.$key],'echo'=>false,'fallback_cb'=>false]),$key==='a'?$f['marker_b']:$f['marker_a']),'Menu output excludes foreign content '.$brand);
});}
$f['urls']=[];foreach(['a','b','private_b','base','va','vb','page_a','page_b','private_page_b','post_a','post_b'] as $key){$f['urls'][$key]=get_permalink($f[$key]);}
$f['native_slugs']=[];foreach(['a','b','private_b','base','va','vb','va_variation','vb_variation','base_variation'] as $key){$f['native_slugs'][$key]=get_post_field('post_name',$f[$key]);}
foreach(['shared','shared_child','shared_tag','collection_a','collection_b'] as $key){$tax=$key==='shared_tag'?'product_tag':'product_cat';$f['slugs'][$key]=get_term($f[$key],$tax)->slug;$f['urls'][$key]=Stores::within($key==='collection_b'?7:6,static fn()=>get_term_link($f[$key],$tax));}
$save();if(WC()->session){remove_action('shutdown',[WC()->session,'save_data'],20);WC()->session->destroy_session();}
$report=['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks];file_put_contents('/gsm-private/seo-v059/runtime.json',wp_json_encode($report,JSON_PRETTY_PRINT));
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
