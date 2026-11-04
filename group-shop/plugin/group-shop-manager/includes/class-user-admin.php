<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Native user lists/editors, with immutable identity kind and explicit employee grants. */
final class UserAdmin {
    private static array $grants=[];
    public static function boot(): void {
        add_filter('wpmu_users_columns',[self::class,'columns']);
        add_filter('manage_users-network_custom_column',[self::class,'column'],10,3);
        add_filter('manage_users_columns',static fn($cols)=>$cols+['gsm_identity'=>'账户类型／范围','gsm_alias'=>'店铺用户名']);
        add_filter('manage_users_custom_column',[self::class,'column'],10,3);
        add_action('edit_user_profile',[self::class,'profile']);
        add_action('show_user_profile',[self::class,'profile']);
        add_filter('editable_roles',[self::class,'roles']);
        add_action('user_profile_update_errors',[self::class,'validate'],20,3);
        add_action('profile_update',[self::class,'save_grants'],30);
        add_action('admin_init',static function (): void {
            if (!empty($_GET['link_orders'])&&!Customers::local_customer(absint($_GET['link_orders']))) { wp_die('只能关联本店顾客的历史订单。','账户权限',['response'=>403]); }
            if (!in_array($GLOBALS['pagenow']??'',['user-edit.php','profile.php'],true)) { return; }
            $id=absint($_REQUEST['user_id']??$_POST['user_id']??get_current_user_id()); $row=Identities::get($id);
            if (!Customers::local_customer($id)) { unset($_POST['wc_email_verified'],$_POST['wc_email_verified_nonce']); }
            if (!$row||$row['kind']!=='customer') { return; }
            if (!is_super_admin()&&(int)$row['store_id']!==get_current_blog_id()) { wp_die('此顾客不属于当前店铺。','账户权限',['response'=>403]); }
            if (!empty($_POST['super_admin'])||(array_key_exists('role',$_POST)&&$_POST['role']!=='customer')) { wp_die('顾客角色固定为 Customer，不能转换为员工。','账户权限',['response'=>403]); }
        },1);
        add_action('admin_notices',[self::class,'notice']);
        add_action('network_admin_notices',[self::class,'notice']);
        add_filter('user_search_columns',static function ($columns): array { return array_values(array_unique(array_merge($columns,['display_name']))); });
    }
    private static function allowed(): bool { return is_multisite()&&Stores::can_manage()&&current_user_can('manage_network_users'); }
    public static function columns(array $columns): array {
        if (self::allowed()) { $columns+=['gsm_identity'=>'账户类型／范围','gsm_alias'=>'店铺用户名','gsm_first_store'=>'首次来源店铺']; }
        return $columns;
    }
    private static function store_label(int $store): string {
        try { return Stores::within($store,static fn()=>get_bloginfo('name').' (#'.get_current_blog_id().')'); }
        catch (\Throwable $error) { return '店铺 #'.$store.'（不可用）'; }
    }
    public static function column(string $output,string $column,int $id): string {
        if (!in_array($column,['gsm_identity','gsm_alias','gsm_first_store'],true)) { return $output; }
        if (!current_user_can('list_users')&&!self::allowed()) { return $output; }
        $row=Identities::get($id);
        if ($column==='gsm_identity') { return esc_html($row?($row['kind']==='staff'?'员工／按店授权':'顾客／'.self::store_label((int)$row['store_id'])):'待迁移'); }
        if ($column==='gsm_alias') { return esc_html($row?$row['login_name']:'—'); }
        if (!self::allowed()) { return $output; }
        $store=(int)get_user_meta($id,'_gsm_first_store',true);
        return esc_html($store?self::store_label($store):'历史用户／未记录');
    }
    public static function roles(array $roles): array {
        $id=absint($_REQUEST['user_id']??0); $row=$id?Identities::get($id):null;
        if ($row&&$row['kind']==='customer') { return array_intersect_key($roles,['customer'=>true]); }
        return $roles;
    }
    public static function profile(\WP_User $user): void {
        $row=Identities::get($user->ID);
        echo '<h2>多店账户身份</h2><table class="form-table"><tr><th>账户类型／范围</th><td>'.esc_html($row?($row['kind']==='staff'?'共享员工，按店铺授权':'本店顾客：'.self::store_label((int)$row['store_id'])):'尚未完成身份迁移').'</td></tr>';
        if ($row) { echo '<tr><th>登录用户名</th><td>'.esc_html($row['login_name']).'<p class="description">原生 Username 是系统唯一标识；登录和展示使用此用户名。账户类型不可在普通角色控件中转换。</p></td></tr>'; }
        echo '</table>';
        if (!Customers::local_customer($user->ID)) { echo '<p>邮箱确认及历史游客订单关联请在顾客所属店后台执行；员工不关联顾客订单。</p><script>document.addEventListener("DOMContentLoaded",()=>{const r=document.getElementById("wc_email_verified");if(r){r.disabled=true;}});</script>'; }
        if ($row&&$row['kind']==='customer') {
            echo '<p>此顾客角色固定为 Customer，不能改成员工或加入其他店铺。需要员工权限时请创建独立员工账户。</p><script>document.addEventListener("DOMContentLoaded",()=>{for(const id of ["role","super_admin"]){const r=document.getElementById(id);if(r){r.disabled=true;}}});</script>';
        }
        if (!$row||$row['kind']!=='staff'||!self::allowed()||is_super_admin($user->ID)) { return; }
        echo '<h3>员工店铺授权</h3><p>只有网络超级管理员可设置。未授权店铺无法登录，不自动授权新店；不会授予网络超级管理员权限。</p><table class="form-table">';
        foreach (Stores::all() as $store=>$name) {
            $roles=(new \WP_User($user->ID,'',(int)$store))->roles;
            $selected=in_array('administrator',$roles,true)?'administrator':(in_array('shop_manager',$roles,true)?'shop_manager':'');
            echo '<tr><th>'.esc_html($name.' (#'.$store.')').'</th><td><select name="gsm_staff_roles['.(int)$store.']">';
            foreach ([''=>'不授权','shop_manager'=>'Shop Manager','administrator'=>'Administrator'] as $value=>$label) { echo '<option value="'.esc_attr($value).'" '.selected($selected,$value,false).'>'.esc_html($label).'</option>'; }
            echo '</select></td></tr>';
        }
        echo '</table>'; wp_nonce_field('gsm_staff_grants_'.$user->ID,'gsm_staff_nonce');
        echo '<input type="hidden" name="gsm_staff_complete" value="1">';
    }
    public static function validate(\WP_Error $errors,bool $update,object $user): void {
        if (!$update) { return; }
        $id=(int)$user->ID; $row=Identities::get($id);
        if ($row&&$row['kind']==='customer'&&array_key_exists('role',$_POST)&&$_POST['role']!=='customer') { $errors->add('gsm_role_locked','顾客角色固定为 Customer，不能转换为员工。'); }
        if ($row&&isset($user->user_email)) {
            $other=Identities::find((int)$row['store_id'],(string)$user->user_email,true);
            if ($other&&(int)$other['user_id']!==$id) { $errors->add('gsm_email_exists','当前店铺已有此邮箱。'); }
        }
        if (isset($_POST['gsm_staff_roles'])||isset($_POST['gsm_staff_complete'])) {
            if (!$row||$row['kind']!=='staff'||!self::allowed()||is_super_admin($id)||($_POST['gsm_staff_complete']??'')!=='1'||!wp_verify_nonce((string)($_POST['gsm_staff_nonce']??''),'gsm_staff_grants_'.$id)) { $errors->add('gsm_staff_grants','员工授权无效或权限不足。'); return; }
            $values=wp_unslash($_POST['gsm_staff_roles']??[]); $stores=Stores::all();
            if (!is_array($values)||count($values)!==count($stores)||array_diff(array_keys($values),array_keys($stores))) { $errors->add('gsm_staff_grants','授权表单不完整，请刷新后重试。'); return; }
            foreach ($values as $store=>$role) { if (!is_string($role)||!in_array($role,['','administrator','shop_manager'],true)) { $errors->add('gsm_staff_grants','员工角色无效。'); return; } }
            self::$grants[$id]=$values;
        }
    }
    public static function save_grants(int $id): void {
        if (!isset(self::$grants[$id])) { return; }
        self::grant($id,self::$grants[$id]); unset(self::$grants[$id]);
    }
    public static function grant(int $id,array $grants): void {
        Stores::authorize(); $row=Identities::get($id);
        if (!$row||$row['kind']!=='staff'||is_super_admin($id)) { throw new \RuntimeException('只能授权独立员工账户。'); }
        foreach ($grants as $store=>$role) { Stores::assert_store((int)$store); if (!in_array($role,['','administrator','shop_manager'],true)) { throw new \RuntimeException('员工角色无效。'); } }
        foreach ($grants as $store=>$role) {
            if ($role==='') { remove_user_from_blog($id,(int)$store); }
            else { $result=add_user_to_blog((int)$store,$id,$role); if (is_wp_error($result)) { throw new \RuntimeException('店铺授权失败。'); } }
        }
        \WP_Session_Tokens::get_instance($id)->destroy_all();
        Schema::audit('staff_store_grants_updated',Stores::main_id(),$id,['fields'=>array_keys($grants)]);
    }
    public static function notice(): void {
        if (!Stores::can_manage()) { return; }
        if (!Identities::ready()) { echo '<div class="notice notice-error"><p>顾客账户隔离迁移尚未完成，注册及顾客登录暂不可用。请先按 OPERATIONS 执行已授权的备份与迁移；不会在访问后台时自动拆分账户。</p></div>'; }
        if (is_network_admin()&&($GLOBALS['pagenow']??'')==='user-new.php') { echo '<div class="notice notice-info"><p>此原生入口创建独立共享员工账户。建立后编辑用户，在“员工店铺授权”选择店铺和角色。顾客请在所属店前台注册；不能由顾客角色转换为员工。</p></div>'; }
    }
}
