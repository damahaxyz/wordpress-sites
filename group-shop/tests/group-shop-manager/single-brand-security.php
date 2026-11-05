<?php
use GroupShop\Manager\{Brands,Stores,Identities,BrandScope};
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||!Brands::active()) { throw new RuntimeException('Migrated local QA only'); }
$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);$x=json_decode(file_get_contents('/gsm-private/single-fixtures.json'),true);$root=get_current_user_id();$checks=[];$created=[];
$assert=static function($ok,$name)use(&$checks):void{if(!$ok){throw new RuntimeException('FAIL: '.$name);} $checks[]=$name;};
// Repair old imported menu ownership from the exact migration mapping, never infer it from ID equality.
$map=get_option('gsm_single_migration_map');foreach($map['terms'] as $brand=>$taxes){foreach($taxes['nav_menu']??[] as $id){update_term_meta($id,'_gsm_brand_id',(int)$brand);}}
try {
 $pages=[];foreach([6,7] as $brand){$pages[$brand]=Stores::within($brand,static fn()=>wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'QA scope temporary page']));$created[]=$pages[$brand];}
 $menu=Stores::within(6,static fn()=>wp_create_nav_menu('QA menu '.bin2hex(random_bytes(3))));
 $assert(!is_wp_error($menu)&&(int)get_term_meta($menu,'_gsm_brand_id',true)===6,'Native new menus receive explicit brand ownership');
 $assert(Stores::within(6,static fn()=>in_array((int)$menu,array_map(static fn($t)=>(int)$t->term_id,wp_get_nav_menus()),true))&&!Stores::within(7,static fn()=>in_array((int)$menu,array_map(static fn($t)=>(int)$t->term_id,wp_get_nav_menus()),true)),'Native menus are isolated by brand');
 wp_set_current_user((int)$f['staff']);
 foreach(['install_plugins','activate_plugins','update_plugins','delete_plugins','edit_plugins','manage_options','switch_themes','edit_theme_options','promote_users','create_users','unfiltered_html'] as $cap){$assert(!Stores::within(7,static fn()=>current_user_can($cap)),'Brand administrator cannot acquire global capability: '.$cap);}
 $assert(Stores::within(7,static fn()=>current_user_can('edit_post',$pages[7]))&&!Stores::within(7,static fn()=>current_user_can('edit_post',$pages[6])),'Brand administrator can edit own native pages and cannot edit A pages');
 $assert(!Stores::within(6,static fn()=>current_user_can('edit_post',$pages[6])),'Shop Manager does not inherit brand administrator page privileges');
 $assert(Stores::within(6,static fn()=>current_user_can('edit_product',(int)$x['a']))&&!Stores::within(6,static fn()=>current_user_can('edit_product',(int)$x['b'])),'Shop Manager retains own native product editing with foreign-ID refusal');
 $draft=Stores::within(6,static fn()=>wp_insert_post(['post_type'=>'product','post_status'=>'auto-draft','post_title'=>'QA scope temporary native draft']));$created[]=$draft;
 $assert((int)get_post_meta($draft,'_gsm_brand_id',true)===6&&Stores::within(6,static fn()=>current_user_can('edit_product',$draft)),'Native employee Add Product auto-draft receives own brand before first save');
 Stores::within(6,static fn()=>update_post_meta((int)$x['a'],'_gsm_brand_id',7));$assert((int)get_post_meta((int)$x['a'],'_gsm_brand_id',true)===6,'Employee cannot reassign product ownership through native metadata');
 $before=get_user_meta((int)$f['staff'],'_gsm_brand_roles',true);update_user_meta((int)$f['staff'],'_gsm_brand_roles',[9=>'administrator']);$assert(get_user_meta((int)$f['staff'],'_gsm_brand_roles',true)===$before,'Employee metadata injection cannot self-grant another brand');
 wp_set_current_user($root);
 $assert((get_user_meta((int)$x['native_staff'],'_gsm_brand_roles',true)[6]??'')==='shop_manager'&&(get_user_meta((int)$x['native_staff'],'_gsm_brand_roles',true)[7]??'')==='administrator','Real native profile POST persisted both explicit employee grants');
 $terms=wp_get_object_terms((int)$x['a'],'product_cat');$term=reset($terms);
 if($term){$a=Stores::within(6,static fn()=>get_terms(['taxonomy'=>'product_cat','include'=>[$term->term_id],'hide_empty'=>false]));$global=Brands::unscoped(static fn()=>get_term($term->term_id,'product_cat'));$assert(count($a)===1&&$a[0]->count<=$global->count,'Shared category display counts do not include other brands or mutate shared cache');}
 foreach([6,7] as $brand){$assert(Stores::within($brand,static function()use($brand){$id=(int)get_option('wp_page_for_privacy_policy');return $id>0&&(int)get_post_meta($id,'_gsm_brand_id',true)===$brand&&str_starts_with(get_permalink($id),Brands::url($brand));}),'Native privacy-policy page resolves own brand: '.$brand);}
 $ct=wp_insert_term('QA private collection '.bin2hex(random_bytes(3)),'product_cat');$ct=(int)$ct['term_id'];update_term_meta($ct,'_gsm_collection',1);update_term_meta($ct,'_gsm_brand_id',7);
 $assert(Stores::within(6,static fn()=>get_terms(['taxonomy'=>'product_cat','include'=>[$ct],'hide_empty'=>false]))===[],'Native category queries hide foreign private collections even with explicit include');
 Stores::within(6,static fn()=>wp_set_object_terms((int)$x['a'],[$ct],'product_cat',true));$assert(!Brands::unscoped(static fn()=>has_term($ct,'product_cat',(int)$x['a'])),'Native term assignment cannot attach a B collection to an A sale product');
 $r=new WP_REST_Request('GET','/wc/v3/coupons/'.(int)$x['coupon_id']);$assert(Stores::within(7,static fn()=>BrandScope::rest(null,null,$r)) instanceof WP_Error,'Foreign native REST coupon ID is denied');
 $comment=Stores::within(6,static fn()=>wp_new_comment(['comment_post_ID'=>(int)$x['a'],'comment_content'=>'Synthetic isolated review '.bin2hex(random_bytes(3)),'comment_author'=>'QA isolation','comment_author_email'=>'qa-isolation@example.invalid','comment_author_url'=>'','comment_type'=>'review','comment_approved'=>1],true));
 if(is_wp_error($comment)){throw new RuntimeException('Native comment failed: '.$comment->get_error_code());}
 $assert((int)get_comment_meta($comment,'_gsm_brand_id',true)===6&&Stores::within(7,static fn()=>get_comments(['comment__in'=>[$comment],'fields'=>'ids']))===[],'New native review receives A ownership and is absent from B query');wp_delete_comment($comment,true);
} finally {wp_set_current_user($root);if(!empty($ct)){wp_delete_term($ct,'product_cat');}if(!empty($comment)&&!is_wp_error($comment)){wp_delete_comment($comment,true);}foreach($created as $id){wp_delete_post($id,true);}if(isset($menu)&&!is_wp_error($menu)){wp_delete_nav_menu($menu);}}
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
