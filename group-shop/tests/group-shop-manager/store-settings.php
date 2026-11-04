<?php
use GroupShop\Manager\{Stores,Media,Admin};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!in_array(wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_HOST).':'.wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_PORT),['localhost:18086','localhost:18087'],true)) { throw new RuntimeException('Isolated QA only'); }
$admin=get_user_by('login','gsm-admin'); wp_set_current_user($admin->ID);
require_once ABSPATH.'wp-admin/includes/image.php';
$a=(int)get_site_by_path((string)get_network()->domain,'/brand-a/')->blog_id;
$b=(int)get_site_by_path((string)get_network()->domain,'/brand-b/')->blog_id;
$checks=[]; $assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$throws=static function ($fn,$name) use ($assert): void { $failed=false; try { $fn(); } catch (Throwable $e) { $failed=true; } $assert($failed,$name); };
$before=Stores::within($a,fn()=>['name'=>get_bloginfo('name'),'tagline'=>get_bloginfo('description'),'theme'=>get_stylesheet(),'logo_id'=>Stores::logo_id(),'blog_public'=>(string)get_option('blog_public')]+Stores::settings());
$main_public=get_blog_option(Stores::main_id(),'blog_public'); $other_public=get_blog_option($b,'blog_public');
$suffix=bin2hex(random_bytes(3));
$make_image=static function ($store,$title): int { return Stores::within($store,static function () use ($title): int {
    $file=wp_upload_bits(sanitize_title($title).'.png',null,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
    $id=(int)wp_insert_attachment(['post_title'=>$title,'post_mime_type'=>'image/png','post_status'=>'inherit'],$file['file']);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file['file'])); return $id;
}); };
$image=$make_image($a,'GSM child logo '.$suffix); $master_image=$make_image(Stores::main_id(),'GSM main logo '.$suffix);
try {
    Stores::save($a,array_merge($before,['logo_id'=>$image,'blog_public'=>'0']));
    $assert(Stores::within($a,fn()=>Stores::logo_id())===$image,'Store-level logo is selected from its own media library');
    $assert(Stores::within($a,fn()=>str_contains(get_custom_logo(),'/sites/'.$a.'/')),'Native custom-logo output uses the local selected attachment');
    $assert(Stores::within($a,fn()=>get_option('blog_public'))=='0'&&get_site($a)->public=='0','Discourage setting updates native blog_public and Multisite visibility');
    $assert(Stores::within($a,fn()=>!empty(wp_robots_noindex([])['noindex'])),'Native robots output contains noindex when discouraged');
    Stores::save($a,array_merge($before,['logo_id'=>$image,'blog_public'=>'1']));
    $assert(Stores::within($a,fn()=>get_option('blog_public'))=='1'&&get_site($a)->public=='1','Allow setting updates native WordPress visibility');
    $assert(Stores::within($a,fn()=>empty(wp_robots_noindex([])['noindex'])),'Allow mode removes WordPress default noindex');
    $assert(get_blog_option(Stores::main_id(),'blog_public')==$main_public&&get_blog_option($b,'blog_public')==$other_public,'Visibility changes are isolated to the selected store');
    $args=$before; unset($args['logo_id'],$args['blog_public']); Stores::save($a,$args);
    $assert(Stores::within($a,fn()=>Stores::logo_id())===$image&&Stores::within($a,fn()=>get_option('blog_public'))=='1','Omitted fields preserve existing logo and visibility');
    $throws(fn()=>Stores::save($a,array_merge($before,['logo_id'=>999999999])),'Invalid attachment is rejected');
    $throws(fn()=>Stores::save($a,array_merge($before,['blog_public'=>'bad'])),'Invalid visibility mode is rejected');
    Stores::within($a,static function () use ($image,$assert,$before): void {
        $other=get_stylesheet()==='twentytwentyfive'?'trovesia':'twentytwentyfive'; switch_theme($other);
        $assert((int)get_theme_mod('custom_logo')===$image,'Switching themes retains the shop logo');
        set_theme_mod('custom_logo',0);
        $assert(Stores::logo_id()===0,'Native logo removal updates the shared shop setting');
        set_theme_mod('custom_logo',$image);
        $assert(Stores::logo_id()===$image,'Native custom-logo editing updates the same source');
        switch_theme($before['theme']);
    });
    $request=['action'=>'query-attachments','gsm_media_store'=>$a,'gsm_media_nonce'=>wp_create_nonce('gsm_media_'.$a)];
    $assert(Media::target($request)===$a,'Media target validates explicit shop nonce');
    $throws(fn()=>Media::target(array_merge($request,['gsm_media_nonce'=>'invalid'])),'Invalid media nonce rejected');
    $throws(fn()=>Media::target(array_merge($request,['action'=>'delete-post'])),'Destructive or unsupported media action cannot fall back to the main store');
} finally {
    wp_set_current_user($admin->ID); Stores::save($a,$before);
}
$customer=get_users(['role'=>'customer','number'=>1,'blog_id'=>$a]);
if ($customer) { wp_set_current_user($customer[0]->ID); $throws(fn()=>Media::target($request),'Customer cannot access cross-store media'); wp_set_current_user($admin->ID); }
$_GET=['page'=>'group-shop-manager','tab'=>'stores','store'=>$a,'gsm_dialog'=>'edit']; ob_start(); Admin::render(); $html=ob_get_clean();
$assert(str_contains($html,'从媒体库选择图片')&&str_contains($html,'name="blog_public"')&&!str_contains($html,'Logo 附件编号'),'Store UI provides native media selection and visibility modes');
$assert(get_current_blog_id()===Stores::main_id(),'Store-setting operations restore original context');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'store'=>$a,'image'=>$image,'main_image'=>$master_image,'child_title'=>'GSM child logo '.$suffix,'main_title'=>'GSM main logo '.$suffix],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
