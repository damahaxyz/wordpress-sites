<?php
use GroupShop\Manager\{Brands,Stores,ProductVersions,Collections,CollectionDisplay};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()) { throw new RuntimeException('Private local QA only'); }
$path='/gsm-private/collections-v055.json'; if (file_exists($path)) { throw new RuntimeException('Clean previous QA055 fixture before repeating'); }
$fixture=['suffix'=>bin2hex(random_bytes(3)),'products'=>[],'terms'=>[]]; $checks=[]; $root=get_current_user_id();
$persist=static function()use(&$fixture,$path){file_put_contents($path,wp_json_encode($fixture));chmod($path,0600);};
register_shutdown_function($persist);
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}$checks[]=$name;};
$denied=static function(callable $fn){try{$fn();return false;}catch(RuntimeException $e){return true;}};
global $wpdb;
$fixture['before']=['terms'=>$wpdb->get_results("SELECT t.term_id,t.name,t.slug,tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING(term_id) WHERE tt.taxonomy IN ('product_cat','product_tag') ORDER BY t.term_id",ARRAY_A),'relations'=>$wpdb->get_results("SELECT tr.object_id,tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt USING(term_taxonomy_id) WHERE tt.taxonomy IN ('product_cat','product_tag') ORDER BY tr.object_id,tr.term_taxonomy_id",ARRAY_A),'collections'=>[]];
foreach(Stores::all() as $brand=>$_){$fixture['before']['collections'][$brand]=Brands::raw_options($brand)['gsm_collections']??[];}
$make=static function($label)use(&$fixture,$persist){$p=new WC_Product_Simple();$p->set_name('QA055 '.$label.' '.$fixture['suffix']);$p->set_status('draft');$p->set_regular_price('25');$p->save();$fixture['products'][]=$p->get_id();$persist();return $p;};
$public=$make('public');$second=$make('second');
foreach(['a'=>[6,$public],'a2'=>[6,$second],'b'=>[7,$public]] as $key=>[$brand,$base]){$id=ProductVersions::ensure_edition($base->get_id(),$brand)['id'];$fixture[$key]=$id;$fixture['products'][]=$id;$persist();Stores::within($brand,static function()use($id){$p=wc_get_product($id);$p->set_status('publish');$p->save();});}
$term=wp_insert_term('QA055 Shared '.$fixture['suffix'],'product_cat');if(is_wp_error($term)){throw new RuntimeException($term->get_error_message());}$fixture['shared']=(int)$term['term_id'];$fixture['terms'][]=$fixture['shared'];$persist();
$sharedchild=wp_insert_term('QA055 Shared child '.$fixture['suffix'],'product_cat',['parent'=>$fixture['shared']]);$fixture['sharedchild']=(int)$sharedchild['term_id'];$fixture['terms'][]=$fixture['sharedchild'];$persist();
$data=static fn($name,$include=[],$parent=0)=>['name'=>'QA055 '.$name.' '.$fixture['suffix'],'mode'=>'manual','include'=>$include,'enabled'=>1,'navigation'=>1,'shop_filter'=>1,'parent'=>$parent];
$create=static function($brand,$key,$value)use(&$fixture,$persist){$id=Collections::save_collection($brand,0,$value);$fixture[$key]=$id;$fixture['terms'][]=$id;$persist();return $id;};
$parent=$create(6,'parent',$data('Parent',[$fixture['a']]));
$child=$create(6,'child',$data('Child',[$fixture['a2']],$parent));
$grand=$create(6,'grand',$data('Grandchild',[],$child));
$foreign=$create(7,'foreign',$data('Other store',[$fixture['b']]));
$check((int)get_term_meta($child,'_gsm_brand_id',true)===6&&get_term_meta($child,'_gsm_collection',true),'New collections persist their store ownership and collection marker');
$check((int)get_term($child,'product_cat')->parent===$parent&&(int)get_term($grand,'product_cat')->parent===$child,'Collection creation persists two levels of native hierarchy');
$slug=get_term($child,'product_cat')->slug;Collections::save_collection(6,$child,$data('Child',[$fixture['a2']],0));
$check((int)get_term($child,'product_cat')->parent===0&&get_term($child,'product_cat')->slug===$slug,'Changing parent preserves the native term ID and slug');
Collections::save_collection(6,$child,$data('Child',[$fixture['a2']],$parent));
foreach(['self'=>$child,'descendant'=>$grand,'shared'=>$fixture['shared'],'foreign'=>$foreign,'missing'=>999999999] as $kind=>$bad){$check($denied(static fn()=>Collections::save_collection(6,$child,$data('Rejected '.$kind,[],$bad)))&&(int)get_term($child,'product_cat')->parent===$parent&&str_contains(get_term($child,'product_cat')->name,'Child'),'Reject '.$kind.' parent before changing existing collection');}
$check($denied(static fn()=>Collections::save_collection(7,$child,$data('Cross store edit'))),'Reject editing another store collection by numeric ID');
$check($denied(static fn()=>Collections::save_collection(6,0,$data('Public product',[$public->get_id()]))),'Public products cannot be manually included in a store collection');
$check($denied(static fn()=>Collections::save_collection(6,0,$data('Foreign product',[$fixture['b']]))),'Foreign store products cannot be manually included');
Stores::within(6,static function()use($check,$fixture,$parent,$child){
 $ids=wp_get_object_terms($fixture['a2'],'product_cat',['fields'=>'ids']);$check(in_array($child,$ids,true)&&!in_array($parent,$ids,true),'Child membership does not automatically add parent collection membership');
 $p=wc_get_product($fixture['a2']);$p->save();$ids=wp_get_object_terms($fixture['a2'],'product_cat',['fields'=>'ids']);$check(in_array($child,$ids,true),'Inherited product save preserves the owned collection relation');
 $rows=Collections::tree();$depths=array_column($rows,'depth','id');$check($depths[$child]===1,'Admin tree orders and indents child collections');
 $check(CollectionDisplay::data($child)['parent']===$parent,'Theme collection data exposes the native parent');
 $nav=Collections::navigation();$check((bool)preg_match('/Parent[^<]*<\/a><ul><li><a[^>]*>QA055 Child/',$nav),'Collection navigation renders nested parent and child lists');
 $filter=CollectionDisplay::filter_form();$check(str_contains($filter,'— QA055 Child'),'Shop collection selector displays hierarchy');
 $query=new WP_Query(CollectionDisplay::query_args($parent,['posts_per_page'=>48]));$ids=wp_list_pluck($query->posts,'ID');$check(in_array($fixture['a'],$ids,true)&&!in_array($fixture['a2'],$ids,true),'Parent module query uses its own rules without collecting child-only products');
});
Collections::trash(6,$parent);
$check($denied(static fn()=>Collections::save_collection(6,$child,$data('Deleted parent',[],$parent))),'Deleted collections cannot be chosen as parents');
$check(Stores::within(6,static fn()=>CollectionDisplay::data($child)!==null),'Trashing a parent leaves child collections usable');
Collections::trash(6,$parent,true);$check((int)get_term($child,'product_cat')->parent===$parent,'Restoring parent preserves the child relationship');
$staff=json_decode(file_get_contents('/gsm-private/fixtures.json'),true)['staff'];wp_set_current_user((int)$staff);
$check($denied(static fn()=>Collections::save_collection(6,$child,$data('Unauthorized edit'))),'Brand staff cannot bypass platform-only collection administration');wp_set_current_user($root);
$check(Brands::id()===1,'Store context is restored after rejected and successful collection writes');
$fixture['http_parent_data']=$data('Parent',[$fixture['a']]);$persist();
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
