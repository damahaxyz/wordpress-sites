<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
final class Admin {
    private const TABS = ['overview'=>'概览', 'stores'=>'店铺设置', 'collections'=>'商品集合', 'products'=>'店铺商品', 'audit'=>'操作日志', 'help'=>'文档与限制'];
    public static function boot(): void {
        add_action('admin_menu', static function (): void {
            if (get_current_blog_id() !== Stores::main_id() || !Stores::can_manage()) { return; }
            add_menu_page('Group Shop Manager', '多店铺管理', is_multisite() ? 'manage_network' : 'manage_options', 'group-shop-manager', [self::class,'render'], 'dashicons-store', 55);
        });
        add_action('admin_post_gsm_action', [self::class, 'handle']);
        add_filter('admin_body_class', static fn($classes)=>$classes.(self::dialog()?' gsm-store-dialog-page':''));
        add_action('admin_enqueue_scripts', static function (): void {
            if (($_GET['page'] ?? '') !== 'group-shop-manager') { return; }
            wp_enqueue_style('gsm-admin', plugins_url('assets/admin.css', GSM_FILE), [], GSM_VERSION.'.'.filemtime(GSM_DIR.'assets/admin.css'));
            $dependencies=[]; $media_config=null;
            if (self::dialog()==='edit' || (self::tab()==='products' && !empty($_GET['product'])) || (self::tab()==='collections' && isset($_GET['collection']))) {
                if (!Stores::can_manage()) { return; }
                $store=absint($_GET['store']??Stores::main_id());
                try { Stores::assert_store($store); } catch (\Throwable $error) { return; }
                wp_enqueue_media();
                if (in_array(self::tab(),['products','collections'],true)) { wp_enqueue_editor(); }
                $dependencies=['media-editor','media-views'];
                $media_config=['store'=>$store,'nonce'=>wp_create_nonce('gsm_media_'.$store)];
            }
            wp_enqueue_script('gsm-admin', plugins_url('assets/admin.js', GSM_FILE), $dependencies, GSM_VERSION.'.'.filemtime(GSM_DIR.'assets/admin.js'), true);
            if ($media_config) { wp_localize_script('gsm-admin','gsmMedia',$media_config); }
            if (self::tab()==='collections' && isset($_GET['collection']) && $media_config) {
                wp_localize_script('gsm-admin','gsmCollectionSearch',['url'=>get_admin_url(Stores::main_id(),'admin-ajax.php'),'store'=>$media_config['store'],'nonce'=>wp_create_nonce('gsm_collection_search_'.$media_config['store'])]);
            }
        });
    }
    public static function url(string $tab = 'overview', array $args = []): string {
        return add_query_arg(array_merge(['page'=>'group-shop-manager','tab'=>$tab], $args), get_admin_url(Stores::main_id(), 'admin.php'));
    }
    public static function form(string $kind, int $store, string $tab): void {
        echo '<form method="post" action="'.esc_url(get_admin_url(Stores::main_id(),'admin-post.php')).'">';
        wp_nonce_field('gsm_action');
        foreach (['action'=>'gsm_action','kind'=>$kind,'store'=>$store,'return_tab'=>$tab] as $key=>$value) { echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">'; }
    }
    public static function input(string $label, string $name, mixed $value = '', string $type = 'text', string $extra = ''): void {
        echo '<label class="gsm-field"><span>'.esc_html($label).'</span><input class="regular-text" type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" '.$extra.'></label>';
    }
    public static function textarea(string $label, string $name, string $value): void {
        echo '<label class="gsm-field"><span>'.esc_html($label).'</span><textarea class="large-text" name="'.esc_attr($name).'" rows="5">'.esc_textarea($value).'</textarea></label>';
    }
    private static function select_store(int $selected): void {
        echo '<form method="get" class="gsm-toolbar"><input type="hidden" name="page" value="group-shop-manager"><input type="hidden" name="tab" value="'.esc_attr(self::tab()).'"><label>选择店铺 <select name="store">';
        foreach (Stores::all() as $id=>$name) { echo '<option value="'.(int)$id.'" '.selected($selected,$id,false).'>'.esc_html($name).' (#'.(int)$id.')</option>'; }
        echo '</select></label> '; submit_button('查看','secondary','',false); echo '</form>';
    }
    private static function tab(): string { $tab=sanitize_key($_GET['tab']??'overview'); return isset(self::TABS[$tab])?$tab:'overview'; }
    private static function store(): int { return absint($_GET['store']??Stores::main_id()); }
    private static function dialog(): string {
        $mode=sanitize_key($_GET['gsm_dialog']??'');
        return ($_GET['page']??'')==='group-shop-manager'&&self::tab()==='stores'&&in_array($mode,['edit','create'],true)?$mode:'';
    }
    public static function handle(): void {
        $authorized=false; $store_saved=false;
        try {
            Stores::authorize(); check_admin_referer('gsm_action');
            $authorized=true;
            $data=wp_unslash($_POST); $store=absint($data['store']??0); $kind=sanitize_key($data['kind']??''); $message='操作已保存。';
            switch ($kind) {
                case 'store_create':
                    $domain=Stores::domain((string)($data['domain']??''));
                    $store=Stores::create((string)($data['name']??''),'store-'.substr(wp_generate_uuid4(),0,8),$domain);
                    $message='店铺已建立；尚未开放搜索收录和客户邮件。点击编辑可继续配置品牌及主题。'; break;
                case 'store_save': Stores::save($store,$data); break;
                case 'edition_save':
                    if (($data['gsm_product_complete']??'')!=='1') { throw new \RuntimeException('表单提交不完整，请重新载入后保存，商品未修改。'); }
                    Catalog::save_edition($store,absint($data['product']??0),$data); break;
                case 'edition_status':
                    Catalog::set_status($store,absint($data['product']??0),(string)($data['status']??''),(string)($data['expected_status']??''));
                    $message=($data['status']??'')==='publish'?'已上架，仅影响当前店铺。':'已禁用（设为草稿），当前店铺前台不再展示。'; break;
                case 'collection_save':
                    if (($data['gsm_collection_complete']??'')!=='1') { throw new \RuntimeException('集合表单提交不完整，请重新载入后保存。'); }
                    $saved_collection=Collections::save_collection($store,absint($data['collection']??0),$data);
                    delete_site_transient('gsm_collection_retry_'.get_current_user_id());
                    $message='集合已保存，可用于页面商品模块和 Shop 筛选。'; break;
                case 'collection_trash': Collections::trash($store,absint($data['collection']??0)); $message='集合已移入回收站，商品保留；可从回收站恢复。'; break;
                case 'collection_restore': Collections::trash($store,absint($data['collection']??0),true); $message='集合已恢复，请检查启用状态与选品条件。'; break;
                case 'collections_rebuild': Collections::rebuild($store); break;
                default: throw new \RuntimeException('未知管理操作。');
            }
            if (in_array($kind,['store_create','store_save'],true)) { delete_site_transient('gsm_store_retry_'.get_current_user_id()); }
            if ($kind==='edition_save') { delete_site_transient('gsm_product_retry_'.get_current_user_id()); }
            $store_saved=in_array($kind,['store_create','store_save'],true);
            set_site_transient('gsm_notice_'.get_current_user_id(),['ok'=>true,'message'=>$message,'store'=>$store,'store_saved'=>in_array($kind,['store_save','store_create'],true)],120);
        } catch (\Throwable $error) {
            set_site_transient('gsm_notice_'.get_current_user_id(),['ok'=>false,'message'=>$error->getMessage()],120);
            if ($authorized && ($kind??'')==='edition_save' && ($data['gsm_product_complete']??'')==='1') {
                $fields=array_intersect_key($data,array_flip(['name','description','short_description','regular_price','sale_price','menu_order','status','image_ids','variations']));
                if (strlen(wp_json_encode($fields))<=200000) { set_site_transient('gsm_product_retry_'.get_current_user_id(),['store'=>$store,'product'=>absint($data['product']??0),'fields'=>$fields],120); }
            }
            if ($authorized && ($kind??'')==='collection_save' && ($data['gsm_collection_complete']??'')==='1') {
                $fields=array_intersect_key($data,array_flip(['name','description','image','order','mode','categories','tags','tag_match','include','exclude','priority','sort','enabled','navigation','shop_filter']));
                if (strlen(wp_json_encode($fields))<=200000) { set_site_transient('gsm_collection_retry_'.get_current_user_id(),['store'=>$store,'collection'=>absint($data['collection']??0),'fields'=>$fields],120); }
            }
            if ($authorized && in_array($kind??'',['store_save','store_create'],true)) {
                $fields=array_intersect_key($data,array_flip(['name','domain','tagline','from_name','from_email','support_email','logo_id','blog_public','theme','mail_enabled']));
                $fields=array_map(static fn($value)=>is_scalar($value)?sanitize_text_field((string)$value):'', $fields);
                $fields['mail_enabled']=!empty($data['mail_enabled']);
                set_site_transient('gsm_store_retry_'.get_current_user_id(),['store'=>$store,'mode'=>$kind==='store_save'?'edit':'create','fields'=>$fields],120);
            }
        }
        $tab=sanitize_key($_POST['return_tab']??'overview');
        $args=['store'=>$store??absint($_POST['store']??Stores::main_id())];
        if ($tab==='collections') {
            if (($_POST['kind']??'')==='collection_save') { $args['collection']=$saved_collection??(absint($_POST['collection']??0)?:'new'); }
            if (($_POST['kind']??'')==='collection_trash') { $args['view']='trash'; }
        }
        if ($tab==='products') {
            $args['paged']=max(1,absint($_POST['paged']??1));
            if (($_POST['kind']??'')==='edition_save') { $args['product']=absint($_POST['product']??0); $args['variation_page']=max(1,absint($_POST['variation_page']??1)); }
        }
        if ($tab==='stores'&&in_array($_POST['gsm_dialog']??'',['edit','create'],true)) {
            $args['gsm_dialog']=$_POST['gsm_dialog'];
            if ($store_saved) { $args['gsm_saved']=wp_create_nonce('gsm_store_saved_'.$store); }
        }
        wp_safe_redirect(self::url(isset(self::TABS[$tab])?$tab:'overview',$args)); exit;
    }
    public static function render(): void {
        if (!Stores::can_manage()) { wp_die('需要网络管理员权限。',403); }
        if (self::dialog()) {
            echo '<div class="wrap gsm gsm-store-editor">';
            $saved=is_string($_GET['gsm_saved']??null)&&wp_verify_nonce($_GET['gsm_saved'],'gsm_store_saved_'.self::store());
            $notice=get_site_transient('gsm_notice_'.get_current_user_id());
            if ($notice) {
                if (!$saved) { delete_site_transient('gsm_notice_'.get_current_user_id()); }
                echo '<div class="notice notice-'.($notice['ok']?'success':'error').' inline"><p>'.esc_html($notice['message']).'</p></div>';
            }
            if ($saved) { echo '<p data-gsm-store-saved="1">店铺已保存，正在刷新列表。</p>'; }
            else {
                try { self::store_editor(self::store(),self::dialog()==='create'); }
                catch (\Throwable $error) { echo '<div class="notice notice-error inline"><p>'.esc_html($error->getMessage()).'</p></div>'; }
            }
            echo '<p><a href="'.esc_url(self::url('stores')).'" target="_top">返回店铺列表</a></p></div>'; return;
        }
        echo '<div class="wrap gsm"><h1>Group Shop Manager <small>v'.esc_html(GSM_VERSION).'</small></h1><p>员工跨店管理 · 顾客按店注册 · 支付集成暂未开发</p><nav class="nav-tab-wrapper">';
        foreach (self::TABS as $key=>$label) { echo '<a class="nav-tab '.(self::tab()===$key?'nav-tab-active':'').'" href="'.esc_url(self::url($key)).'">'.esc_html($label).'</a>'; }
        echo '</nav>';
        if (!is_multisite()) { echo '<div class="notice notice-warning inline"><p>当前为单站模式，可以管理主店；添加店铺及分层账户管理需要先按文档启用 WordPress Multisite。插件不会自动改造数据库或服务器。</p></div>'; }
        $notice=get_site_transient('gsm_notice_'.get_current_user_id());
        if ($notice) { delete_site_transient('gsm_notice_'.get_current_user_id()); echo '<div class="notice notice-'.($notice['ok']?'success':'error').' inline"><p>'.esc_html($notice['message']).'</p></div>'; }
        try {
            $tab=self::tab(); $store=self::store();
            if (!in_array($tab,['overview','stores','audit','help'],true)) {
                self::select_store($store);
                if ($store) { Stores::assert_store($store); }
            }
            self::{$tab}($store);
        } catch (\Throwable $error) { echo '<div class="notice notice-error inline"><p>'.esc_html($error->getMessage()).'</p></div>'; }
        echo '</div>';
    }
    private static function overview(int $store): void {
        echo '<div class="gsm-grid"><section class="gsm-card"><h2>店铺</h2><strong>'.count(Stores::all()).'</strong><p>主店 #'.Stores::main_id().'；最多 25 店。</p></section><section class="gsm-card"><h2>账户与权限</h2><p>员工按授权跨店登录。顾客每店独立注册，用户名和邮箱可相同，密码与地址互不影响。</p><p><a href="'.esc_url(is_multisite()?network_admin_url('users.php'):admin_url('users.php')).'">管理原生用户</a></p></section><section class="gsm-card"><h2>分类与供货</h2><p>主站原生产品分类与标签全店共用，集合按本店规则选品。所有商品不管理库存、始终有货；新商品仍为草稿。</p></section></div><h2>店铺入口</h2><table class="widefat striped"><thead><tr><th>店铺</th><th>地址</th><th>操作</th></tr></thead><tbody>';
        foreach (Stores::all() as $id=>$name) {
            echo '<tr><td>'.esc_html($name).' #'.$id.'</td><td><a target="_blank" rel="noopener" href="'.esc_url(get_home_url($id)).'">'.esc_html(get_home_url($id)).'</a></td><td><a href="'.esc_url(self::url('products',['store'=>$id])).'">商品</a> · <a href="'.esc_url(Orders::admin_url((int)$id)).'">原生订单</a> · <a href="'.esc_url(self::url('stores',['store'=>$id,'gsm_dialog'=>'edit'])).'">设置</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    private static function stores(int $store): void {
        echo '<div class="gsm-stores-heading"><h2>店铺列表 <span class="description">共 '.count(Stores::all()).' 家</span></h2>';
        if (is_multisite()) { echo '<a class="button button-primary" data-gsm-store-dialog="新增店铺" href="'.esc_url(self::url('stores',['gsm_dialog'=>'create'])).'">新增店铺</a>'; }
        echo '</div><p>每家店铺独立保存品牌、域名、主题和收录设置。点击编辑在弹窗中修改。</p><table class="widefat striped gsm-stores-table"><thead><tr><th>店铺</th><th>域名／地址</th><th>主题</th><th>搜索引擎收录</th><th>邮件</th><th>操作</th></tr></thead><tbody>';
        foreach (Stores::all() as $id=>$name) {
            $row=Stores::within($id,static fn()=>['logo'=>wp_get_attachment_image_url(Stores::logo_id(),'thumbnail'),'theme'=>wp_get_theme()->get('Name'),'public'=>(bool)get_option('blog_public'),'mail'=>!empty(Stores::settings()['mail_enabled'])]);
            echo '<tr data-store="'.(int)$id.'"><td><div class="gsm-store-brand">';
            if ($row['logo']) { echo '<img src="'.esc_url($row['logo']).'" alt="" width="40" height="40">'; }
            echo '<div><strong>'.esc_html($name).'</strong><br><span class="description">#'.(int)$id.($id===Stores::main_id()?' · 主店':'').'</span></div></div></td><td><a href="'.esc_url(get_home_url($id)).'" target="_blank" rel="noopener">'.esc_html(get_home_url($id)).'</a></td><td>'.esc_html($row['theme']).'</td><td>'.($row['public']?'允许收录':'不鼓励收录').'</td><td>'.($row['mail']?'已启用':'已关闭').'</td><td><a class="button" data-gsm-store-dialog="编辑店铺" href="'.esc_url(self::url('stores',['store'=>$id,'gsm_dialog'=>'edit'])).'">编辑</a></td></tr>';
        }
        echo '</tbody></table><dialog class="gsm-store-dialog" aria-labelledby="gsm-store-dialog-title"><header><h2 id="gsm-store-dialog-title">店铺设置</h2><button type="button" class="button gsm-dialog-close" aria-label="关闭店铺设置">关闭</button></header><iframe title="店铺设置表单"></iframe></dialog>';
    }
    private static function store_editor(int $store, bool $create): void {
        $retry=get_site_transient('gsm_store_retry_'.get_current_user_id());
        delete_site_transient('gsm_store_retry_'.get_current_user_id());
        $retry=is_array($retry)&&$retry['mode']===($create?'create':'edit')&&(int)$retry['store']===$store?$retry['fields']:[];
        if ($create) {
            echo '<h2>新增店铺</h2><p>建立本网络的新店，不复制历史订单、评论或支付设置。新店默认不鼓励搜索收录，关闭客户邮件；创建后点击编辑完善 Logo、主题和邮箱。</p>';
            self::form('store_create',Stores::main_id(),'stores');
            echo '<input type="hidden" name="gsm_dialog" value="create">';
            self::input('店铺名称','name',$retry['name']??'','text','required');
            self::input('店铺域名','domain',$retry['domain']??'','text','required placeholder="例如 brand.animecats.online"');
            echo '<p class="description">使用 HTTPS 域名根路径。请先完成 DNS、服务器路由与证书配置；这里只设置 WordPress 绑定，不会自动申请证书或配置服务器。</p>';
            submit_button('创建店铺'); echo '</form>'; return;
        }
        $store=$store?:Stores::main_id();
        $settings=Stores::within($store,static fn()=>['name'=>get_bloginfo('name'),'tagline'=>get_bloginfo('description'),'theme'=>get_stylesheet(),'logo_id'=>Stores::logo_id(),'blog_public'=>(string)get_option('blog_public','1'),'logo_url'=>wp_get_attachment_image_url(Stores::logo_id(),'medium')] + Stores::settings());
        $settings['domain']=is_multisite()?(string)get_site($store)->domain:(string)wp_parse_url(get_home_url($store),PHP_URL_HOST);
        if ($store===Stores::main_id()) { unset($retry['domain']); }
        $settings=array_merge($settings,$retry);
        $settings['logo_url']=Stores::within($store,static fn()=>wp_get_attachment_image_url(absint($settings['logo_id']),'medium'));
        echo '<h2>编辑店铺：'.esc_html($settings['name']).'</h2><p>店铺永久编号 #'.$store.' · '.esc_html($settings['uuid']??'尚未初始化').'</p>';
        self::form('store_save',$store,'stores');
        echo '<input type="hidden" name="gsm_dialog" value="edit">';
        self::input('店铺域名','domain',$settings['domain'],'text','required '.($store===Stores::main_id()?'readonly':''));
        echo '<p class="description">'.($store===Stores::main_id()?'主店域名只读，由网络与服务器配置维护，避免影响统一后台。':'更换后立即以 HTTPS 绑定到新域名根路径。请先完成 DNS、服务器路由及证书配置；正文旧链接、支付回调和旧域名跳转需另行迁移。').'</p>';
        foreach (['name'=>'店铺名称','tagline'=>'站点说明','from_name'=>'邮件发件名称','from_email'=>'邮件发件邮箱','support_email'=>'客服／回复邮箱'] as $key=>$label) { self::input($label,$key,$settings[$key]??''); }
        echo '<div class="gsm-field gsm-logo-picker"><strong>店铺 Logo</strong><input type="hidden" name="logo_id" value="'.(int)$settings['logo_id'].'"><p><img class="gsm-logo-preview" src="'.esc_url($settings['logo_url']?:'').'" alt="当前店铺 Logo" '.(!$settings['logo_url']?'hidden':'').' style="max-width:240px;max-height:100px"></p><button type="button" class="button gsm-logo-select">从媒体库选择图片</button> <button type="button" class="button gsm-logo-remove" '.(!$settings['logo_id']?'hidden':'').'>移除 Logo</button><p class="description">使用当前店铺的原生媒体库；选择或移除后点击下方保存。没有 Logo 时主题可显示店铺名称。</p></div>';
        echo '<label class="gsm-field"><span>搜索引擎收录</span><select name="blog_public"><option value="0" '.selected($settings['blog_public'],'0',false).'>不鼓励搜索引擎收录（建站中）</option><option value="1" '.selected($settings['blog_public'],'1',false).'>允许搜索引擎收录</option></select></label><p class="description">与 WordPress“设置 → 阅读”的搜索引擎可见性同步。新店默认不鼓励收录；这是收录建议，不是访问限制，也不保证搜索引擎一定收录或立即删除已收录页面。</p>';
        echo '<label class="gsm-field"><span>主题</span><select name="theme">';
        $allowed=(array)get_site_option('allowedthemes',[]);
        foreach (wp_get_themes() as $slug=>$theme) {
            if (is_multisite()&&$slug!==$settings['theme']&&empty($allowed[$slug])) { continue; }
            echo '<option value="'.esc_attr($slug).'" '.selected($settings['theme'],$slug,false).'>'.esc_html($theme->get('Name')).'</option>';
        }
        echo '</select></label><label><input type="checkbox" name="mail_enabled" value="1" '.checked(!empty($settings['mail_enabled']),true,false).'> 已完成该店 FluentSMTP 发件身份配置，允许发送邮件</label><p class="description">新店默认禁止发信。此开关不验证 DNS、不创建邮箱，也不复制主店 SMTP 密钥。</p>';
        submit_button('保存店铺设置'); echo '</form>';
    }
    private static function products(int $store): void { ProductAdmin::render($store); }
    private static function collections(int $store): void { CollectionAdmin::collections($store); }
    private static function audit(int $store): void {
        global $wpdb;
        $ids=array_keys(Stores::all());
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Schema::table('audit').' WHERE store_id IN ('.implode(',',array_fill(0,count($ids),'%d')).') ORDER BY id DESC LIMIT 100',...$ids));
        echo '<p>最近 100 项管理事件。日志不记录密码、SMTP 密钥、邮件正文或完整客户资料。</p><table class="widefat striped"><thead><tr><th>时间 UTC</th><th>店铺</th><th>管理员</th><th>动作／对象</th><th>详情</th></tr></thead><tbody>';
        foreach ($rows as $row) { echo '<tr><td>'.esc_html($row->created_at).'</td><td>#'.(int)$row->store_id.'</td><td>#'.(int)$row->actor_id.'</td><td>'.esc_html($row->action).' #'.(int)$row->object_id.'</td><td><code>'.esc_html($row->details).'</code></td></tr>'; }
        echo '</tbody></table>';
    }
    private static function help(int $store): void {
        echo '<section class="gsm-card"><h2>先读接手文档</h2><p><code>plugin/group-shop-manager/README.md</code> → <code>docs/HANDOFF.md</code> → <code>docs/ARCHITECTURE.md</code> → <code>CHANGELOG.md</code></p><h2>当前版本边界</h2><ul><li>同一 Multisite 使用原生用户：员工共享身份按店授权，顾客身份、密码和默认地址按店隔离。不会自动接入独立 Trovesia 店铺。</li><li>不同域名分别登录，未实现跨域自动 SSO。</li><li>支付及保存付款方式共享暂未开发，不复制主店支付配置。</li><li>新店禁止收录与发信，需独立完成域名、HTTPS、SMTP 和上线检查。</li><li>店铺商品独立维护，不再提供主商品跨店复制或字段覆盖；本店编辑保留。</li><li>主站原生分类／标签全店统一，本店商品集合独立；商品内容、价格、发布状态、评论、购物车和前台订单按店隔离，所有商品不管理库存、始终有货。</li><li>订单通过所属店 WooCommerce 原生后台管理，各店分别查看；支付、退款与物流集成仍待配置。</li><li>不修改 FluentSMTP 源码，不保证 Google 无法识别品牌关联。</li></ul></section>';
    }
}
