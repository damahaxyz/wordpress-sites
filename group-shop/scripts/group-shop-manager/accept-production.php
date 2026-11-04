<?php
/** Explicit test-data installation acceptance; no mail, payment, order creation or status change. */
use GroupShop\Manager\{Stores,Catalog,Customers,Orders,Admin};
if (!defined('WP_CLI')||!WP_CLI||!is_multisite()||get_home_url(Stores::main_id())!=='https://www.animecats.online') { throw new RuntimeException('Wrong installation.'); }
if (GSM_VERSION!=='0.1.0') { throw new RuntimeException('Historical v0.1.0 installer. Use upgrade-v020.php for the current network; do not recreate demo-store.'); }
Stores::authorize();
add_filter('pre_wp_mail','__return_false',PHP_INT_MAX);
$checks=[];
$assert=static function ($ok,$message) use (&$checks): void { if (!$ok) { throw new RuntimeException($message); } $checks[]=$message; };
$main=Stores::main_id(); $main_counts=['users'=>count_users()['total_users'],'orders'=>wc_get_orders(['limit'=>1,'paginate'=>true])->total];
$site=get_site_by_path('www.animecats.online','/demo-store/');
$demo=$site&&(int)$site->blog_id!==$main?(int)$site->blog_id:Stores::create('Group Shop Demo','demo-store');
$assert($demo!==$main,'Demo storefront created inside the shared network');
$master=wc_get_product(175);
if (!$master) { $items=wc_get_products(['status'=>'publish','limit'=>1]); $master=$items[0]??null; }
$assert((bool)$master,'Existing master catalogue remains available');
$edition=Catalog::publish($master->get_id(),$demo,Catalog::FIELDS);
$assert(Stores::within($demo,fn()=>wc_get_product($edition)->get_status())==='draft','Published demo edition is draft');
$assert(Stores::within($demo,fn()=>get_comments(['type'=>'review','count'=>true]))==0,'Demo store has no copied product reviews');
$assert(Stores::within($demo,fn()=>get_option('blog_public'))=='0'&&!Stores::within($demo,fn()=>Stores::settings()['mail_enabled']),'Demo store is noindex with mail disabled');
$email='gsm-acceptance-'.bin2hex(random_bytes(6)).'@example.invalid'; $password=wp_generate_password(28,true,true);
$user=wp_insert_user(['user_login'=>'gsm-accept-'.bin2hex(random_bytes(4)),'user_email'=>$email,'user_pass'=>$password,'role'=>'customer']);
if (is_wp_error($user)) { throw new RuntimeException($user->get_error_message()); }
try {
    $assert(wp_authenticate($email,$password) instanceof WP_User,'Shared customer can authenticate at the master');
    $auth=Stores::within($demo,fn()=>wp_authenticate($email,$password));
    $assert($auth instanceof WP_User&&(int)$auth->ID===(int)$user,'Same customer credentials authenticate in the demo shop');
    Stores::within($demo,fn()=>Customers::logged_in($auth->user_login,$auth));
    $assert(is_user_member_of_blog($user,$demo),'First demo login creates a customer membership without duplicate user');
    update_user_meta($user,'billing_city','Synthetic shared address');
    $assert(Stores::within($demo,fn()=>(new WC_Customer($user))->get_billing_city())==='Synthetic shared address','Native billing address is shared');
} finally {
    require_once ABSPATH.'wp-admin/includes/ms.php'; wpmu_delete_user($user);
}
$assert(count_users()['total_users']===$main_counts['users'],'Temporary acceptance customer removed');
$assert(wc_get_orders(['limit'=>1,'paginate'=>true])->total===$main_counts['orders'],'Acceptance did not create or change master orders');
foreach (['overview','stores','catalog','products','inventory','orders','customers','reviews','audit','help'] as $tab) {
    $_GET=['page'=>'group-shop-manager','tab'=>$tab,'store'=>$demo]; ob_start(); Admin::render(); $html=ob_get_clean();
    $assert(str_contains($html,'Group Shop Manager')&&!str_contains($html,'notice-error'),'Production admin screen: '.$tab);
}
$assert(get_current_blog_id()===$main,'Production scope restored');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'demo_store'=>$demo,'demo_product'=>$edition,'demo_url'=>get_home_url($demo),'users'=>$main_counts['users'],'orders'=>$main_counts['orders'],'mail_sent'=>false,'payments_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
