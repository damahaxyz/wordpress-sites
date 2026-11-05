<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Scope-aware identity index; passwords and real email remain native WordPress data. */
final class Identities {
    private static int $internal=0;
    private static ?int $scope=null;
    private static bool $staff_create=false;
    private static array $pending=[];
    private static array $locks=[];
    public static function boot(): void {
        add_filter('email_exists',[self::class,'email_exists'],20,2);
        add_filter('username_exists',[self::class,'username_exists'],20,2);
        add_filter('pre_user_login',[self::class,'new_login'],50);
        add_filter('wp_pre_insert_user_data',[self::class,'prepare_data'],50,4);
        add_action('user_register',[self::class,'registered'],1,2);
        add_action('profile_update',[self::class,'updated'],1,3);
        add_action('deleted_user',[self::class,'deleted']);
        add_action('shutdown',[self::class,'unlock'],PHP_INT_MAX);
    }
    public static function ready(): bool { return (bool)get_site_option('gsm_identity_ready',false); }
    public static function internal(callable $fn): mixed {
        ++self::$internal; try { return $fn(); } finally { --self::$internal; }
    }
    public static function is_internal(): bool { return self::$internal>0; }
    public static function scoped(int $store,callable $fn): mixed {
        $old=self::$scope; self::$scope=$store;
        try { return $fn(); } finally { self::$scope=$old; }
    }
    public static function key(string $value): string { return strtolower(trim($value)); }
    public static function get(int $id): ?array {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('identities').' WHERE user_id=%d',$id),ARRAY_A);
        return $row?:null;
    }
    public static function find(int $store,string $value,bool $email=false): ?array {
        global $wpdb;
        $field=$email?'email_key':'login_key';
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('identities')." WHERE network_id=%d AND store_id=%d AND $field=%s",Brands::network_id(),$store,self::key($value)),ARRAY_A);
        return $row?:null;
    }
    public static function put(int $id,string $kind,int $store,string $alias,string $email): void {
        global $wpdb;
        if (!in_array($kind,['customer','staff'],true) || ($kind==='customer'&&$store<1) || ($kind==='staff'&&$store!==0)) { throw new \RuntimeException('账户归属无效。'); }
        $result=$wpdb->insert(Schema::table('identities'),['user_id'=>$id,'network_id'=>Brands::network_id(),'store_id'=>$store,'kind'=>$kind,'login_name'=>$alias,'login_key'=>self::key($alias),'email_key'=>self::key($email)]);
        if ($result===false) { throw new \RuntimeException('当前店铺已有该用户名或邮箱，账户未建立。'); }
    }
    public static function accessible(int $id,?int $store=null): bool {
        if (!$id) { return false; }
        $store??=Brands::id();
        try { Stores::assert_store($store); } catch (\Throwable $e) { return false; }
        if (Brands::root($id)) { return true; }
        $row=self::get($id);
        if (!$row||(int)$row['network_id']!==Brands::network_id()) { return false; }
        if ($row['kind']==='customer') { return self::ready()&&(int)$row['store_id']===$store; }
        $grants=(array)get_user_meta($id,'_gsm_brand_roles',true);
        return in_array($grants[$store]??'',['administrator','shop_manager'],true);
    }
    public static function alias(int $id): string {
        $row=self::get($id); return $row?$row['login_name']:(get_userdata($id)->user_login??'');
    }
    /** Woo's list API hides disabled gateways and defaults to one page. Ownership must include all. */
    public static function local_tokens(int $user,int $page=1,int $limit=100): array {
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare("SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id=%d ORDER BY token_id LIMIT %d OFFSET %d",$user,$limit,(max(1,$page)-1)*$limit));
        return array_values(array_filter(array_map(static fn($id)=>\WC_Payment_Tokens::get((int)$id),$ids)));
    }
    public static function staff_creation(): bool {
        return self::$staff_create || (is_admin()&&($GLOBALS['pagenow']??'')==='user-new.php'&&Stores::can_manage());
    }
    public static function create_staff(array $data): int|\WP_Error {
        Stores::authorize(); self::$staff_create=true;
        try { return wp_insert_user($data+['role'=>'']); } finally { self::$staff_create=false; }
    }
    /** Context for native uniqueness checks, including editing a customer's network profile. */
    private static function scope(): int {
        if (self::$scope!==null) { return self::$scope; }
        $target=absint($_REQUEST['user_id']??$_POST['user_id']??0);
        if ($target && current_user_can('edit_user',$target)) {
            $row=self::get($target); if ($row) { return (int)$row['store_id']; }
        }
        if (self::staff_creation()) { return 0; }
        if (is_admin()&&in_array($GLOBALS['pagenow']??'',['user-new.php','site-users.php'],true)&&!empty($_REQUEST['role'])&&$_REQUEST['role']!=='customer') { return 0; }
        return Brands::id();
    }
    public static function email_exists(mixed $id,string $email): int|false {
        if (self::is_internal()) { return $id?(int)$id:false; }
        $row=self::find(self::scope(),$email,true);
        return $row?(int)$row['user_id']:false;
    }
    public static function username_exists(mixed $id,string $login): int|false {
        if (self::is_internal()||(str_starts_with($login,'gsmc_')||str_starts_with($login,'gsms_'))) { return $id?(int)$id:false; }
        $row=self::find(self::scope(),$login);
        return $row?(int)$row['user_id']:false;
    }
    private static function lock(int $store,string $alias,string $email): void {
        global $wpdb;
        $keys=['login:'.self::key($alias),'email:'.self::key($email)]; sort($keys);
        foreach ($keys as $key) {
            $lock='gsm_identity_'.md5(Brands::network_id().':'.$store.':'.$key);
            if (isset(self::$locks[$lock])) { continue; }
            if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1) { throw new \RuntimeException('账户正在保存，请稍后重试。'); }
            self::$locks[$lock]=true;
        }
    }
    public static function unlock(): void {
        global $wpdb;
        foreach (array_keys(self::$locks) as $key) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
        self::$locks=[];
    }
    public static function new_login(string $login): string {
        if (self::is_internal() || (str_starts_with($login,'gsmc_')||str_starts_with($login,'gsms_'))) { return $login; }
        // wp_update_user also runs pre_user_login, but usernames are immutable on updates.
        // Only wp_pre_insert_user_data binds this candidate if the operation is actually new.
        $internal=(self::staff_creation()?'gsms_':'gsmc_'.Brands::id().'_').str_replace('-','',wp_generate_uuid4());
        self::$pending[$internal]=['alias'=>$login,'store'=>Brands::id()];
        return $internal;
    }
    private static function rejected(string $message): array {
        if (wp_doing_ajax()) { wp_send_json_error(['message'=>$message],409); }
        // wp_insert_user turns an empty result into WP_Error instead of a partial insert.
        return [];
    }
    public static function prepare_data(array $data,bool $update,?int $id,array $raw): array {
        if (self::is_internal()) { return $data; }
        if ($update) {
            $row=self::get((int)$id);
            if (!$row) { if (!Brands::root((int)$id)) { throw new \RuntimeException('账户尚未完成身份迁移。'); } return $data; }
            if (!is_email($data['user_email'])||strlen($data['user_email'])>100) { throw new \RuntimeException('邮箱无效。'); }
            self::lock((int)$row['store_id'],$row['login_name'],$data['user_email']);
            $other=self::find((int)$row['store_id'],$data['user_email'],true);
            if ($other&&(int)$other['user_id']!==$id) { return self::rejected('当前店铺已有该邮箱。'); }
            return $data;
        }
        $staff=self::staff_creation();
        if (!$staff&&!self::ready()) { throw new \RuntimeException('顾客账户迁移尚未完成，请稍后注册。'); }
        $login=$data['user_login'];
        $pending=self::$pending[$login]??['alias'=>(string)($raw['user_login']??$login),'store'=>Brands::id()];
        $store=$staff?0:(int)$pending['store']; $alias=trim($pending['alias']);
        if ($alias===''||strlen($alias)>60||!validate_username($alias)||(str_starts_with(strtolower($alias),'gsmc_')||str_starts_with(strtolower($alias),'gsms_'))||is_email($alias)) { throw new \RuntimeException('用户名无效，请使用不含邮箱格式的用户名。'); }
        if (!is_email($data['user_email'])||strlen($data['user_email'])>100) { throw new \RuntimeException('邮箱无效。'); }
        if (!$staff&&!in_array($raw['role']??'customer',['customer','subscriber',''],true)) { throw new \RuntimeException('顾客只能使用 Customer 角色；请从网络用户入口创建员工。'); }
        if (!$staff) {
            foreach (array_keys((array)($raw['meta_input']??[])) as $key) { if (str_ends_with($key,'capabilities')||str_ends_with($key,'user_level')||$key==='primary_blog') { return self::rejected('注册资料不能包含权限字段。'); } }
            $data['user_activation_key']='';
        }
        self::lock($store,$alias,$data['user_email']);
        if (self::find($store,$alias)||self::find($store,$data['user_email'],true)) { return self::rejected('当前店铺已有该用户名或邮箱。'); }
        if (!(str_starts_with($login,'gsmc_')||str_starts_with($login,'gsms_'))) { $data['user_login']=($staff?'gsms_':'gsmc_'.$store.'_').str_replace('-','',wp_generate_uuid4()); }
        if (!$staff) { $data['user_nicename']=$data['user_login']; }
        if (empty($raw['display_name'])||$data['display_name']===$login) { $data['display_name']=$alias; }
        self::$pending[$data['user_login']]=['alias'=>$alias,'store'=>$store,'kind'=>$staff?'staff':'customer'];
        return $data;
    }
    public static function registered(int $id,array $raw=[]): void {
        if (self::is_internal()) { return; }
        $user=get_userdata($id); $pending=self::$pending[$user->user_login]??null;
        if (!$pending||!isset($pending['kind'])) { throw new \RuntimeException('账户身份初始化失败。'); }
        try { self::put($id,$pending['kind'],$pending['store'],$pending['alias'],$user->user_email); }
        catch (\Throwable $error) {
            self::internal(static function () use ($id): void {
                require_once ABSPATH.'wp-admin/includes/user.php';
                if (is_multisite()) { require_once ABSPATH.'wp-admin/includes/ms.php'; wpmu_delete_user($id); }
                else { wp_delete_user($id); }
            });
            self::unlock();
            if (wp_doing_ajax()) { wp_send_json_error(['message'=>'账户建立失败，请重试。'],409); }
            wp_die('账户建立失败，请重试。','账户错误',['response'=>409]);
        }
        unset(self::$pending[$user->user_login]);
        if ($pending['kind']==='customer') {
            self::internal(static function () use ($id,$pending): void {
                $user=new \WP_User($id); $user->set_role('customer');
                update_user_meta($id,'primary_blog',$pending['store']);
            });
        }
        if ($pending['kind']==='staff') { self::internal(static function () use ($id): void { (new \WP_User($id))->set_role('subscriber'); update_user_meta($id,'_gsm_brand_roles',[]); }); }
        if (empty($raw['nickname'])) { update_user_meta($id,'nickname',$pending['alias']); }
        Customers::record($id);
        self::unlock();
    }
    public static function updated(int $id,mixed $old,array $raw=[]): void {
        if (self::is_internal()) { return; }
        $row=self::get($id); if (!$row) { return; }
        global $wpdb;
        $result=$wpdb->update(Schema::table('identities'),['email_key'=>self::key(get_userdata($id)->user_email)],['user_id'=>$id]);
        if ($result===false) { throw new \RuntimeException('账户邮箱索引更新失败，请联系管理员。'); }
        self::unlock();
    }
    public static function deleted(int $id): void { global $wpdb; $wpdb->delete(Schema::table('identities'),['user_id'=>$id]); }
}
