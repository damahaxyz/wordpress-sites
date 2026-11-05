<?php
use GroupShop\Manager\{Brands,Stores,ProductVersions,Collections,NativeMenus};
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()){throw new RuntimeException('Private local QA only');}
$path='/gsm-private/native-menus-v057.json';if(file_exists($path)){throw new RuntimeException('Clean previous QA057 first');}global $wpdb;
$f=['suffix'=>bin2hex(random_bytes(3)),'posts'=>[],'terms'=>[],'banks'=>[],'brands'=>Brands::registry(),'before_posts'=>$wpdb->get_results("SELECT ID,post_title,post_content,post_status,post_parent,post_name FROM {$wpdb->posts} WHERE post_type IN ('page','nav_menu_item') ORDER BY ID",ARRAY_A),'before_menus'=>$wpdb->get_results("SELECT t.*,tt.* FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt USING(term_id) WHERE tt.taxonomy='nav_menu' ORDER BY t.term_id",ARRAY_A)];
foreach(Stores::all() as $id=>$_){$f['banks'][$id]=Brands::raw_options((int)$id);}
$persist=static function()use(&$f,$path){file_put_contents($path,wp_json_encode($f));chmod($path,0600);};register_shutdown_function($persist);$persist();$checks=[];$root=get_current_user_id();
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}$checks[]=$name;};
foreach(['main'=>1,'a'=>6,'b'=>7] as $key=>$brand){
    $id=Stores::within($brand,static fn()=>wp_create_nav_menu('QA057 '.$key.' '.$f['suffix']));if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$f['menu_'.$key]=$id;$f['terms'][]=$id;$persist();
    $_REQUEST['gsm_brand']=$brand;$id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'QA057 page '.$key.' '.$f['suffix'],'post_content'=>'QA057 content','post_name'=>'qa057-page-'.$key.'-'.$f['suffix']],true);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$f['page_'.$key]=$id;$f['posts'][]=$id;$persist();
}
$p=new WC_Product_Simple();$p->set_name('QA057 public '.$f['suffix']);$p->set_status('draft');$p->set_regular_price('25');$p->save();$f['base']=$p->get_id();$f['posts'][]=$p->get_id();$persist();
foreach(['a'=>6,'b'=>7] as $key=>$brand){$id=ProductVersions::ensure_edition($p->get_id(),$brand)['id'];$f['product_'.$key]=$id;$f['posts'][]=$id;$persist();Stores::within($brand,static function()use($id){$p=wc_get_product($id);$p->set_status('publish');$p->save();});$id=Collections::save_collection($brand,0,['name'=>'QA057 collection '.$key.' '.$f['suffix'],'mode'=>'manual','include'=>[$id],'enabled'=>1]);$f['collection_'.$key]=$id;$f['terms'][]=$id;$persist();}
$term=wp_insert_term('QA057 shared '.$f['suffix'],'product_cat');$f['shared']=$term['term_id'];$f['terms'][]=$term['term_id'];$persist();
$row=static fn($type,$object,$id)=>['menu-item-type'=>$type,'menu-item-object'=>$object,'menu-item-object-id'=>$id,'menu-item-status'=>'publish'];
Stores::within(6,static function()use(&$f,$row,$check,$persist){
    $check(NativeMenus::here($f['menu_a'])&&!NativeMenus::here($f['menu_b']),'Menu ownership is independent per store');
    $check(wp_get_nav_menu_object($f['menu_b'])===false,'Explicit foreign menu ID cannot resolve in another storefront');
    $ids=wp_get_nav_menus(['fields'=>'ids']);$check(in_array($f['menu_a'],$ids,true)&&!in_array($f['menu_b'],$ids,true),'Native menu queries list only the current store');
    foreach(['page_a','product_a'] as $key){$check(NativeMenus::resource($row('post_type',str_starts_with($key,'page')?'page':'product',$f[$key])),'Own published resource selectable: '.$key);}
    foreach(['page_b','product_b','base'] as $key){$check(!NativeMenus::resource($row('post_type',$key==='page_b'?'page':'product',$f[$key])),'Foreign or public product cannot be selected: '.$key);}
    $check(NativeMenus::resource($row('taxonomy','product_cat',$f['shared']))&&NativeMenus::resource($row('taxonomy','product_cat',$f['collection_a']))&&!NativeMenus::resource($row('taxonomy','product_cat',$f['collection_b'])),'Shared categories and own active collections selectable; foreign collections denied');
    foreach(['/shop/','#details',Brands::url(6).'/privacy-policy/'] as $url){$check(NativeMenus::resource(['menu-item-type'=>'custom','menu-item-url'=>$url]),'Same-store custom link accepted');}
    foreach([Brands::url(7).'/privacy-policy/','https://example.com/','//example.com/','javascript:alert(1)','/?page_id='.$f['page_b']] as $url){$check(!NativeMenus::resource(['menu-item-type'=>'custom','menu-item-url'=>$url]),'Foreign or unsafe custom link denied');}
    $id=wp_update_nav_menu_item($f['menu_a'],0,$row('post_type','page',$f['page_a']));$f['item_a']=$id;$f['posts'][]=$id;$persist();
    set_theme_mod('nav_menu_locations',['primary'=>$f['menu_a'],'footer'=>$f['menu_a']]);$check(get_nav_menu_locations()['primary']===$f['menu_a'],'Own menu binds to storefront locations');
    $before=get_theme_mods();set_theme_mod('nav_menu_locations',['primary'=>$f['menu_b']]);$check(get_theme_mods()===$before,'Foreign menu location is rejected before writing option bank');
    update_option('nav_menu_options',['auto_add'=>[$f['menu_a']]]);$check(get_option('nav_menu_options')['auto_add']===[$f['menu_a']],'Auto-add stores only own menu IDs');
});
$check(Stores::within(7,static fn()=>empty(get_nav_menu_locations()['primary'])||get_nav_menu_locations()['primary']!==$f['menu_a']),'Store B never inherits A location binding');
$_REQUEST['gsm_brand']=6;$id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'QA057 auto a '.$f['suffix']],true);$f['auto_page']=$id;$f['posts'][]=$id;$persist();
$auto=Stores::within(6,static fn()=>wp_get_nav_menu_items($f['menu_a']));foreach($auto as $item){if($item->ID!==$f['item_a']){$f['posts'][]=$item->ID;}}$persist();
$check(in_array($id,array_map('intval',wp_list_pluck($auto,'object_id')),true),'New page auto-add runs after ownership metadata is saved and in page owner scope');
$check(!in_array($f['page_b'],array_map('intval',wp_list_pluck($auto,'object_id')),true),'Auto-add does not collect another store page');
Stores::within(6,static function()use($f,$check){update_term_meta($f['menu_a'],'_gsm_brand_id',7);delete_term_meta($f['menu_a'],'_gsm_brand_id');$check(NativeMenus::owner($f['menu_a'])===6,'Menu ownership cannot be reassigned or removed through raw term meta');});
$staff=json_decode(file_get_contents('/gsm-private/fixtures.json'),true)['staff'];wp_set_current_user((int)$staff);
$check(Stores::within(7,static fn()=>NativeMenus::can_manage()&&current_user_can('gsm_manage_menus')&&!current_user_can('edit_theme_options')),'Brand administrator gains menu capability without general theme authority');
$check(!Stores::within(6,static fn()=>NativeMenus::can_manage()||current_user_can('gsm_manage_menus')),'Shop Manager does not gain menu or theme management');wp_set_current_user($root);
$persist();echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
