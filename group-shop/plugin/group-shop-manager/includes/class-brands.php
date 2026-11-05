<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** One WordPress installation; brand scope never switches database tables. */
final class Brands {
    private static int $current=1;
    private static int $depth=0;
    private static int $unscoped=0;
    private static bool $writing=false;
    private static bool $all_admin=false;
    private static ?int $api_brand=null;
    public static function active(): bool { return !is_multisite()&&(bool)get_option('gsm_single_brand_ready'); }
    public static function network_id(): int { return 1; }
    public static function id(): int { return is_multisite()?get_current_blog_id():self::$current; }
    public static function registry(): array { return (array)get_option('gsm_brands',[]); }
    public static function raw_options(int $id): array { return (array)get_option('gsm_brand_options_'.$id,[]); }
    public static function root(?int $id=null): bool {
        $id??=get_current_user_id();
        if (is_multisite()) { return is_super_admin($id); }
        // Do not call is_super_admin/has_cap inside user_has_cap: that recurses in single-site WP.
        return $id>0&&in_array($id,array_map('intval',(array)get_option('gsm_platform_admins',[])),true);
    }
    public static function within(int $id,callable $fn): mixed {
        if (!isset(self::registry()[$id])) { throw new \RuntimeException('品牌不存在。'); }
        $old=self::$current; self::$current=$id; ++self::$depth;
        try { return $fn(); } finally { self::$current=$old; --self::$depth; }
    }
    public static function enter(int $id): int { Stores::assert_store($id); $old=self::$current; self::$current=$id; ++self::$depth; return $old; }
    public static function leave(int $old): void { self::$current=$old; self::$depth=max(0,self::$depth-1); }
    public static function unscoped(callable $fn): mixed { ++self::$unscoped; try { return $fn(); } finally { --self::$unscoped; } }
    public static function unrestricted(): bool { return self::$unscoped>0||Identities::is_internal(); }
    public static function query_scope(): ?int {
        if (self::unrestricted()) { return null; }
        return self::$depth===0&&self::root()&&((is_admin()&&self::$all_admin)||self::$api_brand===0)?null:self::id();
    }
    public static function url(int $id): string { $b=self::registry()[$id]??null; return $b?(string)$b['url']:''; }
    public static function admin_url(int $id,string $path=''): string {
        $base=self::url(1).'/wp-admin/'.ltrim($path,'/');
        return add_query_arg('gsm_brand',$id,$base);
    }
    public static function boot(): void {
        if (!self::active()) { return; }
        $registry=self::registry(); $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
        $host=preg_replace('/:\d+$/','',$host); $found=false;
        foreach ($registry as $id=>$brand) { if ($brand['domain']===$host) { self::$current=(int)$id; $found=true; break; } }
        if (!$found&&!defined('WP_CLI')) { wp_die('未知品牌域名。','品牌不存在',['response'=>404]); }
        $admin=is_admin();
        // Native draft previews stay on the already authenticated admin host; this
        // grants no session on another domain and is never a customer brand selector.
        $preview=absint($_GET['page_id']??0);
        if(!$admin&&$preview&&!empty($_GET['preview'])&&get_post_type($preview)==='page'){
            $owner=(int)get_post_meta($preview,'_gsm_brand_id',true);$user=get_current_user_id();$grants=(array)get_user_meta($user,'_gsm_brand_roles',true);
            $allowed=self::root()||((Identities::get($user)['kind']??'')==='staff'&&($grants[$owner]??'')==='administrator');
            if($owner&&isset($registry[$owner])&&$allowed&&wp_verify_nonce((string)($_GET['gsm_preview_nonce']??''),'gsm_page_preview_'.$preview)){self::$current=$owner;}
        }
        if ($admin) {
            $products=basename((string)($_SERVER['SCRIPT_NAME']??''))==='edit.php'&&($_REQUEST['post_type']??'')==='product'&&empty($_REQUEST['page']);
            $categories=basename((string)($_SERVER['SCRIPT_NAME']??''))==='edit-tags.php'&&($_REQUEST['taxonomy']??'')==='product_cat';
            $pages=in_array(basename((string)($_SERVER['SCRIPT_NAME']??'')),['edit.php','post-new.php'],true)&&($_REQUEST['post_type']??'')==='page'&&empty($_REQUEST['page']);
            $menus=basename((string)($_SERVER['SCRIPT_NAME']??''))==='nav-menus.php';
            $selected=isset($_REQUEST['gsm_brand'])?absint($_REQUEST['gsm_brand']):(($products||$categories)&&self::root()?0:(($pages||$menus)&&self::root()?1:absint($_COOKIE['gsm_brand_admin']??0)));
            if($pages&&$selected===0&&self::root()){$selected=1;}
            if($menus&&$selected===0&&self::root()){$selected=1;}
            $page_id=absint($_REQUEST['post']??$_REQUEST['post_ID']??0);
            if($page_id&&get_post_type($page_id)==='page'){$selected=(int)get_post_meta($page_id,'_gsm_brand_id',true);}
            self::$all_admin=$selected===0;
            if ($selected&&isset($registry[$selected])) { self::$current=$selected; }
        }
        if (isset($_SERVER['HTTP_X_GSM_BRAND'])) { self::$api_brand=absint($_SERVER['HTTP_X_GSM_BRAND']); if (self::$api_brand&&!isset($registry[self::$api_brand])) { wp_die('品牌不存在。','品牌错误',['response'=>404]); } self::$current=self::$api_brand?:1; }
        add_filter('pre_option',[self::class,'option'],5,3);
        add_filter('rest_url',static function($url){
            if(!is_admin()||(!self::root()&&(Identities::get(get_current_user_id())['kind']??'')!=='staff')){return $url;}
            // Gutenberg must call the host holding its login cookie, even when
            // home() points at a different storefront. Signed headers carry scope.
            $parts=wp_parse_url($url);return rtrim((string)get_option('siteurl'),'/').($parts['path']??'/wp-json/').(isset($parts['query'])?'?'.$parts['query']:'');
        },20);
        add_filter('pre_update_option',[self::class,'update_option'],5,3);
        add_filter('theme_mod_custom_logo',static fn($logo)=>(int)get_option('gsm_logo_id',$logo),50);
        add_filter('woocommerce_cookie',static fn($name)=>$name.'_brand_'.self::id());
        add_action('init',static function (): void {
            if (self::$api_brand!==null) { $uid=get_current_user_id(); if (!$uid||(!self::root()&&((Identities::get($uid)['kind']??'')!=='staff'||self::$api_brand===0||!Identities::accessible($uid)))||!wp_verify_nonce((string)($_SERVER['HTTP_X_GSM_BRAND_NONCE']??''),'gsm_brand_api_'.self::$api_brand)) { wp_die('品牌 API 授权无效。','权限不足',['response'=>403]); } }
            if (!is_admin()||!is_user_logged_in()) { return; }
            if (!self::root()&&!Identities::accessible(get_current_user_id(),self::id())) { wp_die('没有该品牌的管理权限。','权限不足',['response'=>403]); }
            if (isset($_REQUEST['gsm_brand'])&&!headers_sent()) { setcookie('gsm_brand_admin',(string)absint($_REQUEST['gsm_brand']),['expires'=>0,'path'=>'/wp-admin','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']); }
        },20);
        add_action('admin_bar_menu',static function ($bar): void {
            if (!is_user_logged_in()||(!self::root()&&(Identities::get(get_current_user_id())['kind']??'')!=='staff')) { return; }
            $products=basename((string)($_SERVER['SCRIPT_NAME']??''))==='edit.php'&&($_REQUEST['post_type']??'')==='product'&&empty($_REQUEST['page']);
            $bar->add_node(['id'=>'gsm-brand','title'=>($products&&self::$all_admin&&self::root()?'商品：公共商品':'品牌：'.(self::$all_admin&&self::root()?'全部':(self::registry()[self::id()]['name']??''))),'href'=>self::admin_url(self::id(),'edit.php?post_type=product')]);
            if (self::root()) { $bar->add_node(['id'=>'gsm-brand-all','parent'=>'gsm-brand','title'=>'公共商品','href'=>self::admin_url(0,'edit.php?post_type=product')]); }
            foreach (Stores::all() as $id=>$name) { if (self::root()||Identities::accessible(get_current_user_id(),(int)$id)) { $bar->add_node(['id'=>'gsm-brand-'.$id,'parent'=>'gsm-brand','title'=>$name,'href'=>self::root()?self::admin_url((int)$id,'edit.php?post_type=product'):self::url((int)$id).'/wp-admin/edit.php?post_type=product']); } }
        },90);
        add_action('admin_enqueue_scripts',static function (): void {
            if (!self::root()&&(Identities::get(get_current_user_id())['kind']??'')!=='staff') { return; }
            $brand=self::$all_admin&&self::root()?0:self::id(); $config=['brand'=>$brand,'nonce'=>wp_create_nonce('gsm_brand_api_'.$brand)];
            wp_enqueue_script('wp-api-fetch');
            wp_add_inline_script('wp-api-fetch','(()=>{const c='.wp_json_encode($config).';wp.apiFetch.use((o,next)=>{o.headers={...(o.headers||{}),"X-GSM-Brand":String(c.brand),"X-GSM-Brand-Nonce":c.nonce};return next(o);});if(window.jQuery){jQuery.ajaxPrefilter((o,original,xhr)=>{if((o.url||"").includes("/wp-json/")){xhr.setRequestHeader("X-GSM-Brand",String(c.brand));xhr.setRequestHeader("X-GSM-Brand-Nonce",c.nonce);}});}})();');
        });
        add_action('admin_footer',static function (): void {
            echo '<script>document.querySelectorAll("form").forEach(f=>{if(!f.querySelector("[name=gsm_brand]")){const i=document.createElement("input");i.type="hidden";i.name="gsm_brand";i.value='.wp_json_encode(self::$all_admin&&self::root()?'0':(string)self::id()).';f.appendChild(i);}});</script>';
        });
    }
    private static function scoped_option(string $key): bool {
        return str_starts_with($key,'gsm_collections')||in_array($key,['home','siteurl','blogname','blogdescription','blog_public','gsm_store_settings','gsm_logo_id','gsm_collections','gsm_collections_review','show_on_front','page_on_front','page_for_posts','wp_page_for_privacy_policy','site_icon','sidebars_widgets','nav_menu_options'],true)||str_starts_with($key,'theme_mods_')||str_starts_with($key,'trovesia_')||(bool)preg_match('/^woocommerce_.*_page_id$/',$key);
    }
    public static function option(mixed $pre,string $key,mixed $default=false): mixed {
        if (self::$writing||!self::scoped_option($key)) { return $pre; }
        if ($key==='home') { return self::url(self::id()); }
        if ($key==='siteurl') {
            $host=preg_replace('/:\d+$/','',strtolower((string)($_SERVER['HTTP_HOST']??'')));
            foreach (self::registry() as $brand) { if ($brand['domain']===$host) { return $brand['url']; } }
            return self::url(1);
        }
        $options=self::raw_options(self::id());
        return array_key_exists($key,$options)?$options[$key]:$pre;
    }
    public static function set_option(int $id,string $key,mixed $value): void {
        $options=self::raw_options($id); $options[$key]=$value;
        self::$writing=true; try { update_option('gsm_brand_options_'.$id,$options,false); } finally { self::$writing=false; }
    }
    public static function update_option(mixed $value,string $key,mixed $old): mixed {
        if (!self::$writing&&self::scoped_option($key)) { self::set_option(self::id(),$key,$value); return $old; }
        return $value;
    }
    public static function name(int $id): string { return (string)(self::registry()[$id]['name']??'品牌 #'.$id); }
}
