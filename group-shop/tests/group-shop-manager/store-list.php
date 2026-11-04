<?php
use GroupShop\Manager\{Stores,Admin};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!in_array(wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_HOST).':'.wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_PORT),['localhost:18086','localhost:18087'],true)) { throw new RuntimeException('Isolated QA only'); }
$admin=get_user_by('login','gsm-admin'); wp_set_current_user($admin->ID);
$a=(int)get_site_by_path((string)get_network()->domain,'/brand-a/')->blog_id;
$b=(int)get_site_by_path((string)get_network()->domain,'/brand-b/')->blog_id;
$checks=[];
$assert=static function($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$throws=static function($fn,$name) use ($assert): void { $failed=false; try { $fn(); } catch (Throwable $e) { $failed=true; } $assert($failed,$name); };
$snapshot=static fn($id)=>Stores::within($id,static fn()=>['name'=>get_bloginfo('name'),'tagline'=>get_bloginfo('description'),'theme'=>get_stylesheet(),'logo_id'=>Stores::logo_id(),'blog_public'=>(string)get_option('blog_public')]+Stores::settings());
$before=$snapshot($a); $site=get_site($a); $home=get_blog_option($a,'home'); $siteurl=get_blog_option($a,'siteurl');
$other=$snapshot($b); $main=get_site(Stores::main_id());
$rows_before=Stores::within($a,static fn()=>['users'=>count_users()['total_users'],'products'=>wc_get_products(['limit'=>-1,'return'=>'ids','status'=>['publish','draft','private']]),'orders'=>wc_get_orders(['limit'=>-1,'return'=>'ids']),'logo'=>Stores::logo_id()]);
$new_id=0;
try {
    $assert(Stores::domain('HTTPS://MiXeD.example.org/')==='mixed.example.org','HTTPS domain input normalizes to a lowercase hostname');
    $assert(Stores::domain($site->domain,$a)===$site->domain,'Unchanged native subdirectory domain remains valid');
    foreach (['','http://unsafe.example.org','https://user@example.org','https://example.org/path','example.org:443','*.example.org','127.0.0.1','localhost','-bad.example.org','foo..example.org','example.org?x=1','例子.中国'] as $value) {
        $throws(fn()=>Stores::domain($value),'Invalid domain rejected: '.($value?:'(empty)'));
    }
    $throws(fn()=>Stores::save(Stores::main_id(),['domain'=>'moved.example.org']),'Main network domain is protected server-side');
    $throws(fn()=>Stores::domain($main->domain),'Domain used by an existing root or subdirectory site is rejected');
    $throws(fn()=>Stores::save($a,array_merge($before,['domain'=>'safe.example.org','logo_id'=>99999999])),'Invalid settings prevent any domain mutation');
    $assert(get_site($a)->domain===$site->domain,'Rejected settings leave native domain intact');
    Stores::save($a,array_merge($before,['domain'=>'mapped-qa.example.org']));
    $assert(get_site($a)->domain==='mapped-qa.example.org'&&get_site($a)->path==='/','Domain edit updates native site domain and root path');
    $assert(get_blog_option($a,'home')==='https://mapped-qa.example.org'&&get_blog_option($a,'siteurl')==='https://mapped-qa.example.org','Domain edit updates native home and siteurl using HTTPS');
    $assert($snapshot($a)===$before,'Brand, mail settings, UUID, theme, logo and visibility survive a domain change');
    $assert($snapshot($b)===$other&&get_site(Stores::main_id())->domain===$main->domain,'Domain edits do not change other stores or network host');
    $rows_after=Stores::within($a,static fn()=>['users'=>count_users()['total_users'],'products'=>wc_get_products(['limit'=>-1,'return'=>'ids','status'=>['publish','draft','private']]),'orders'=>wc_get_orders(['limit'=>-1,'return'=>'ids']),'logo'=>Stores::logo_id()]);
    $assert($rows_before===$rows_after,'Products, orders, users and logo attachment identity are retained');
    $throws(fn()=>Stores::domain('mapped-qa.example.org',$b),'Another store cannot bind the same domain');
    $throws(fn()=>Stores::save($b,array_merge($other,['domain'=>'mapped-qa.example.org'])),'Duplicate domain is rejected on save');
    $_GET=['page'=>'group-shop-manager','tab'=>'stores']; ob_start(); Admin::render(); $list=ob_get_clean();
    $assert(substr_count($list,'<tr data-store=')===count(Stores::all()),'Store list has exactly one record per active store');
    $assert(str_contains($list,'mapped-qa.example.org')&&str_contains($list,'gsm-store-dialog')&&str_contains($list,'data-gsm-store-dialog'),'List shows mapped domain and modal edit/create entry points');
    $assert(!str_contains($list,'name="logo_id"')&&!str_contains($list,'name="slug"')&&!str_contains($list,'选择店铺 <select'),'List contains no inline editor or old store selector');
    $_GET=['page'=>'group-shop-manager','tab'=>'stores','store'=>$a,'gsm_dialog'=>'edit']; ob_start(); Admin::render(); $form=ob_get_clean();
    $assert(str_contains($form,'name="domain" value="mapped-qa.example.org"')&&str_contains($form,'从媒体库选择图片'),'Child editor shows its own domain and native media selector');
    $assert(!str_contains($form,'nav-tab-wrapper')&&!str_contains($form,'gsm-stores-table'),'Popup editor omits duplicate navigation and list');
    $_GET['gsm_saved']=wp_create_nonce('gsm_store_saved_'.$a); delete_site_transient('gsm_notice_'.get_current_user_id());
    ob_start(); Admin::render(); $saved=ob_get_clean();
    $assert(str_contains($saved,'data-gsm-store-saved')&&!str_contains($saved,'name="domain"'),'Verified success redirect works even after another request consumed its flash notice');
    $_GET['store']=$b; ob_start(); Admin::render(); $wrong=ob_get_clean();
    $assert(!str_contains($wrong,'data-gsm-store-saved')&&str_contains($wrong,'name="domain"'),'Success token is scoped to the store and cannot mark another store saved');
    unset($_GET['gsm_saved']);
    $_GET['store']=Stores::main_id(); ob_start(); Admin::render(); $form=ob_get_clean();
    $assert((bool)preg_match('/name="domain"[^>]+readonly/',$form),'Main-store domain input is read-only');
    $_GET=['page'=>'group-shop-manager','tab'=>'stores','gsm_dialog'=>'create']; ob_start(); Admin::render(); $form=ob_get_clean();
    $assert(str_contains($form,'name="domain"')&&str_contains($form,'name="gsm_dialog" value="create"')&&!str_contains($form,'name="slug"'),'Create dialog asks for name and domain without an internal slug');
    $new_id=Stores::create('Mapped QA','mapped-qa-'.bin2hex(random_bytes(2)),'new-mapped-qa.example.org');
    $assert(get_site($new_id)->domain==='new-mapped-qa.example.org'&&get_site($new_id)->path==='/'&&get_blog_option($new_id,'siteurl')==='https://new-mapped-qa.example.org','New store is created directly on its mapped HTTPS domain');
    $assert(get_blog_option($new_id,'blog_public')=='0'&&!get_blog_option($new_id,'gsm_store_settings')['mail_enabled'],'New mapped store remains noindex with mail disabled');
    $assert(get_blog_option($new_id,'woocommerce_manage_stock')==='no'&&get_blog_option($new_id,'woocommerce_shop_page_id')>0,'New mapped store reuses native WooCommerce setup and unlimited availability');
    update_blog_details($new_id,['archived'=>1]);
    $throws(fn()=>Stores::domain('new-mapped-qa.example.org',$b),'Archived stores still reserve their domain');
    $customer=get_users(['role'=>'customer','number'=>1,'blog_id'=>$a]);
    if ($customer) { wp_set_current_user($customer[0]->ID); $throws(fn()=>Stores::domain('forbidden.example.org',$a),'Customer cannot change or validate cross-store domain bindings'); wp_set_current_user($admin->ID); }
} finally {
    wp_set_current_user($admin->ID);
    update_blog_details($a,['domain'=>$site->domain,'path'=>$site->path]);
    Stores::within($a,static function() use ($home,$siteurl): void { update_option('home',$home); update_option('siteurl',$siteurl); });
    if ($new_id) { require_once ABSPATH.'wp-admin/includes/ms.php'; wpmu_delete_blog($new_id,true); }
}
$assert(get_current_blog_id()===Stores::main_id(),'All operations restore the main store context');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
