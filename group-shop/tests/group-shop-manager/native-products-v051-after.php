<?php
use GroupShop\Manager\{Brands,NativeProducts,Stores,ProductVersions};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()) {throw new RuntimeException('Migrated local QA only');}
$x=json_decode(file_get_contents('/gsm-private/native-products-v051.json'),true);$checks=[];
if (empty($x['independent'])||!wc_get_product($x['independent'])) { $p=Stores::within(6,static function()use($x){$p=new WC_Product_Simple();$p->set_name('QA051 independent '.$x['suffix']);$p->set_regular_price('9');$p->set_status('draft');$p->update_meta_data('_gsm_brand_id',6);$p->save();return $p;});$x['independent']=$p->get_id();file_put_contents('/gsm-private/native-products-v051.json',wp_json_encode($x)); }
$check=static function($ok,$name)use(&$checks):void{if(!$ok){throw new RuntimeException('FAIL: '.$name);} $checks[]=$name;};
$b=wc_get_product($x['b']);$base=wc_get_product($x['base']);
$check($b->get_name()===$base->get_name()&&$b->get_regular_price()===$base->get_regular_price()&&$b->get_meta('_gsm_overrides')===[],'Real native parent POST finishes with correct restored inheritance');
$found=false;foreach(wc_get_product($x['vb'])->get_children() as $id){$v=wc_get_product($id);if($v->get_attributes()===['size'=>'Large']){$found=$v->get_regular_price()==='131'&&in_array('price',(array)$v->get_meta('_gsm_overrides'),true);}}
$check($found,'Real browser native AJAX persists B Large variation price 131 and explicit override');
$check(wc_get_product($x['vbase'])->get_variation_regular_price('max')==50.0,'Brand variation edit preserves public maximum price');
$check(count(get_post_meta($base->get_id(),'_gsm_brand_id'))===1&&(int)get_post_meta($base->get_id(),'_gsm_brand_id',true)===0,'Native public-product creation writes exactly one correct brand marker');
$root=get_current_user_id();$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);wp_set_current_user((int)$f['staff']);$blocked=false;
try {Stores::within(7,static function()use($x,$b):void{$_POST=['gsm_product_nonce'=>wp_create_nonce('gsm_product_versions'),'gsm_versions_complete'=>'1','gsm_product_brand'=>7,'gsm_base_product'=>1244];ProductVersions::before($b);});}catch(WC_Data_Exception $e){$blocked=true;}finally{wp_set_current_user($root);$_POST=[];}
$check($blocked,'Employee cannot forge a replacement public-product association');
$draft=Stores::within(7,static fn()=>wp_insert_post(['post_type'=>'product','post_status'=>'auto-draft','post_title'=>'Auto Draft']));
try {$check((int)get_post_meta($draft,'_gsm_brand_id',true)===7&&!get_post_meta($draft,'_gsm_public_slug',true),'Native automatic draft has one brand and no premature public slug');$p=new WC_Product_Simple($draft);$p->set_name('QA051 native slug '.$x['suffix']);$p->set_regular_price('12');$p->set_status('draft');$p->save();$check(get_post_meta($draft,'_gsm_public_slug',true)===sanitize_title($p->get_name()),'First real native save derives usable public slug from product title');}finally{wp_delete_post($draft,true);}
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
