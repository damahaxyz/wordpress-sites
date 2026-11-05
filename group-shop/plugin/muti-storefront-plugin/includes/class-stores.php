<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
final class Stores {
    public static function boot(): void { add_action('update_option_blog_public',static fn()=>self::purge(),20); }
    public static function main_id(): int { return 1; }
    public static function can_manage(): bool { return Brands::root(); }
    public static function authorize(): void { if (!self::can_manage()) { throw new \RuntimeException('需要商城总管理员权限。'); } }
    public static function assert_store(int $id): void {
        if (is_multisite()) { $site=get_site($id); if ($site&&!$site->deleted&&!$site->archived&&!$site->spam) { return; } }
        elseif (isset(Brands::registry()[$id])||($id===1&&!Brands::registry())) { return; }
        throw new \RuntimeException('品牌不存在或已停用。');
    }
    public static function within(int $id,callable $callback): mixed {
        self::assert_store($id);
        if (is_multisite()) { $switch=get_current_blog_id()!==$id; if ($switch) { switch_to_blog($id); } try { return $callback(); } finally { if ($switch) { restore_current_blog(); } } }
        if (!Brands::registry()) { return $callback(); }
        return Brands::within($id,$callback);
    }
    public static function all(): array {
        if (is_multisite()) { $out=[]; foreach (get_sites(['number'=>100,'deleted'=>0,'archived'=>0,'spam'=>0]) as $s) { $out[(int)$s->blog_id]=(string)get_blog_option((int)$s->blog_id,'blogname'); } return $out; }
        $out=[]; foreach (Brands::registry() as $id=>$b) { $out[(int)$id]=$b['name']; } return $out?:[1=>(string)get_option('blogname')];
    }
    public static function settings(): array { return (array)get_option('gsm_store_settings',[]); }
    public static function logo_id(): int { return (int)get_option('gsm_logo_id',get_theme_mod('custom_logo',0)); }
    public static function domain(string $value,int $id=0): string {
        self::authorize(); if ($id) { self::assert_store($id); }
        $host=strtolower(trim($value)); $host=preg_replace('~^https?://~','',$host); $host=rtrim($host,'/');
        if (strlen($host)>253||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)||filter_var($host,FILTER_VALIDATE_IP)) { throw new \RuntimeException('请输入有效域名，不包含路径、端口或通配符。'); }
        foreach (Brands::registry() as $brand=>$row) { if ((int)$brand!==$id&&$row['domain']===$host) { throw new \RuntimeException('该域名已被其他品牌使用。'); } }
        return $host;
    }
    private static function locked(callable $fn): mixed { global $wpdb; if ((int)$wpdb->get_var("SELECT GET_LOCK('gsm_brand_registry',10)")!==1) { throw new \RuntimeException('品牌配置正在更新，请重试。'); } try { return $fn(); } finally { $wpdb->get_var("SELECT RELEASE_LOCK('gsm_brand_registry')"); } }
    public static function save(int $id,array $data): void { self::locked(static fn()=>self::save_locked($id,$data)); }
    private static function save_locked(int $id,array $data): void {
        self::authorize(); self::assert_store($id); $registry=Brands::registry();
        $name=sanitize_text_field($data['name']??$registry[$id]['name']); if ($name==='') { throw new \RuntimeException('品牌名称不能为空。'); }
        $domain=array_key_exists('domain',$data)?self::domain((string)$data['domain'],$id):$registry[$id]['domain'];
        $scheme=wp_parse_url($registry[$id]['url'],PHP_URL_SCHEME)==='http'&&wp_get_environment_type()==='local'?'http':'https';
        $old=Brands::raw_options($id); $settings=$old['gsm_store_settings']??[];
        $from=sanitize_email($data['from_email']??$settings['from_email']??''); $support=sanitize_email($data['support_email']??$settings['support_email']??'');
        if (!empty($data['mail_enabled'])&&(!is_email($from)||!is_email($support))) { throw new \RuntimeException('启用邮件前请设置有效的发件和客服邮箱。'); }
        $logo=absint($data['logo_id']??$old['gsm_logo_id']??0); if ($logo&&!wp_attachment_is_image($logo)) { throw new \RuntimeException('请选择媒体库中的图片。'); }
        $public=(string)($data['blog_public']??$old['blog_public']??'0'); if (!in_array($public,['0','1'],true)) { throw new \RuntimeException('收录设置无效。'); }
        $registry[$id]=array_merge($registry[$id],['name'=>$name,'domain'=>$domain,'url'=>$scheme.'://'.$domain]); update_option('gsm_brands',$registry,false);
        foreach (['blogname'=>$name,'blogdescription'=>sanitize_text_field($data['tagline']??$old['blogdescription']??''),'blog_public'=>$public,'gsm_logo_id'=>$logo,'gsm_store_settings'=>array_merge($settings,['uuid'=>$settings['uuid']??wp_generate_uuid4(),'from_email'=>$from,'support_email'=>$support,'from_name'=>sanitize_text_field($data['from_name']??$name),'mail_enabled'=>!empty($data['mail_enabled'])])] as $key=>$value) { Brands::set_option($id,$key,$value); }
        self::purge(); Schema::audit('brand_updated',$id);
    }
    public static function create(string $name,string $slug,string $domain=''): int { return self::locked(static fn()=>self::create_locked($name,$slug,$domain)); }
    private static function create_locked(string $name,string $slug,string $domain=''): int {
        self::authorize(); if (is_multisite()) { throw new \RuntimeException('请先完成单站迁移。'); }
        if (count(self::all())>=25) { throw new \RuntimeException('最多管理 25 个品牌。'); }
        $name=sanitize_text_field($name); if ($name===''||$domain==='') { throw new \RuntimeException('品牌名称和域名不能为空。'); }
        $domain=self::domain($domain); $all=Brands::registry(); $id=max(max(array_keys($all)),(int)get_option('gsm_brand_sequence',0))+1; update_option('gsm_brand_sequence',$id,false);
        $scheme=wp_get_environment_type()==='local'&&str_ends_with($domain,'.local')?'http':'https';
        $all[$id]=['name'=>$name,'domain'=>$domain,'url'=>$scheme.'://'.$domain,'uuid'=>wp_generate_uuid4()]; update_option('gsm_brands',$all,false);
        Brands::set_option($id,'gsm_store_settings',['uuid'=>$all[$id]['uuid'],'mail_enabled'=>false,'from_name'=>$name,'from_email'=>'','support_email'=>'']);
        Brands::set_option($id,'site_icon',0); Brands::set_option($id,'gsm_logo_id',0); Brands::set_option($id,'blogname',$name); Brands::set_option($id,'blogdescription',''); Brands::set_option($id,'blog_public','0'); Brands::set_option($id,'gsm_collections',[]);
        $source=Brands::raw_options(1); $page_map=[];
        foreach (array_keys($source) as $key) {
            if (!preg_match('/^woocommerce_.*_page_id$/',$key)&&!in_array($key,['page_on_front','page_for_posts','wp_page_for_privacy_policy'],true)) { continue; }
            $old=(int)$source[$key]; if (!$old) { continue; }
            if (!isset($page_map[$old])) { $post=get_post($old); if (!$post) { continue; } $page_map[$old]=wp_insert_post(['post_type'=>'page','post_status'=>$post->post_status,'post_title'=>$post->post_title,'post_content'=>$post->post_content,'post_name'=>'brand-'.$id.'-'.$post->post_name,'meta_input'=>['_gsm_brand_id'=>$id,'_gsm_public_slug'=>get_post_meta($old,'_gsm_public_slug',true)?:$post->post_name]]); }
            Brands::set_option($id,$key,$page_map[$old]);
        }
        foreach (['show_on_front','theme_mods_'.get_stylesheet()] as $key) { if (isset($source[$key])) { $value=$source[$key]; if(str_starts_with($key,'theme_mods_')&&is_array($value)){$value['nav_menu_locations']=[];} Brands::set_option($id,$key,$value); } }
        Brands::set_option($id,'nav_menu_options',['auto_add'=>[]]);
        Schema::audit('brand_created',$id); return $id;
    }
    public static function purge(): void { if (class_exists('Trovesia\\Plugin\\Page_Cache')) { \Trovesia\Plugin\Page_Cache::instance()->purge_now('muti-storefront-plugin update'); } }
}
