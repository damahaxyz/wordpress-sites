<?php
/** Run only in the disposable QA network; contains synthetic users/orders and blocks all mail. */
use GroupShop\Manager\{Stores,Schema,Catalog,Availability,Taxonomies,Customers,Orders,Mail,Admin};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !in_array(wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_HOST).':'.wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_PORT),['localhost:18086','localhost:18087'],true)) { throw new RuntimeException('Disposable QA network required.'); }
if (GSM_VERSION!=='0.3.0') { throw new RuntimeException('Historical v030 independent-taxonomy suite. For v031 use prepare-local.php, product-editor.php and shared-taxonomies.php.'); }
$admin=get_user_by('login','gsm-admin'); wp_set_current_user($admin->ID);
$checks=[];
$assert=static function ($condition,string $name) use (&$checks): void { if (!$condition) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$throws=static function (callable $fn,string $name) use ($assert): void { $failed=false; try { $fn(); } catch (Throwable $e) { $failed=true; } $assert($failed,$name); };
$run=static fn(int $store,callable $fn)=>Stores::within($store,$fn);
$main=Stores::main_id();
$smtp=['connections'=>[], 'mappings'=>[], 'misc'=>['default_connection'=>'qa-a','simulate_emails'=>'yes']];
foreach (['a','b'] as $letter) {
    $sender='orders-brand-'.$letter.'@example.invalid';
    $smtp['connections']['qa-'.$letter]=['title'=>'QA only','provider_settings'=>['provider'=>'smtp','sender_email'=>$sender,'sender_name'=>'Brand '.strtoupper($letter),'host'=>'smtp.example.invalid','port'=>587,'auth'=>'no','username'=>'','password'=>'']];
    $smtp['mappings'][$sender]='qa-'.$letter;
}
update_option('fluentmail-settings',$smtp,false);
if (function_exists('fluentMailGetSettings')) { fluentMailGetSettings([],false); }

require_once ABSPATH . 'wp-admin/includes/ms.php';
foreach (get_sites(['number'=>100,'site__not_in'=>[$main]]) as $fixture_site) {
    if (in_array($fixture_site->path,['/brand-a/','/brand-b/'],true)) { wpmu_delete_blog((int)$fixture_site->blog_id,true); }
}
$existing=get_site_by_path((string)get_network()->domain,'/brand-a/');
$a=$existing&&(int)$existing->blog_id!==$main?(int)$existing->blog_id:Stores::create('Brand A','brand-a');
$existing=get_site_by_path((string)get_network()->domain,'/brand-b/');
$b=$existing&&(int)$existing->blog_id!==$main?(int)$existing->blog_id:Stores::create('Brand B','brand-b');
$assert($run($a,fn()=>count(get_terms(['taxonomy'=>'product_cat','hide_empty'=>false])))===1,'New stores start with native defaults and do not inherit main classifications');
$assert($a!==$b&&count(Stores::all())>=3,'Independent stores are created inside the network');
$assert($run($a,fn()=>get_option('blog_public'))=='0'&&!$run($a,fn()=>Stores::settings()['mail_enabled']),'New stores start noindex with customer mail blocked');
$uuid=$run($a,fn()=>Stores::settings()['uuid']);
foreach ([$a=>'Brand A',$b=>'Brand B'] as $id=>$name) { Stores::save($id,['name'=>$name,'theme'=>'trovesia','from_name'=>$name,'from_email'=>'orders-'.strtolower(str_replace(' ','-',$name)).'@example.invalid','support_email'=>'support@example.invalid','mail_enabled'=>1]); }
$assert($uuid===$run($a,fn()=>Stores::settings()['uuid']),'Saving branding preserves permanent store identity');
$throws(fn()=>Stores::within(999999,fn()=>true),'Unknown store rejected');
$throws(fn()=>Stores::within($b,fn()=>throw new RuntimeException('fixture')),'Scope exception surfaces');
$assert(get_current_blog_id()===$main,'Blog context restored after exception');
$suffix=bin2hex(random_bytes(3));
$source=$run($main,static function () use ($suffix): int {
    $upload=wp_upload_bits('gsm-qa-'.$suffix.'.png',null,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
    $image=wp_insert_attachment(['post_title'=>'QA image','post_mime_type'=>'image/png','post_status'=>'inherit'],$upload['file']);
    $term=wp_insert_term('QA source '.$suffix,'product_cat');
    $p=new WC_Product_Simple(); $p->set_name('Master '.$suffix); $p->set_regular_price('25.00'); $p->set_manage_stock(true); $p->set_stock_quantity(9); $p->set_status('publish'); $p->set_description('<p>Original content</p><img src="'.wp_get_attachment_url($image).'">'); $p->set_image_id($image); $p->set_category_ids([$term['term_id']]); return $p->save();
});
$email='gsm-qa-'.$suffix.'@example.invalid';
$customer=wc_create_new_customer($email,'gsm-qa-'.$suffix,'Shared-QA-Password-1!');
$assert(!is_wp_error($customer),'One shared customer created'); $customer=(int)$customer;
$comment=wp_insert_comment(['comment_post_ID'=>$source,'comment_type'=>'review','comment_author'=>'QA original','comment_content'=>'Only main store','comment_approved'=>1,'user_id'=>$customer]); add_comment_meta($comment,'rating',5);
$pa=Catalog::publish($source,$a,Catalog::FIELDS); $pb=Catalog::publish($source,$b,Catalog::FIELDS);
$assert($run($a,fn()=>wc_get_product($pa)->get_status())==='draft','New edition remains draft');
$assert($run($a,fn()=>!wc_get_product($pa)->managing_stock()&&wc_get_product($pa)->is_in_stock()),'New edition has unlimited stock without managing quantities');
$assert($run($a,fn()=>get_comments(['post_id'=>$pa,'type'=>'review','count'=>true]))==0,'Publishing does not copy master comments');
$assert($run($a,fn()=>str_contains(wc_get_product($pa)->get_description(),'/sites/'.$a.'/')),'Inline image is localized into destination media library');
$assert(Catalog::publish($source,$a,['prices'])===$pa,'Repeated publication reuses the same product mapping');
Catalog::save_edition($a,$pa,['name'=>'Brand A edition','description'=>'','regular_price'=>'31.00','sale_price'=>'','menu_order'=>7]);
$assert($run($a,fn()=>wc_get_product($pa)->get_description())==='','Explicit empty description is preserved');
Catalog::publish($source,$a,['prices']);
$assert($run($a,fn()=>wc_get_product($pa)->get_name())==='Brand A edition','Price-only publication preserves local title');
$assert($run($a,fn()=>wc_get_product($pa)->get_description())==='','Price-only publication preserves intentional empty content');
$assert($run($b,fn()=>wc_get_product($pb)->get_menu_order())===0&&$run($a,fn()=>wc_get_product($pa)->get_menu_order())===7,'Store ordering is independent');
$throws(fn()=>Catalog::save_edition($a,$pa,['regular_price'=>'10','sale_price'=>'11']),'Invalid sale price rejected');
$throws(fn()=>Catalog::save_edition($a,$pa,['category_ids'=>'99999999']),'Foreign or missing category rejected');
$assert($run($a,fn()=>get_option('woocommerce_manage_stock'))==='no','Stock management is disabled at store level');
$assert($run($b,fn()=>wc_get_product($pb)->is_in_stock())&&$run($main,fn()=>wc_get_product($source)->is_in_stock()),'Main and child editions are always in stock');
Catalog::publish($source,$a,['prices']);
$assert($run($a,fn()=>!wc_get_product($pa)->get_manage_stock('edit')&&wc_get_product($pa)->get_stock_status('edit')==='instock'),'Republishing preserves unlimited stock policy');
$variable=$run($main,static function () use ($suffix): int {
    $attr=new WC_Product_Attribute(); $attr->set_name('Pack'); $attr->set_options(['Single','Double']); $attr->set_visible(true); $attr->set_variation(true);
    $p=new WC_Product_Variable(); $p->set_name('Variable '.$suffix); $p->set_attributes([$attr]); $p->set_status('publish'); $id=$p->save();
    foreach (['Single'=>29.9,'Double'=>49.9] as $value=>$price) { $v=new WC_Product_Variation(); $v->set_parent_id($id); $v->set_attributes(['pack'=>$value]); $v->set_regular_price((string)$price); $v->set_manage_stock(true); $v->set_stock_quantity(8); $v->save(); }
    WC_Product_Variable::sync($id); return $id;
});
$va=Catalog::publish($variable,$a,Catalog::FIELDS);
$variants=$run($a,fn()=>wc_get_product($va)->get_children());
$assert(count($variants)===2&&$run($a,fn()=>!wc_get_product($variants[0])->managing_stock()&&wc_get_product($variants[0])->is_in_stock()),'Variable products map options with unlimited variation stock');
Catalog::save_edition($a,$variants[0],['regular_price'=>'39.00','sale_price'=>'']);
$assert($run($a,fn()=>wc_get_product($variants[0])->get_regular_price())==='39.00','Independent variation price can be edited centrally');
$auth=$run($a,fn()=>wp_authenticate($email,'Shared-QA-Password-1!'));
$assert($auth instanceof WP_User&&$auth->ID===$customer,'Master registration credentials work in another shop');
$run($a,fn()=>Customers::logged_in($auth->user_login,$auth));
$run($b,fn()=>Customers::logged_in($auth->user_login,$auth));
$assert((int)get_user_meta($customer,'_gsm_first_store',true)===$main,'First registration origin is not overwritten by later shop login');
$assert(is_user_member_of_blog($customer,$a)&&is_user_member_of_blog($customer,$b),'Shared login creates per-store customer membership');
$run($a,static function () use ($customer): void { $c=new WC_Customer($customer); $c->set_billing_city('Shared QA City'); $c->save(); });
$assert($run($b,fn()=>(new WC_Customer($customer))->get_billing_city())==='Shared QA City','Customer address is shared across stores');
$token=WP_Session_Tokens::get_instance($customer)->create(time()+3600);
wp_set_password('Shared-QA-Password-2!',$customer);
$assert(!WP_Session_Tokens::get_instance($customer)->verify($token),'Password change revokes previous shared sessions');
$assert($run($b,fn()=>wp_authenticate($email,'Shared-QA-Password-2!')) instanceof WP_User,'New global password works in another store');
$reset=$run($b,fn()=>Customers::reset_message('','qa-reset-token','gsm-qa-'.$suffix,get_userdata($customer)));
$emails=WC()->mailer()->get_emails();
$additional=$emails['WC_Email_Customer_Reset_Password']->get_additional_content();
$assert(str_contains($additional,'all stores'),'Native WooCommerce reset email explains the shared password scope');
$assert(str_contains($reset,'Brand B')&&str_contains($reset,'/brand-b/wp-login.php')&&str_contains($reset,'全部店铺'),'Reset mail uses current shop and explains global password scope');
$oa=$run($a,static function () use ($customer,$pa): int { $o=wc_create_order(['customer_id'=>$customer]); $o->add_product(wc_get_product($pa),1); $o->calculate_totals(); $o->payment_complete('QA-NOT-A-REAL-PAYMENT'); return $o->get_id(); });
$ob=$run($b,static function () use ($customer,$pb): int { $o=wc_create_order(['customer_id'=>$customer]); $o->add_product(wc_get_product($pb),1); $o->calculate_totals(); return $o->get_id(); });
$assert($run($a,fn()=>(int)wc_get_order($oa)->get_meta('_gsm_store_id'))===$a,'Order origin is stamped with owning store');
$run($a,fn()=>update_option('blogname','Brand A renamed'));
$assert($run($a,fn()=>wc_get_order($oa)->get_meta('_gsm_store_name'))==='Brand A','Historical order brand snapshot does not change after rename');
$list=Orders::list([$a,$b]);
$found=array_filter($list['items'],fn($r)=>($r['store']===$a&&$r['id']===$oa)||($r['store']===$b&&$r['id']===$ob));
$assert(count($found)===2,'Central orders retain identity even when local numeric IDs collide');
Orders::change_status($a,$oa,'completed','processing');
$assert($run($a,fn()=>wc_get_order($oa)->get_status())==='completed'&&$run($b,fn()=>wc_get_order($ob)->get_status())==='pending','Central order status update affects only owning store');
$throws(fn()=>Orders::change_status($a,$oa,'completed','processing'),'Repeated stale status update is rejected');
$throws(fn()=>Orders::change_status($b,$ob,'processing','pending'),'Central management cannot simulate payment completion');
$run($a,static function () use ($pa,$customer): void { $id=wp_insert_comment(['comment_post_ID'=>$pa,'comment_type'=>'review','comment_author'=>'QA A','comment_content'=>'Only A edition','comment_approved'=>1,'user_id'=>$customer]); add_comment_meta($id,'rating',4); });
$assert($run($b,fn()=>get_comments(['post_id'=>$pb,'type'=>'review','count'=>true]))==0,'Review content remains isolated between shop editions');
$assert($run($b,fn()=>wc_customer_bought_product($email,$customer,$pb))===false,'An unpaid order does not inherit another store purchase verification');
$assert($run($b,fn()=>get_option('fluentmail-settings')['mappings']['orders-brand-a@example.invalid'])==='qa-a','Subsite reads the centrally managed FluentSMTP configuration');
if (function_exists('fluentMailgetConnection')) {
    $assert($run($a,fn()=>fluentMailgetConnection('orders-brand-a@example.invalid')->getSetting('sender_email'))==='orders-brand-a@example.invalid','FluentSMTP resolves the exact A sender without copying credentials');
    $assert($run($b,fn()=>fluentMailgetConnection('orders-brand-b@example.invalid')->getSetting('sender_email'))==='orders-brand-b@example.invalid','FluentSMTP resolves B sender after switching from A in the same request');
}
$saved_mail_settings=$run($b,fn()=>Stores::settings());
$run($b,fn()=>update_option('gsm_store_settings',array_merge(Stores::settings(),['from_email'=>'unmapped@example.invalid'])));
$assert($run($b,fn()=>wp_mail('qa@example.invalid','Unmapped synthetic QA','Synthetic'))===false,'Missing exact SMTP mapping cannot fall back to the master sender');
$run($b,fn()=>update_option('gsm_store_settings',$saved_mail_settings));
$headers=$run($b,fn()=>Mail::headers(['headers'=>[]]));
$assert(in_array('X-Group-Shop-Store: '.$b,$headers['headers'],true)&&in_array('Reply-To: support@example.invalid',$headers['headers'],true),'Mail carries explicit store ownership and reply address');
$assert($run($b,fn()=>Mail::from('fallback@example.invalid'))==='orders-brand-b@example.invalid','Sender address resolves from the order shop context');
$footer=$run($b,fn()=>Mail::footer(''));
$assert(str_contains($footer,'Brand B')&&!str_contains($footer,'Brand A')&&str_contains($footer,'/brand-b/'),'Mail footer cannot leak another shop brand or links');
$run($b,fn()=>update_option('gsm_store_settings',array_merge(Stores::settings(),['mail_enabled'=>false])));
$assert($run($b,fn()=>wp_mail('qa@example.invalid','Blocked synthetic QA','Synthetic'))===false,'Unconfigured or disabled shop mail fails closed');
$run($b,fn()=>update_option('gsm_store_settings',array_merge(Stores::settings(),['mail_enabled'=>true])));
wp_set_current_user($customer);
$throws(fn()=>Availability::migrate($a),'Customer cannot run stock policy migration');
$throws(fn()=>Catalog::publish($source,$b,['name']),'Customer cannot publish master data');
$throws(fn()=>Orders::list([$a,$b]),'Customer cannot query central order dashboard');
$throws(fn()=>Stores::save($b,['name'=>'Attack']),'Customer cannot edit store identity');
wp_set_current_user($admin->ID);
foreach (['overview','stores','collections','catalog','products','orders','customers','reviews','audit','help'] as $tab) {
    $_GET=['page'=>'muti-storefront-plugin','tab'=>$tab,'store'=>$a]; ob_start(); Admin::render(); $html=ob_get_clean();
    $assert(str_contains($html,'muti-storefront-plugin')&&!str_contains($html,'notice-error'),'Admin screen renders: '.$tab);
    $assert(get_current_blog_id()===$main,'Admin screen restores main context: '.$tab);
}
$throws(fn()=>Orders::change_status($a,$oa,'cancelled','completed'),'Completed orders cannot be cancelled through the limited first-version workflow');
$_GET=['page'=>'muti-storefront-plugin','tab'=>'orders','store'=>$a,'order'=>$oa]; ob_start(); Admin::render(); $detail=ob_get_clean();
$assert(str_contains($detail,'订单记录')&&str_contains($detail,'收货资料')&&str_contains($detail,'内部备注'),'Central order details expose fulfillment data and internal notes in the owning store');
$assert(get_current_blog_id()===$main,'Order detail restores main context');
require __DIR__.'/classification-suite.php';
require __DIR__.'/native-category-migration.php';
// Fixtures intentionally retained in the isolated environment for browser QA; never run on production.
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'stores'=>[$main,$a,$b],'fixtures'=>['source'=>$source,'edition_a'=>$pa,'edition_b'=>$pb,'variable_a'=>$va,'customer'=>$customer,'order_a'=>$oa,'order_b'=>$ob,'classification'=>$classification_fixtures]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
