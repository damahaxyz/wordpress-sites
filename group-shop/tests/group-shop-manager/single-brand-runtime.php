<?php
use GroupShop\Manager\{Brands,Stores,Identities,Customers,UserAdmin,ProductVersions,BrandScope,BrandSession,IdentityPrivacy};
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||!Brands::active()) { throw new RuntimeException('Migrated local QA only'); }
$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true,512,JSON_THROW_ON_ERROR); $root=get_current_user_id();$checks=[];$created=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$assert(Brands::root()&&current_user_can('manage_options'),'Platform administrator retains native single-site management');
$assert(Stores::within(6,static fn()=>[Brands::id(),get_current_blog_id()])===[6,1]&&Brands::id()===1,'Brand context changes without switching native tables and always restores');
try { Stores::within(6,static fn()=>throw new RuntimeException('expected')); } catch (RuntimeException $e) {} $assert(Brands::id()===1,'Exception restores original brand context');
foreach ([6=>$f['password_a'],7=>$f['password_b']] as $brand=>$pass) {
 $u=Stores::within($brand,static fn()=>wp_authenticate($f['email'],$pass));$assert($u instanceof WP_User&&$u->ID===$f[$brand===6?'a':'b'],'Independent customer email/password survives migration: '.$brand);
}
$assert(is_wp_error(Stores::within(9,static fn()=>wp_authenticate($f['email'],$f['password_a']))),'Customer cannot enter an unregistered brand');
$assert(Stores::within(7,static fn()=>Customers::session_user((int)$f['a']))===false,'Copied customer identity cannot establish a foreign-brand session');
$assert(Stores::within(6,static fn()=>IdentityPrivacy::customer($f['email']))!==Stores::within(7,static fn()=>IdentityPrivacy::customer($f['email'])),'Privacy tools resolve independent same-email brand customers');
wp_set_current_user((int)$f['staff']);
$assert(Stores::within(6,static fn()=>current_user_can('manage_woocommerce'))&&Stores::within(7,static fn()=>current_user_can('manage_woocommerce')),'Employee native capabilities follow explicit A and B grants');
$assert(!Stores::within(9,static fn()=>current_user_can('manage_woocommerce'))&&!Brands::root()&&!current_user_can('manage_options'),'Employee cannot enter C or acquire platform settings');
$assert(!Stores::within(6,static fn()=>current_user_can('edit_user',(int)$f['b'])),'Employee cannot edit foreign-brand customer');
$assert(!Stores::within(6,static fn()=>current_user_can('promote_user',(int)$f['a'])),'Customer remains immutable even through native role capability checks');
wp_set_current_user($root);
$ua=get_userdata((int)$f['a']);$ua->set_role('administrator');$assert((new WP_User($ua->ID))->roles===['customer'],'Direct role overwrite cannot promote an existing customer');
wp_set_current_user(0);$guestA=Stores::within(6,static fn()=>(new BrandSession())->generate_customer_id());$guestB=Stores::within(7,static fn()=>(new BrandSession())->generate_customer_id());wp_set_current_user($root);
$assert(strlen($guestA)===32&&str_starts_with($guestA,'t_b6_')&&str_starts_with($guestB,'t_b7_'),'Guest session IDs preserve native guest marker and bind brand in 32 characters');
$assert(!Stores::within(7,static fn()=>BrandSession::belongs($guestA)),'Foreign guest session ID is rejected before restoring cart data');
$suffix=bin2hex(random_bytes(3));$images=get_posts(['post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','numberposts'=>2]);
$base=new WC_Product_Simple();$base->set_name('QA common '.$suffix);$base->set_description('Common description '.$suffix);$base->set_short_description('Common short '.$suffix);$base->set_regular_price('49.00');$base->set_virtual(true);$base->set_status('draft');$base->set_image_id((int)$images[0]);$base->set_sku('QA-COMMON-'.$suffix);$base->update_meta_data('_gsm_brand_id',0);$base->update_meta_data('_gsm_kind','base');$base->update_meta_data('_gsm_requested_brands',[6,7]);$base->save();$created[]=$base->get_id();
$a=wc_get_product(ProductVersions::edition($base->get_id(),6));$b=wc_get_product(ProductVersions::edition($base->get_id(),7));$created[]=$a->get_id();$created[]=$b->get_id();
$assert($a&&$b&&$a->get_id()!==$b->get_id(),'Saving public product creates distinct native brand sale versions');
$a->update_meta_data('_gsm_overrides',['name','description','short_description','images','price']);$a->set_name('QA Brand A '.$suffix);$a->set_description('Private A description '.$suffix);$a->set_short_description('Private A short '.$suffix);$a->set_image_id((int)$images[1]);$a->set_regular_price('129.00');$a->set_sale_price('');$a->set_status('publish');$a->save();
$b->set_status('publish');$b->save();
$base=wc_get_product($base->get_id());$base->set_name('QA common updated '.$suffix);$base->set_description('Common updated description '.$suffix);$base->set_regular_price('79.00');$base->save();
$a=wc_get_product($a->get_id());$b=wc_get_product($b->get_id());
$assert($a->get_name()==='QA Brand A '.$suffix&&$a->get_description()==='Private A description '.$suffix&&$a->get_regular_price()==='129.00'&&(int)$a->get_image_id()===(int)$images[1],'Brand A title, description, image and price overrides survive public edits');
$assert($b->get_name()===$base->get_name()&&$b->get_description()===$base->get_description()&&$b->get_regular_price()==='79.00'&&(int)$b->get_image_id()===(int)$images[0],'Brand B inherits updated title, description, image and native price');
$assert($a->get_status()==='publish'&&$b->get_status()==='publish','Shared edits preserve each brand native publication state');
$assert(str_starts_with(get_permalink($a->get_id()),Brands::url(6))&&str_starts_with(get_permalink($b->get_id()),Brands::url(7)),'Each version has its own brand-domain public permalink');
foreach ([6=>$a->get_id(),7=>$b->get_id()] as $brand=>$id) {
 $rows=Stores::within($brand,static fn()=>wc_get_products(['limit'=>-1,'status'=>'publish']));$ids=array_map(static fn($p)=>$p->get_id(),$rows);
 $assert(in_array($id,$ids,true)&&!in_array($brand===6?$b->get_id():$a->get_id(),$ids,true)&&!in_array($base->get_id(),$ids,true),'Native product queries isolate brand editions and hide public bases: '.$brand);
}
$r=Stores::within(6,static fn()=>rest_do_request('/wc/store/v1/products/'.$b->get_id()));$assert($r->get_status()===404,'Store API refuses foreign brand product by native ID');
$r=Stores::within(6,static fn()=>rest_do_request(new WP_REST_Request('GET','/wc/store/v1/products')));$assert($r->get_status()===200&&!in_array($b->get_id(),array_column($r->get_data(),'id'),true),'Store API catalog cannot include another brand version');
global $wpdb;$assert((float)$wpdb->get_var($wpdb->prepare("SELECT min_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",$a->get_id()))===129.0&&(float)$wpdb->get_var($wpdb->prepare("SELECT min_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",$b->get_id()))===79.0,'Native price lookup stores independent brand prices');
$vbase=new WC_Product_Variable();$vbase->set_name('QA variable '.$suffix);$vbase->set_status('draft');$vbase->set_virtual(true);$attr=new WC_Product_Attribute();$attr->set_name('Size');$attr->set_options(['Small','Large']);$attr->set_visible(true);$attr->set_variation(true);$vbase->set_attributes([$attr]);$vbase->update_meta_data('_gsm_brand_id',0);$vbase->update_meta_data('_gsm_kind','base');$vbase->save();$created[]=$vbase->get_id();
foreach (['Small'=>'10.00','Large'=>'20.00'] as $size=>$price) { $v=new WC_Product_Variation();$v->set_parent_id($vbase->get_id());$v->set_attributes(['size'=>$size]);$v->set_regular_price($price);$v->set_virtual(true);$v->set_status('publish');$v->save();$created[]=$v->get_id(); }
$vbase=wc_get_product($vbase->get_id());$vbase->update_meta_data('_gsm_requested_brands',[6,7]);$vbase->save();
$va=wc_get_product(ProductVersions::edition($vbase->get_id(),6));$vb=wc_get_product(ProductVersions::edition($vbase->get_id(),7));$created[]=$va->get_id();$created[]=$vb->get_id();foreach(array_merge($va->get_children(),$vb->get_children()) as $id){$created[]=$id;}
$assert(count($va->get_children())===2&&count($vb->get_children())===2,'Each variable brand version has independent native variation IDs');
$child=wc_get_product($va->get_children()[0]);$source=(int)$child->get_meta('_gsm_base_variation');$child->set_regular_price('33.00');$child->update_meta_data('_gsm_overrides',['price']);$child->save();$original=wc_get_product($source);$original->set_regular_price('25.00');$original->save();$vbase=wc_get_product($vbase->get_id());$vbase->save();
$match=Brands::unscoped(static fn()=>get_posts(['post_type'=>'product_variation','post_status'=>'any','fields'=>'ids','numberposts'=>1,'post_parent'=>$vb->get_id(),'meta_key'=>'_gsm_base_variation','meta_value'=>$source]));
$assert(wc_get_product($child->get_id())->get_regular_price()==='33.00'&&wc_get_product($match[0])->get_regular_price()==='25.00','Per-variation price override survives while other brand inherits new native price');
$coupon=new WC_Coupon();$coupon->set_code('qa-brand-'.$suffix);$coupon->set_discount_type('percent');$coupon->set_amount(100);$coupon->update_meta_data('_gsm_brand_id',6);$coupon->save();
// Keep only this explicit fixture family for the actual HTTP checkout; no credential is in this manifest.
file_put_contents('/gsm-private/single-fixtures.json',wp_json_encode(['base'=>$base->get_id(),'a'=>$a->get_id(),'b'=>$b->get_id(),'base_variable'=>$vbase->get_id(),'va'=>$va->get_id(),'vb'=>$vb->get_id(),'ids'=>array_values(array_unique($created)),'coupon'=>$coupon->get_code(),'coupon_id'=>$coupon->get_id(),'suffix'=>$suffix,'a_url'=>get_permalink($a->get_id()),'b_url'=>get_permalink($b->get_id())]));chmod('/gsm-private/single-fixtures.json',0600);
$assert(Brands::id()===1&&get_current_blog_id()===1,'All product, authentication and REST operations restore native and brand context');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
