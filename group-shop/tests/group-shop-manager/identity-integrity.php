<?php
use GroupShop\Manager\{Stores,Identities,Customers,UserMigration,IdentityPrivacy};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||get_home_url()!=='http://www.main.local') { throw new RuntimeException('Mapped local QA only'); }
$admin=get_user_by('login','gsm-admin')->ID; wp_set_current_user($admin); $checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$suffix=bin2hex(random_bytes(3)); $alias='legacy-'.$suffix; $email=$alias.'@example.invalid'; $password=wp_generate_password(25,false); $orders=[]; $cloned=0; $source=0; $download=0; $token=0; $guest=[]; $staff=0;
$shape=static fn($o)=>[$o->get_status(),$o->get_total(),$o->get_currency(),$o->get_address('billing'),$o->get_address('shipping')];
try {
    $source=Stores::within(6,static fn()=>Identities::internal(static fn()=>wp_insert_user(['user_login'=>$alias,'user_pass'=>$password,'user_email'=>$email,'role'=>'customer'])));
    Identities::internal(static fn()=>add_user_to_blog(1,$source,'customer'));
    update_user_meta($source,'_gsm_first_store',6); update_user_meta($source,'billing_city','Shared legacy');
    update_user_meta($source,'_woocommerce_persistent_cart_1',['cart'=>['qa-item'=>['quantity'=>2]]]);
    $oldsession=WP_Session_Tokens::get_instance($source)->create(time()+600);
    foreach ([6,1] as $store) {
        $orders[$store]=Stores::within($store,static function () use ($source,$email) { $o=wc_create_order(['customer_id'=>$source,'status'=>'pending']); $o->set_billing_email($email); $o->set_billing_city('Snapshot city'); $o->set_total('13.50'); $o->save(); return $o->get_id(); });
    }
    $before=Stores::within(1,static fn()=>$shape(wc_get_order($orders[1])));
    [$download,$token]=Stores::within(1,static function () use ($source,$orders,$email): array {
        $d=new WC_Customer_Download(); $d->set_download_id('qa-identity-migration'); $d->set_product_id(0); $d->set_user_id($source); $d->set_user_email($email); $d->set_order_id($orders[1]); $d->save();
        $t=new WC_Payment_Token_CC(); $t->set_token('qa-not-a-real-gateway-token'); $t->set_gateway_id('gsm-qa-disabled'); $t->set_card_type('visa'); $t->set_last4('1111'); $t->set_expiry_month('12'); $t->set_expiry_year('2030'); $t->set_user_id($source); $t->save();
        return [$d->get_id(),$t->get_id()];
    });
    $result=UserMigration::run(); $cloned=(int)Identities::find(1,$alias)['user_id'];
    $assert($source!==$cloned&&Identities::get($source)['store_id']==6,'Legacy customer splits into one ID per owning store');
    $assert(wp_check_password($password,get_userdata($source)->user_pass,$source)&&wp_check_password($password,get_userdata($cloned)->user_pass,$cloned),'One-time migration preserves existing password hash without plaintext synchronization');
    $assert(!WP_Session_Tokens::get_instance($source)->verify($oldsession),'Legacy shared session is revoked');
    $assert(Stores::within(6,static fn()=>wc_get_order($orders[6])->get_customer_id())===$source&&Stores::within(1,static fn()=>wc_get_order($orders[1])->get_customer_id())===$cloned,'Both legacy and HPOS-compatible order CRUD retain correct local owner');
    $assert(Stores::within(1,static fn()=>$shape(wc_get_order($orders[1])))===$before,'Migration preserves historical order status, total and address snapshot');
    $assert(get_user_meta($cloned,'billing_city',true)==='Shared legacy','Existing default address is copied as each independent starting value');
    $assert(get_user_meta($source,'_woocommerce_persistent_cart_1',true)===''&&get_user_meta($cloned,'_woocommerce_persistent_cart_1',true)!=='','Persistent cart moves only to the matching store identity');
    $assert(Stores::within(1,static fn()=>(new WC_Customer_Download($download))->get_user_id())===$cloned,'Local download permission follows migrated customer ID');
    $assert(Stores::within(1,static fn()=>(int)WC_Payment_Tokens::get($token)->get_user_id())===$cloned,'Existing local token ownership follows migrated customer ID without sharing tokens');
    $assert(!is_user_member_of_blog($source,1)&&is_user_member_of_blog($cloned,1),'Migrated original no longer belongs to the second shop');
    update_user_meta($source,'billing_city','Private A'); update_user_meta($cloned,'billing_city','Private B');
    $a=Stores::within(6,static fn()=>wp_json_encode(IdentityPrivacy::customer($email)));
    $b=Stores::within(1,static fn()=>wp_json_encode(IdentityPrivacy::customer($email)));
    $assert(str_contains($a,'Private A')&&!str_contains($a,'Private B')&&str_contains($b,'Private B')&&!str_contains($b,'Private A'),'Real-email privacy exports resolve only this shop customer');
    $assert(count(Stores::within(6,static fn()=>IdentityPrivacy::tokens($email)['data']))===0&&count(Stores::within(1,static fn()=>IdentityPrivacy::tokens($email)['data']))===1,'Scoped token exports cannot pick another shop identity by duplicate email');
    $assert(count(Stores::within(6,static fn()=>IdentityPrivacy::downloads($email)['data']))===0&&count(Stores::within(1,static fn()=>IdentityPrivacy::downloads($email)['data']))===1,'Scoped download exports use local owner');
    Stores::within(6,static fn()=>IdentityPrivacy::erase_customer($email));
    $assert(get_user_meta($source,'billing_city',true)===''&&get_user_meta($cloned,'billing_city',true)==='Private B','Native privacy address erasure affects only this shop identity');
    foreach ([6,1] as $store) { $guest[$store]=Stores::within($store,static function () use ($email) { $o=wc_create_order(); $o->set_billing_email($email); $o->save(); return $o->get_id(); }); }
    $staff=Identities::create_staff(['user_login'=>'staff-'.$suffix,'user_email'=>$email,'user_pass'=>wp_generate_password()]);
    Stores::within(6,static fn()=>do_action('woocommerce_customer_email_verified',$staff));
    Stores::within(6,static fn()=>do_action('woocommerce_customer_email_verified',$cloned));
    $assert(Stores::within(6,static fn()=>wc_get_order($guest[6])->get_customer_id())===0,'Email confirmation cannot link guest orders to staff or a foreign customer');
    Stores::within(6,static fn()=>do_action('woocommerce_customer_email_verified',$source));
    $assert(Stores::within(6,static fn()=>wc_get_order($guest[6])->get_customer_id())===$source&&Stores::within(1,static fn()=>wc_get_order($guest[1])->get_customer_id())===0,'Native confirmed-email guest order linking affects only the owning store');
    grant_super_admin($source); $assert(!is_super_admin($source),'Customer cannot become network super administrator through native API');
    $invalid=Stores::within(6,static fn()=>wp_insert_user(['user_login'=>'injected-'.$suffix,'user_email'=>'injected-'.$suffix.'@example.invalid','user_pass'=>wp_generate_password(),'role'=>'customer','meta_input'=>['wp_7_capabilities'=>['administrator'=>true]]]));
    $assert(is_wp_error($invalid),'Registration cannot inject another store role through meta_input');
    $blocked=Stores::within(6,static function () use ($cloned): bool { $o=new WC_Order(); $o->set_customer_id($cloned); return $o->save()===0; });
    $assert($blocked,'New order cannot bind a foreign-shop customer ID');
    $assert(apply_filters('woocommerce_logger_log_message','blocked','error',['error'=>new WC_Data_Exception('gsm_customer_store','blocked')])===null,'Scope denial cannot log the full native order object');
    $exports=apply_filters('wp_privacy_personal_data_exporters',[]);
    $assert(isset($exports['woocommerce-customer-data'])&&$exports['woocommerce-customer-data']['callback']===[IdentityPrivacy::class,'customer'],'Native privacy registry uses scoped customer adapter');
} finally {
    foreach ($guest as $store=>$id) { Stores::within((int)$store,static function () use ($id): void { $o=wc_get_order($id); if ($o) { $o->delete(true); } }); }
    foreach ($orders as $store=>$id) { Identities::internal(static fn()=>Stores::within((int)$store,static function () use ($id): void { $o=wc_get_order($id); if ($o) { $o->delete(true); } })); }
    Stores::within(1,static function () use ($download,$token): void { if ($download) { try { (new WC_Customer_Download($download))->delete(true); } catch (Exception $e) {} } if ($token) { WC_Payment_Tokens::delete($token); } });
    Identities::internal(static function () use ($source,$cloned,$staff): void { require_once ABSPATH.'wp-admin/includes/ms.php'; foreach (array_unique(array_filter([$cloned,$source,$staff])) as $id) { wpmu_delete_user($id); } });
}
$assert(!$source||Identities::get($source)===null,'Deleted temporary account leaves no identity-index row');
$assert(get_current_blog_id()===Stores::main_id(),'Integrity tests restore main store context');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
