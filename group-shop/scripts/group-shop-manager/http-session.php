<?php
use GroupShop\Manager\Stores;
if (!defined('WP_CLI')||!WP_CLI||get_home_url(Stores::main_id())!=='https://www.animecats.online'||get_current_user_id()!==1) { throw new RuntimeException('Wrong installation or administrator'); }
Stores::authorize();
$file='/gsm-check/session.json';
if (($args[0]??'')==='close') {
    if (is_file($file)) { $data=json_decode(file_get_contents($file),true); WP_Session_Tokens::get_instance(1)->destroy($data['token']); }
    return;
}
if (($args[0]??'')!=='open'||is_file($file)) { throw new RuntimeException('Invalid session operation'); }
$expires=time()+900; $token=WP_Session_Tokens::get_instance(1)->create($expires);
file_put_contents($file,wp_json_encode(['token'=>$token])); chmod($file,0600);
file_put_contents('/gsm-check/headers','Cookie: '.SECURE_AUTH_COOKIE.'='.wp_generate_auth_cookie(1,$expires,'secure_auth',$token).'; '.LOGGED_IN_COOKIE.'='.wp_generate_auth_cookie(1,$expires,'logged_in',$token)); chmod('/gsm-check/headers',0600);
