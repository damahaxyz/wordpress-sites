<?php
use GroupShop\Manager\{Stores,Admin,Customers,UserAdmin};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || get_home_url(Stores::main_id()) !== 'http://www.main.local') { throw new RuntimeException('Mapped local synthetic QA only'); }
$admin=get_user_by('login','gsm-admin')->ID; wp_set_current_user($admin);
$checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$main=Stores::main_id();
$columns=['username'=>'Username','email'=>'Email','blogs'=>'Sites'];
$result=apply_filters('wpmu_users_columns',$columns);
$assert(array_diff_assoc($columns,$result)===[] && $result['gsm_first_store']==='首次来源店铺','Native network columns and Sites remain with one origin column');
$assert(!has_filter('manage_users_columns',[UserAdmin::class,'columns']) && !has_filter('manage_users_custom_column',[UserAdmin::class,'column']),'Site user list and generic column output are not extended');
$assert(UserAdmin::column('Other output','other',1)==='Other output','Other plugin columns retain their output');
$id=wp_insert_user(['user_login'=>'gsm-native-users-'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(32),'role'=>'customer']);
if (is_wp_error($id)) { throw new RuntimeException('Synthetic user creation failed'); }
try {
    $assert((int)get_user_meta($id,'_gsm_first_store',true)===$main,'Native registration still records initial store');
    foreach ([$main,6,7] as $store) {
        update_user_meta($id,'_gsm_first_store',$store);
        $expected=Stores::within($store,static fn()=>get_bloginfo('name').' (#'.get_current_blog_id().')');
        $before=get_user_meta($id);
        $output=apply_filters('manage_users-network_custom_column','','gsm_first_store',$id);
        $assert($output===esc_html($expected),'Origin resolves correct network store: '.$store);
        $assert(get_user_meta($id)===$before,'Rendering does not mutate user metadata: '.$store);
    }
    $filter=static fn()=>'<b>QA & Store</b>';
    add_filter('option_blogname',$filter);
    try { $assert(UserAdmin::column('','gsm_first_store',$id)==='&lt;b&gt;QA &amp; Store&lt;/b&gt; (#7)','Store labels are escaped'); }
    finally { remove_filter('option_blogname',$filter); }
    foreach (['','bad',-6] as $value) {
        update_user_meta($id,'_gsm_first_store',$value);
        $assert(UserAdmin::column('','gsm_first_store',$id)==='历史用户／未记录','Missing or malformed origin is not inferred: '.var_export($value,true));
    }
    update_user_meta($id,'_gsm_first_store',99999999);
    $assert(UserAdmin::column('','gsm_first_store',$id)==='店铺 #99999999（已停用或不可用）','Unavailable origin retains ID without falling back to main');
    $assert(get_current_blog_id()===$main,'Origin success and failure restore current store');
    update_user_meta($id,'_gsm_first_store',$main);
    add_user_to_blog(6,$id,'editor');
    $user=get_userdata($id);
    Stores::within(6,static fn()=>Customers::logged_in($user->user_login,$user));
    Stores::within(7,static fn()=>Customers::logged_in($user->user_login,$user));
    $assert(Stores::within(6,static fn()=>in_array('editor',(new WP_User($id))->roles,true)),'Existing store role is not downgraded on login');
    $assert(Stores::within(7,static fn()=>in_array('customer',(new WP_User($id))->roles,true)),'New store login still joins with customer role');
    $assert((int)get_user_meta($id,'_gsm_first_store',true)===$main && get_user_meta($id,'_gsm_store_6_joined_at',true) && get_user_meta($id,'_gsm_store_7_joined_at',true),'Later logins preserve origin and record historical store visits');
    update_user_meta($id,'billing_city','QA Billing'); update_user_meta($id,'shipping_city','QA Shipping');
    $assert(Stores::within(6,static fn()=>get_user_meta($id,'billing_city',true))==='QA Billing' && Stores::within(7,static fn()=>get_user_meta($id,'shipping_city',true))==='QA Shipping','Default billing and shipping metadata remain network-shared');
    $sessions=WP_Session_Tokens::get_instance($id); $sessions->create(time()+600);
    wp_set_password(wp_generate_password(32),$id);
    $assert($sessions->get_all()===[],'Password change still revokes existing shared-user sessions');
    wp_set_current_user($id);
    $assert(!isset(UserAdmin::columns($columns)['gsm_first_store']) && UserAdmin::column('unchanged','gsm_first_store',$id)==='unchanged','Non-network user cannot use origin display extension');
} finally {
    wp_set_current_user($admin);
    require_once ABSPATH.'wp-admin/includes/user.php'; require_once ABSPATH.'wp-admin/includes/ms.php';
    wpmu_delete_user($id);
}
$_GET=['page'=>'muti-storefront-plugin','tab'=>'customers']; ob_start(); Admin::render(); $html=ob_get_clean();
$assert(!str_contains($html,'共享客户') && !str_contains($html,'tab=customers') && str_contains($html,'<h2>店铺入口</h2>'),'Retired customer tab falls back to overview without old list');
$assert(str_contains($html,esc_url(network_admin_url('users.php'))),'Overview links to native network users');
$assert(substr_count($html,'class="nav-tab ')===7,'Seven current manager tabs remain');
$assert(get_current_blog_id()===$main,'Shared-user lifecycle tests restore original context');
$fixtures=[];
foreach (get_users(['blog_id'=>0,'number'=>20,'orderby'=>'ID']) as $user) {
    $fixtures[]=['id'=>$user->ID,'origin'=>get_user_meta($user->ID,'_gsm_first_store',true),'label'=>UserAdmin::column('','gsm_first_store',$user->ID)];
}
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'fixtures'=>$fixtures],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
