<?php
use GroupShop\Manager\{Stores,Identities};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||get_home_url()!=='http://www.main.local') { throw new RuntimeException('Mapped local QA only'); }
$f=json_decode(file_get_contents('/gsm-private/admin-fixtures.json'),true,512,JSON_THROW_ON_ERROR); $checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$fixture=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);
$assert(!wc_get_container()->get(Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService::class)->is_verified((int)$fixture['a']),'Foreign-shop native profile save does not verify or link a customer in main shop');
$id=(int)$f['staff']; $row=Identities::get($id); $customer=Identities::find(6,$f['race']);
$assert($row&&$row['kind']==='staff'&&$row['store_id']==0,'Native employee creation stores immutable staff identity');
$assert((new WP_User($id,'',6))->roles===['shop_manager']&&(new WP_User($id,'',7))->roles===['administrator'],'Native grant save persists the requested roles');
$assert(count(get_blogs_of_user($id))===2&&!Identities::accessible($id,9),'Native grant save gives no implicit access to other shops');
global $wpdb;
$assert($customer&&count(get_users(['blog_id'=>0,'search'=>$f['email'],'search_columns'=>['user_email']]))===1,'Concurrent requests persist exactly one native user and one identity row');
$assert(!is_super_admin((int)$customer['user_id']),'Concurrent registration leaves customer without privileged membership');
foreach (['/wc/v3/customers/','/wp/v2/users/'] as $route) {
    $r=Stores::within(7,static fn()=>apply_filters('rest_pre_dispatch',null,rest_get_server(),new WP_REST_Request('GET',$route.$customer['user_id'])));
    $assert(is_wp_error($r)&&$r->get_error_data()['status']===403,'REST rejects foreign customer by ID: '.$route);
}
Identities::internal(static function () use ($id,$customer): void { require_once ABSPATH.'wp-admin/includes/ms.php'; foreach ([$id,(int)$customer['user_id']] as $uid) { WP_Session_Tokens::get_instance($uid)->destroy_all(); wpmu_delete_user($uid); } });
$assert(Identities::get($id)===null&&Identities::get((int)$customer['user_id'])===null,'Temporary native employee and race customer are removed cleanly');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
