<?php
use GroupShop\Manager\{Brands,Stores,Identities,BrandScope,IdentityPrivacy,Mail};
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||!Brands::active()) { throw new RuntimeException('Migrated local QA only'); }
$x=json_decode(file_get_contents('/gsm-private/single-fixtures.json'),true);$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);$checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$o=wc_get_order((int)$x['http_order']);
$assert($o&&(int)$o->get_meta('_gsm_store_id')===6&&$o->get_customer_id()===(int)$f['a'],'Real Store API checkout persists the A brand and owning customer');
$assert((float)$o->get_total()===0.0&&$o->get_payment_method()===''&&in_array($o->get_status(),['processing','completed'],true),'Virtual free checkout finalizes without a payment gateway');
$items=array_values($o->get_items());$assert(count($items)===1&&(int)$items[0]->get_product_id()===(int)$x['a']&&(float)$items[0]->get_subtotal()===129.0&&$items[0]->get_quantity()===1,'Checkout snapshots the native A product at its own 129 price and one unit');
$assert(Stores::within(7,static fn()=>!in_array($o->get_id(),wc_get_orders(['limit'=>-1,'return'=>'ids']),true)),'B native order queries cannot include the A checkout');
$assert(wc_get_product((int)$x['a'])->get_regular_price()==='129.00','Actual native product editor POST preserves A price override');
$assert(wc_get_product((int)$x['b'])->get_regular_price()==='79.00','Actual native A edit preserves B independent price');
$ua=Identities::find(6,$x['http_customer_email'],true);$ub=Identities::find(7,$x['http_customer_email'],true);
$assert($ua&&$ub&&$ua['user_id']!==$ub['user_id'],'Actual same-email registration persists separate brand customer IDs');
$test=new WC_Order();$test->set_customer_id((int)$f['staff']);$assert($test->save()===0,'Even native order CRUD rejects employee identity as a shopping customer');
$token=Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token((string)$f['staff']);$r=new WP_REST_Request('POST','/wc/store/v1/checkout');$r->set_header('Cart-Token',$token);
$response=Stores::within(6,static fn()=>rest_do_request($r));$assert($response->get_status()===403,'Staff cart token cannot bypass separate-customer checkout policy');
$old=Brands::raw_options(6)['gsm_store_settings'];$mail=array_merge($old,['from_name'=>'A QA Sender','from_email'=>'a-qa@example.invalid','support_email'=>'a-help@example.invalid','mail_enabled'=>false]);Brands::set_option(6,'gsm_store_settings',$mail);
try {
    $emails=WC()->mailer()->get_emails();$email=$emails['WC_Email_Customer_Completed_Order'];$email->object=$o;
    $html=$email->get_content_html();$plain=$email->get_content_plain();
    $assert(str_contains($html,'www.branda.local')&&str_contains($html,'a-help@example.invalid'),'Direct native order email rendering uses the owning brand links and support identity');
    $assert(str_contains($plain,'Brand A renamed')||str_contains($plain,'www.branda.local'),'Plain-text native order email uses owning brand context');
    $assert($email->get_from_address()==='a-qa@example.invalid'&&$email->get_from_name()==='A QA Sender','Native email sender resolves order brand even when invoked from main context');
    $message=Mail::headers(['headers'=>$email->get_headers(),'message'=>'Synthetic test']);
    $assert(in_array('X-Group-Shop-Store: 6',$message['headers'],true)&&in_array('Reply-To: a-help@example.invalid',$message['headers'],true),'Native resend mail headers retain owning brand after template scope restores');
    $assert(Brands::id()===1&&get_current_blog_id()===1,'HTML/plain mail rendering restores both brand and native context');
} finally { Brands::set_option(6,'gsm_store_settings',$old); }
$brand=Stores::create('QA transient brand','qa-transient','qa-transient-'.bin2hex(random_bytes(3)).'.local');
$opts=Brands::raw_options($brand);$assert(isset(Stores::all()[$brand])&&!is_multisite(),'Creating a new brand never creates a native WordPress site');
$assert(($opts['blog_public']??'')==='0'&&empty($opts['gsm_store_settings']['mail_enabled'])&&(int)($opts['gsm_logo_id']??-1)===0,'New brand defaults to noindex, no mail, and no borrowed logo');
$assert(!Identities::accessible((int)$f['staff'],$brand),'New brands do not automatically grant existing employees');
$page=(int)($opts['woocommerce_myaccount_page_id']??0);$assert($page>0&&(int)get_post_meta($page,'_gsm_brand_id',true)===$brand&&str_starts_with(get_permalink($page),Brands::url($brand)),'New brand has a native own-account page and matching public URL');
$pages=Brands::unscoped(static fn()=>get_posts(['post_type'=>'page','post_status'=>'any','numberposts'=>-1,'fields'=>'ids','meta_key'=>'_gsm_brand_id','meta_value'=>$brand]));foreach($pages as $id){wp_delete_post($id,true);} $registry=Brands::registry();unset($registry[$brand]);update_option('gsm_brands',$registry,false);delete_option('gsm_brand_options_'.$brand);
$assert(count(Stores::all())===7&&Brands::id()===1,'Temporary brand and native pages cleanly removed after acceptance');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
