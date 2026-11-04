<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Customer authentication is site-scoped; staff identity and passwords are network-wide. */
final class Customers {
    public static function boot(): void {
        remove_filter('authenticate','wp_authenticate_username_password',20);
        remove_filter('authenticate','wp_authenticate_email_password',20);
        add_filter('authenticate',[self::class,'authenticate'],15,3);
        add_filter('authenticate',[self::class,'final_auth'],PHP_INT_MAX,3);
        add_filter('determine_current_user',[self::class,'session_user'],PHP_INT_MAX);
        add_filter('wp_is_application_passwords_available_for_user',static fn($allowed,$user)=>(Identities::get($user->ID)['kind']??'')==='customer'?false:$allowed,20,2);
        add_filter('pre_update_site_option_site_admins',static function ($logins) {
            return array_values(array_filter((array)$logins,static function ($login): bool { $user=get_user_by('login',$login); return !$user||(Identities::get($user->ID)['kind']??'')!=='customer'; }));
        });
        add_filter('can_add_user_to_blog',[self::class,'can_add'],20,4);
        add_filter('update_user_metadata',[self::class,'protect_meta'],20,5);
        add_filter('add_user_metadata',[self::class,'protect_meta'],20,5);
        add_filter('delete_user_metadata',[self::class,'protect_delete_meta'],20,5);
        add_filter('map_meta_cap',[self::class,'capabilities'],30,4);
        add_filter('user_has_cap',[self::class,'customer_caps'],PHP_INT_MAX,4);
        add_filter('woocommerce_registration_errors',static function ($errors,$name,$email) {
            if (is_email($name)||str_starts_with(strtolower($name),'gsmc_')||str_starts_with(strtolower($name),'gsms_')||strlen($name)>60) { $errors->add('gsm_username','用户名无效，请使用非邮箱格式的用户名。'); }
            return $errors;
        },20,3);
        add_filter('rest_user_query',static function ($args) { $args['blog_id']=get_current_blog_id(); return $args; });
        add_filter('rest_pre_insert_user',static function ($data,$request) {
            $roles=$request->get_param('roles');
            if ($roles&&array_diff((array)$roles,['customer'])) { return new \WP_Error('gsm_role_locked','员工请通过网络用户入口创建或授权。',['status'=>403]); }
            return $data;
        },20,2);
        add_filter('rest_pre_dispatch',static function ($result,$server,$request) {
            if (preg_match('#^/(?:wc/v[1-3]/customers|wp/v2/users)/(\d+)(?:/|$)#',$request->get_route(),$m)) {
                $row=Identities::get((int)$m[1]);
                if ($row&&(($row['kind']==='customer'&&(int)$row['store_id']!==get_current_blog_id())||($row['kind']==='staff'&&str_starts_with($request->get_route(),'/wc/')))) { return new \WP_Error('gsm_user_scope','此账户不属于当前店铺的顾客。',['status'=>403]); }
            }
            return $result;
        },20,3);
        add_action('wp_login',[self::class,'logged_in'],20,2);
        add_action('wp_set_password',static function ($password,$id): void { \WP_Session_Tokens::get_instance((int)$id)->destroy_all(); },20,2);
        add_action('wp_loaded',[self::class,'prepare_reset'],1);
        add_action('wp_loaded',static function (): void {
            if (remove_action('woocommerce_customer_email_verified','wc_update_new_customer_past_orders')) {
                add_action('woocommerce_customer_email_verified',static function ($id): void {
                    if (self::local_customer((int)$id)) { wc_update_new_customer_past_orders((int)$id); }
                });
            }
        },0);
        add_action('login_init',[self::class,'native_registration']);
        add_action('before_signup_form',static function (): void { wp_safe_redirect(wc_get_page_permalink('myaccount')); exit; });
        add_action('init',[self::class,'guard_reset_request'],2);
        add_filter('allow_password_reset',[self::class,'allow_reset'],20,2);
        add_action('lostpassword_post',[self::class,'lostpassword'],20,2);
        add_action('validate_password_reset',[self::class,'validate_reset'],20,2);
        add_filter('retrieve_password_message',[self::class,'reset_message'],50,4);
        add_filter('wp_mail',[self::class,'mail_alias'],50);
        add_filter('gettext',[self::class,'translate'],20,3);
        add_action('woocommerce_lostpassword_form',[self::class,'scope_notice']);
        add_action('woocommerce_edit_account_form_start',[self::class,'scope_notice']);
        add_action('woocommerce_before_edit_address_form',[self::class,'scope_notice']);
        add_action('woocommerce_register_form_start',[self::class,'scope_notice']);
        add_filter('woocommerce_customer_get_username',static fn($name,$customer)=>Identities::get($customer->get_id())?Identities::alias($customer->get_id()):$name,20,2);
        add_filter('rest_prepare_user',static function ($response,$user) {
            $row=Identities::get($user->ID);
            if ($row&&$row['kind']==='customer') { $data=$response->get_data(); $data['slug']=$row['login_name']; if (isset($data['username'])) { $data['username']=$row['login_name']; } $response->set_data($data); }
            return $response;
        },20,2);
        add_action('woocommerce_check_cart_items',static function (): void { if (self::staff_shopping()) { wc_add_notice('请使用本店独立顾客账户下单，员工账户仅用于管理。','error'); } });
        add_action('woocommerce_store_api_cart_errors',static function ($errors): void { if (self::staff_shopping()) { $errors->add('gsm_staff_checkout','请使用本店独立顾客账户下单。'); } });
    }
    private static function staff_shopping(): bool {
        $id=get_current_user_id(); return $id&&(is_super_admin($id)||(Identities::get($id)['kind']??'')==='staff');
    }
    public static function local_customer(int $id): bool {
        $row=Identities::get($id);
        return $row&&$row['kind']==='customer'&&(int)$row['store_id']===get_current_blog_id();
    }
    private static function employee_entry(): bool {
        return ($GLOBALS['pagenow']??'')==='wp-login.php' || !empty($_REQUEST['gsm_employee']);
    }
    public static function resolve(string $input,bool $employee=false): ?\WP_User {
        $input=trim($input); if ($input===''||str_starts_with(strtolower($input),'gsmc_')||str_starts_with(strtolower($input),'gsms_')) { return null; }
        $email=is_email($input)!==false;
        $customer=Identities::find(get_current_blog_id(),$input,$email);
        $staff=Identities::find(0,$input,$email);
        $row=$employee?($staff??$customer):($customer??$staff);
        if ($row) { return get_userdata((int)$row['user_id'])?:null; }
        // A network super administrator stays reachable before the explicit migration.
        $native=get_user_by($email?'email':'login',$input);
        return $native&&is_super_admin($native->ID)?$native:null;
    }
    private static function failure(): \WP_Error { return new \WP_Error('gsm_invalid_login','用户名、邮箱或密码不正确，或此店尚未注册。'); }
    public static function authenticate(mixed $user,string $login,string $password): mixed {
        if ($user instanceof \WP_User) { return $user; }
        if ($login===''||$password==='') { return self::failure(); }
        $user=self::resolve($login,self::employee_entry());
        if (!$user||!Identities::accessible($user->ID)) { return self::failure(); }
        $checked=apply_filters('wp_authenticate_user',$user,$password);
        if (is_wp_error($checked)) { return $checked; }
        if (!wp_check_password($password,$user->user_pass,$user->ID)) { return self::failure(); }
        if (wp_password_needs_rehash($user->user_pass,$user->ID)) { wp_set_password($password,$user->ID); }
        return $user;
    }
    public static function final_auth(mixed $user,string $login='',string $password=''): mixed {
        return $user instanceof \WP_User&&!Identities::accessible($user->ID)?self::failure():$user;
    }
    public static function session_user(mixed $id): int|false {
        return $id&&Identities::accessible((int)$id)?(int)$id:false;
    }
    public static function record(int $id): void {
        $row=Identities::get($id); $store=$row&&$row['kind']==='customer'?(int)$row['store_id']:get_current_blog_id();
        add_user_meta($id,'_gsm_first_store',$store,true);
        add_user_meta($id,'_gsm_store_'.$store.'_joined_at',gmdate('c'),true);
    }
    public static function logged_in(string $login,\WP_User $user): void {
        // Membership is granted explicitly; logging in never adds a customer to another shop.
        if (Identities::accessible($user->ID)) { self::record($user->ID); }
    }
    public static function can_add(mixed $allowed,int $id,string $role,int $store): mixed {
        if (Identities::is_internal()) { return $allowed; }
        $row=Identities::get($id);
        if ($row&&$row['kind']==='customer') {
            return (int)$row['store_id']===$store&&$role==='customer'?$allowed:new \WP_Error('gsm_customer_scope','顾客只能属于原店铺并使用 Customer 角色。');
        }
        if (is_super_admin($id)||($row&&$row['kind']==='staff')) {
            return Stores::can_manage()&&in_array($role,['administrator','shop_manager'],true)?$allowed:new \WP_Error('gsm_staff_grant','员工店铺权限只能由网络管理员分配。');
        }
        return new \WP_Error('gsm_unclassified','请先完成账户身份迁移或从网络用户入口创建员工。');
    }
    public static function protect_meta(mixed $check,int $id,string $key,mixed $value,mixed $extra=null): mixed {
        if (Identities::is_internal()) { return $check; }
        $row=Identities::get($id); if (!$row) { return $check; }
        if ($row['kind']==='staff') {
            if (str_ends_with($key,'capabilities')&&!Stores::can_manage()) { return false; }
            return $check;
        }
        global $wpdb;
        if (preg_match('/^'.preg_quote($wpdb->base_prefix,'/').'(?:\d+_)?capabilities$/D',$key)) {
            $home=$wpdb->get_blog_prefix((int)$row['store_id']).'capabilities';
            if ($key!==$home || $value!==['customer'=>true]) { return false; }
        }
        if ($key==='primary_blog'&&(int)$value!==(int)$row['store_id']) { return false; }
        return $check;
    }
    public static function protect_delete_meta(mixed $check,int $id,string $key,mixed $value,mixed $all=null): mixed {
        if (Identities::is_internal()) { return $check; }
        $row=Identities::get($id);
        if ($row&&$row['kind']==='staff'&&str_ends_with($key,'capabilities')&&!Stores::can_manage()) { return false; }
        if ($row&&$row['kind']==='customer'&&(str_ends_with($key,'capabilities')||$key==='primary_blog')) { return false; }
        return $check;
    }
    public static function capabilities(array $caps,string $cap,int $actor,array $args): array {
        $target=(int)($args[0]??0);
        if (!$target||!in_array($cap,['edit_user','promote_user','remove_user','delete_user'],true)||Identities::is_internal()) { return $caps; }
        $row=Identities::get($target); if (!$row) { return $caps; }
        if ($row['kind']==='customer') {
            if (in_array($cap,['promote_user','remove_user'],true)) { return ['do_not_allow']; }
            if (!is_super_admin($actor)&&((int)$row['store_id']!==get_current_blog_id()||($cap==='delete_user'))) { return ['do_not_allow']; }
        } elseif (!is_super_admin($actor)&&$actor!==$target) { return ['do_not_allow']; }
        return $caps;
    }
    public static function customer_caps(array $all,array $requested,array $args,\WP_User $user): array {
        if (Identities::is_internal()||is_super_admin($user->ID)) { return $all; }
        $row=Identities::get($user->ID); if (!$row||$row['kind']!=='customer') { return $all; }
        if (!Identities::accessible($user->ID)) { return array_fill_keys(array_keys($all),false); }
        $allowed=['exist'=>true]+(get_role('customer')->capabilities??['read'=>true]);
        foreach (['view_order','pay_for_order','order_again','download_file','download_order_item'] as $key) { if (isset($all[$key])) { $allowed[$key]=$all[$key]; } }
        foreach ($all as $key=>$value) { if (!isset($allowed[$key])) { $allowed[$key]=false; } }
        return $allowed;
    }
    public static function prepare_reset(): void {
        if (empty($_POST['user_login'])||(!isset($_POST['wc_reset_password'])&&($_REQUEST['action']??'')!=='lostpassword')) { return; }
        $user=self::resolve((string)wp_unslash($_POST['user_login']),self::employee_entry());
        $_POST['user_login']=$user&&self::can_reset($user->ID)?$user->user_login:'gsm_invalid_reset';
    }
    private static function can_reset(int $id): bool {
        $row=Identities::get($id);
        if (is_super_admin($id)||($row&&$row['kind']==='staff'&&get_current_blog_id()===Stores::main_id())) { return true; }
        return Identities::accessible($id);
    }
    public static function allow_reset(mixed $allow,int $id): mixed { return self::can_reset($id)?$allow:false; }
    public static function lostpassword(\WP_Error $errors,mixed $user): void {
        if (!$user instanceof \WP_User||!self::can_reset($user->ID)) { $errors->add('gsm_reset_scope','该账户不存在或不属于当前店铺。'); }
    }
    public static function validate_reset(\WP_Error $errors,mixed $user): void {
        if ($user instanceof \WP_User&&!self::can_reset($user->ID)) { $errors->add('gsm_reset_scope','密码重置链接不属于当前店铺。'); }
    }
    public static function guard_reset_request(): void {
        $id=0;
        if (!empty($_GET['key'])&&!empty($_GET['id'])) { $id=absint($_GET['id']); }
        if (!empty($_GET['key'])&&!empty($_GET['login'])) { $user=get_user_by('login',(string)wp_unslash($_GET['login'])); $id=$user?$user->ID:0; }
        if ($id&&!self::can_reset($id)) { wp_die('密码重置链接不属于当前店铺。','账户错误',['response'=>403]); }
    }
    public static function native_registration(): void {
        if (($_REQUEST['action']??'')==='register') { wp_safe_redirect(wc_get_page_permalink('myaccount')); exit; }
    }
    public static function reset_message(string $message,string $key,string $login,\WP_User $user): string {
        $row=Identities::get($user->ID); $staff=is_super_admin($user->ID)||($row['kind']??'')==='staff';
        $scope=$staff?'此员工账户密码用于已授权的各店铺。':'新密码只用于当前店铺的顾客账户，不影响其他店铺。';
        $url=add_query_arg(['action'=>'rp','key'=>$key,'login'=>$login],home_url('/wp-login.php'));
        return sprintf("%s\n\n有人请求重设您的账户密码。%s\n如非本人操作，请忽略此邮件。\n\n%s\n",wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES),$scope,$url);
    }
    public static function scope_notice(): void {
        echo '<p class="woocommerce-info">顾客账户、密码和默认地址仅用于当前店铺；其他店铺需另行注册。员工账户按授权跨店使用。</p>';
    }
    public static function translate(string $translation,string $text,string $domain): string {
        if ($domain==='trovesia-plugin'&&$text==='Already registered with one of our stores? Sign in with the same email and password. Your account and saved addresses are shared across our stores.') {
            return '顾客账户仅用于当前店铺。其他店铺需要重新注册；可使用相同用户名和邮箱，密码与地址独立。';
        }
        return $translation;
    }
    /** Replace canonical IDs in message text, never inside reset-link query parameters. */
    public static function mail_alias(array $mail): array {
        if (empty($mail['message'])||!str_contains($mail['message'],'gsm')) { return $mail; }
        $mail['message']=preg_replace_callback('/(?<![A-Za-z0-9%=&\/?._-])(?:gsmc_\d+_|gsms_)[a-f0-9]{32}(?![A-Za-z0-9%=&\/?._-])/',static function ($m): string {
            $user=get_user_by('login',$m[0]); return $user?esc_html(Identities::alias($user->ID)):$m[0];
        },$mail['message']);
        return $mail;
    }
}
