<?php
use GroupShop\Manager\{Brands,Stores,ProductVersions,BulkProducts};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local') {throw new RuntimeException('Private local QA only');}
$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);$root=get_current_user_id();$checks=[];$roots=[];$suffix=bin2hex(random_bytes(3));
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}$checks[]=$name;};
$denied=static function(callable $fn):bool{try{$fn();return false;}catch(WC_Data_Exception $e){return true;}};
register_shutdown_function(static function()use(&$roots,$suffix){file_put_contents('/gsm-private/products-v053-recovery.json',wp_json_encode(['roots'=>$roots,'suffix'=>$suffix]));chmod('/gsm-private/products-v053-recovery.json',0600);});
$make=static function(string $name)use($suffix,&$roots){$p=new WC_Product_Simple();$p->set_name('QA053 '.$name.' '.$suffix);$p->set_regular_price('40');$p->set_description('Public description');$p->set_status('draft');$p->save();$roots[]=$p->get_id();return $p;};
$base=Stores::within(6,static fn()=>$make('public'));$other=$make('second public');
$check($base->get_meta('_gsm_kind')==='base'&&(int)$base->get_meta('_gsm_brand_id')===0,'New root product is public even within a brand context');
$check(current_user_can('gsm_create_public_products')&&get_post_type_object('product')->cap->create_posts==='gsm_create_public_products','Native create capability is dedicated to public-product administrators');
$p=new WC_Product_Simple();$p->set_name('Unlinked attempt');$p->update_meta_data('_gsm_brand_id',6);$check($denied(static fn()=>$p->save())&&!$p->get_id(),'Native CRUD rejects a new unlinked brand product before inserting it');
$check($denied(static fn()=>ProductVersions::create($base,6)),'Materializer rejects missing public source');
$a=wc_get_product(ProductVersions::ensure_edition($base->get_id(),6)['id']);$b=wc_get_product(ProductVersions::ensure_edition($base->get_id(),7)['id']);$roots[]=$a->get_id();$roots[]=$b->get_id();
$check((int)$a->get_meta('_gsm_base_product')===$base->get_id()&&$a->get_status()==='draft'&&$a->get_meta('_gsm_overrides')===[],'New brand version has a public source, empty overrides and draft status');
$clone=clone $a;$clone->set_id(0);$check($denied(static fn()=>$clone->save()),'Duplicating a sales version cannot bypass public inheritance');
$copy=wc_get_product($a->get_id());$copy->update_meta_data('_gsm_base_product',0);$check($denied(static fn()=>$copy->save())&&(int)get_post_meta($a->get_id(),'_gsm_base_product',true)===$base->get_id(),'Clearing source through native CRUD is rejected without changing stored relation');
$copy=wc_get_product($a->get_id());$copy->update_meta_data('_gsm_base_product',$other->get_id());$check($denied(static fn()=>$copy->save()),'Changing an existing brand version to another source is rejected');
$copy=wc_get_product($a->get_id());$copy->update_meta_data('_gsm_brand_id',7);$check($denied(static fn()=>$copy->save()),'Reassigning an existing brand version to another brand is rejected');
$_POST=['gsm_product_nonce'=>wp_create_nonce('gsm_product_versions'),'gsm_versions_complete'=>'1','gsm_product_brand'=>6,'gsm_base_product'=>0];
$check($denied(static fn()=>ProductVersions::before(wc_get_product($a->get_id()))),'Tampered native form cannot detach a brand version');
$_POST=['gsm_product_nonce'=>wp_create_nonce('gsm_product_versions'),'gsm_versions_complete'=>'1','gsm_product_brand'=>6,'gsm_base_product'=>$base->get_id(),'gsm_product_overrides'=>['name','price']];
$a=wc_get_product($a->get_id());$a->set_name('QA053 A custom '.$suffix);$a->set_regular_price('99');ProductVersions::before($a);$a->save();$_POST=[];
$check(wc_get_product($a->get_id())->get_regular_price()==='99','Native inherited editor still accepts explicit price and title overrides');
$base=wc_get_product($base->get_id());$base->set_regular_price('55');$base->set_description('Updated public description');$base->save();
$a=wc_get_product($a->get_id());$b=wc_get_product($b->get_id());$check($a->get_regular_price()==='99'&&$b->get_regular_price()==='55'&&$a->get_description()==='Updated public description','Public updates inherit while brand overrides remain effective');
$var=new WC_Product_Variable();$var->set_name('QA053 variable '.$suffix);$var->set_status('draft');$attr=new WC_Product_Attribute();$attr->set_name('Size');$attr->set_options(['Small','Large']);$attr->set_variation(true);$attr->set_visible(true);$var->set_attributes([$attr]);$var->save();$roots[]=$var->get_id();
foreach(['Small','Large'] as $size){$v=new WC_Product_Variation();$v->set_parent_id($var->get_id());$v->set_attributes(['size'=>$size]);$v->set_regular_price('60');$v->set_status('publish');$v->save();}WC_Product_Variable::sync($var->get_id());
$va=wc_get_product(ProductVersions::ensure_edition($var->get_id(),6)['id']);$roots[]=$va->get_id();$check(count($va->get_children())===2,'Variable brand edition materializes the public specifications and children');
$child=wc_get_product($va->get_children()[0]);Stores::within(6,static function()use($child){$child->update_meta_data('_gsm_overrides',['price']);$child->set_regular_price('88');$child->save();});$check(wc_get_product($child->get_id())->get_regular_price()==='88','Existing inherited variation remains editable through valid overrides');
$newchild=new WC_Product_Variation();$newchild->set_parent_id($va->get_id());$check($denied(static fn()=>$newchild->save()),'Direct creation of a brand-only variation is rejected');
wp_set_current_user((int)$f['staff']);Stores::within(6,static function()use($make,$check,$denied,$a){$check(!current_user_can('gsm_create_public_products'),'Brand employee cannot create native products');$check($denied(static fn()=>$make('employee attempt')),'Employee CRUD cannot create a standalone product');$p=wc_get_product($a->get_id());$p->set_regular_price('101');$p->save();$check(wc_get_product($a->get_id())->get_regular_price()==='101','Employee can still update authorized brand differences');});wp_set_current_user($root);
$job=BulkProducts::start(BulkProducts::prepare('gsm_add_brand',[$base->get_id(),$var->get_id()])['token'],11);while($job['stage']==='running'){$job=BulkProducts::step($job['token']);}$check(BulkProducts::summary($job)['success']===2,'Batch inheritance still creates simple and variable versions under the unified model');
// Scheduled Woo price updates have no logged-in actor but must preserve the same model.
if (!defined('DOING_CRON')) { define('DOING_CRON',true); }wp_set_current_user(0);
$base=wc_get_product($base->get_id());$base->set_regular_price('58');$base->save();$check(wc_get_product($b->get_id())->get_regular_price()==='58','Existing public products can receive scheduled updates without creating standalone editions');
Stores::within(6,static function()use($a,$check){$p=wc_get_product($a->get_id());$p->set_regular_price('102');$p->save();$check(wc_get_product($a->get_id())->get_regular_price()==='102','Existing brand editions retain Woo scheduled-save support');});wp_set_current_user($root);
file_put_contents('/gsm-private/products-v053.json',wp_json_encode(['base'=>$base->get_id(),'other'=>$other->get_id(),'a'=>$a->get_id(),'b'=>$b->get_id(),'variable'=>$var->get_id(),'va'=>$va->get_id(),'roots'=>$roots,'suffix'=>$suffix]));chmod('/gsm-private/products-v053.json',0600);
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
