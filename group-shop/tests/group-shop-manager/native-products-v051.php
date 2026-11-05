<?php
use GroupShop\Manager\{Brands,ProductVersions,NativeProducts,Stores};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local') {throw new RuntimeException('Migrated local QA only');}
$checks=[];$check=static function($ok,$name)use(&$checks):void{if(!$ok){throw new RuntimeException('FAIL: '.$name);} $checks[]=$name;};
$suffix=bin2hex(random_bytes(3));$base=new WC_Product_Simple();$base->set_name('QA051 public '.$suffix);$base->set_description('Public description '.$suffix);$base->set_short_description('Public short '.$suffix);$base->set_regular_price('35');$base->set_weight('1.5');$base->set_purchase_note('Public purchase note');$base->set_status('draft');$base->update_meta_data('_gsm_brand_id',0);$base->update_meta_data('_gsm_kind','base');$base->update_meta_data('_gsm_requested_brands',[6,7]);$base->save();
$a=wc_get_product(ProductVersions::edition($base->get_id(),6));$b=wc_get_product(ProductVersions::edition($base->get_id(),7));
$check($a&&$b&&$a->get_id()!==$b->get_id(),'One native public product has distinct native A/B sale versions');
$_POST=['gsm_product_nonce'=>wp_create_nonce('gsm_product_versions'),'gsm_versions_complete'=>'1','gsm_product_brand'=>6,'gsm_base_product'=>$base->get_id(),'gsm_product_overrides'=>['name','price']];
$a->set_name('QA051 own A '.$suffix);$a->set_regular_price('89');$a->set_description('Forged inherited description');$a->set_weight('99');$a->set_purchase_note('Forged common note');ProductVersions::before($a);$a->save();$a=wc_get_product($a->get_id());
$check($a->get_name()==='QA051 own A '.$suffix&&$a->get_regular_price()==='89','Explicit A title and price overrides persist');
$check($a->get_description()===$base->get_description()&&$a->get_weight()==='1.5'&&$a->get_purchase_note()===$base->get_purchase_note(),'Forged or stale hidden description, weight and purchase-note edits restore shared source');
$_POST['gsm_product_overrides']=[];ProductVersions::before($a);$a->save();$a=wc_get_product($a->get_id());
$check($a->get_name()===$base->get_name()&&$a->get_regular_price()==='35','Turning off overrides restores current public values');
$check(wc_get_product($b->get_id())->get_regular_price()==='35','A override edits never modify B price');
$check(str_contains(NativeProducts::edit_url($base->get_id()),'gsm_brand=0')&&str_contains(NativeProducts::edit_url($a->get_id()),'gsm_brand=6'),'Native edit links carry appropriate public or A brand scope');
$ids=array_map(static fn($p)=>$p->ID,NativeProducts::family($base->get_id()));$check(in_array($a->get_id(),$ids,true)&&in_array($b->get_id(),$ids,true),'Public family resolves both native brand editions');
$actions=NativeProducts::row_actions(['inline hide-if-no-js'=>'Quick edit'],get_post($a->get_id()));$check(!isset($actions['inline hide-if-no-js'])&&isset($actions['gsm_base'],$actions['gsm_family']),'Inherited rows expose source/family navigation and omit misleading Quick Edit');
$attr=new WC_Product_Attribute();$attr->set_name('Size');$attr->set_options(['Small','Large']);$attr->set_visible(true);$attr->set_variation(true);
$vbase=new WC_Product_Variable();$vbase->set_name('QA051 variable '.$suffix);$vbase->set_attributes([$attr]);$vbase->set_status('draft');$vbase->update_meta_data('_gsm_brand_id',0);$vbase->update_meta_data('_gsm_kind','base');$vbase->save();$children=[];
foreach(['Small','Large'] as $i=>$size){$v=new WC_Product_Variation();$v->set_parent_id($vbase->get_id());$v->set_attributes(['size'=>$size]);$v->set_regular_price((string)(40+$i*10));$v->set_description('Public variation '.$size);$v->set_weight('2');$v->set_status('publish');$v->update_meta_data('_gsm_brand_id',0);$v->save();$children[]=$v->get_id();}
WC_Product_Variable::sync($vbase->get_id());$vbase=wc_get_product($vbase->get_id());$va=ProductVersions::create($vbase,6,$vbase->get_id());$vb=ProductVersions::create($vbase,7,$vbase->get_id());$vc=wc_get_product($va->get_children()[0]);$src=wc_get_product((int)$vc->get_meta('_gsm_base_variation'));
$_POST=['gsm_variation_complete'=>[$vc->get_id()=>'1'],'gsm_variation_overrides'=>[$vc->get_id()=>['price','description']]];
$vc->set_regular_price('123');$vc->set_description('Own variation description');$vc->set_attributes(['size'=>'Forged']);$vc->set_weight('99');ProductVersions::before_variation($vc,0);$vc->save();do_action('woocommerce_save_product_variation',$vc->get_id(),0);$vc=wc_get_product($vc->get_id());
$check($vc->get_regular_price()==='123'&&$vc->get_description()==='Own variation description','Native variation overrides save before parent synchronization');
$check($vc->get_attributes()===$src->get_attributes()&&$vc->get_weight()==='2','Variation specifications and common shipping fields always inherit source');
$_POST['gsm_variation_overrides']=[];ProductVersions::before_variation($vc,0);$vc->set_status('private');$vc->save();do_action('woocommerce_save_product_variation',$vc->get_id(),0);$vc=wc_get_product($vc->get_id());
$check($vc->get_regular_price()===$src->get_regular_price()&&$vc->get_description()===$src->get_description(),'Removing variation overrides restores public variation fields');
$check($vc->get_status()==='private','Brand variation enable/disable remains independently effective');
$check(wc_get_product($vb->get_children()[0])->get_regular_price()!=='123','A variation editing preserves B version');
$_POST=[];
file_put_contents('/gsm-private/native-products-v051.json',wp_json_encode(['base'=>$base->get_id(),'a'=>$a->get_id(),'b'=>$b->get_id(),'vbase'=>$vbase->get_id(),'va'=>$va->get_id(),'vb'=>$vb->get_id(),'suffix'=>$suffix]));chmod('/gsm-private/native-products-v051.json',0600);
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
