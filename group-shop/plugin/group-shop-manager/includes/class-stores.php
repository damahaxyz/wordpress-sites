<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
final class Stores {
    public static function boot(): void {
        add_filter('theme_mod_custom_logo', static function ($value) { $logo=get_option('gsm_logo_id',false); return $logo===false?$value:(int)$logo; });
        $capture=static function ($option,$value): void {
            if ($option!=='theme_mods_'.get_stylesheet()||!is_array($value)||!array_key_exists('custom_logo',$value)) { return; }
            $logo=absint($value['custom_logo']);
            if (!$logo||wp_attachment_is_image($logo)) { update_option('gsm_logo_id',$logo,false); }
        };
        add_action('updated_option',static function ($option,$old,$value) use ($capture): void { $capture($option,$value); },10,3);
        add_action('added_option',$capture,10,2);
        add_action('update_option_blog_public',static function (): void { self::purge(); },20);
    }
    public static function logo_id(): int { return (int)get_option('gsm_logo_id',get_theme_mod('custom_logo',0)); }
    public static function main_id(): int { return is_multisite() ? (int) get_main_site_id() : get_current_blog_id(); }
    public static function can_manage(): bool {
        return is_multisite() ? is_super_admin() && current_user_can('manage_network') : current_user_can('manage_options');
    }
    public static function authorize(): void {
        if (!self::can_manage()) { throw new \RuntimeException('需要网络管理员权限。'); }
    }
    public static function assert_store(int $id): void {
        if (!is_multisite()) {
            if ($id !== get_current_blog_id()) { throw new \RuntimeException('需要先启用 WordPress 多站点。'); }
            return;
        }
        $site = get_site($id);
        if (!$site || (int)$site->network_id !== get_current_network_id() || $site->deleted || $site->spam || $site->archived) {
            throw new \RuntimeException('店铺不存在、已停用或不属于当前网络。');
        }
    }
    public static function within(int $id, callable $callback): mixed {
        self::assert_store($id);
        $switched = get_current_blog_id() !== $id;
        if ($switched) { switch_to_blog($id); }
        try { return $callback(); } finally { if ($switched) { restore_current_blog(); } }
    }
    public static function all(): array {
        if (!is_multisite()) { return [get_current_blog_id() => get_bloginfo('name')]; }
        $result = [];
        foreach (get_sites(['network_id' => get_current_network_id(), 'number' => 100, 'deleted' => 0, 'spam' => 0, 'archived' => 0]) as $site) {
            $result[(int)$site->blog_id] = (string)get_blog_option((int)$site->blog_id, 'blogname');
        }
        return $result;
    }
    public static function settings(): array { return (array)get_option('gsm_store_settings', []); }
    /** Validate a root-domain mapping; unchanged legacy subdirectory addresses remain valid. */
    public static function domain(string $value, int $id = 0): string {
        self::authorize();
        if ($id) { self::assert_store($id); }
        $host = strtolower(trim($value));
        $host = preg_replace('~^https://~i', '', $host);
        $host = rtrim($host, '/');
        $current = $id ? (is_multisite()?(string)get_site($id)->domain:(string)wp_parse_url(get_home_url($id),PHP_URL_HOST)) : '';
        if ($id && $host === $current) { return $host; }
        if ($id === self::main_id() || !is_multisite()) { throw new \RuntimeException('主店域名由网络及服务器配置维护，不能在这里修改。'); }
        if (strlen($host)>253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) || filter_var($host,FILTER_VALIDATE_IP)) {
            throw new \RuntimeException('请输入有效域名，例如 test.animecats.online；不包含路径、端口、通配符或 http://。国际域名请使用 Punycode。');
        }
        foreach (get_sites(['domain'=>$host,'number'=>0]) as $site) {
            if ((int)$site->blog_id !== $id) { throw new \RuntimeException('此域名已被其他站点使用，包括已停用的站点。'); }
        }
        return $host;
    }
    public static function save(int $id, array $data): void {
        self::authorize();
        self::assert_store($id);
        $domain = array_key_exists('domain',$data) ? self::domain((string)$data['domain'],$id) : null;
        self::within($id, static function () use ($id,$data,$domain): void {
            $old = self::settings();
            $name = sanitize_text_field($data['name'] ?? get_bloginfo('name'));
            if ($name === '') { throw new \RuntimeException('店铺名称不能为空。'); }
            $from = sanitize_email($data['from_email'] ?? '');
            $support = sanitize_email($data['support_email'] ?? '');
            $enabled = !empty($data['mail_enabled']);
            if ($enabled && (!is_email($from) || !is_email($support))) { throw new \RuntimeException('启用邮件前请设置有效的发件和客服邮箱。'); }
            $logo = array_key_exists('logo_id',$data)?absint($data['logo_id']):self::logo_id();
            if ($logo && !wp_attachment_is_image($logo)) { throw new \RuntimeException('Logo 必须是当前店铺媒体库中的图片。'); }
            $public=array_key_exists('blog_public',$data)?(string)$data['blog_public']:(string)get_option('blog_public','1');
            if (!in_array($public,['0','1'],true)) { throw new \RuntimeException('搜索引擎收录设置无效。'); }
            $theme = sanitize_key($data['theme'] ?? get_stylesheet());
            if (!wp_get_theme($theme)->exists()) { throw new \RuntimeException('主题不存在。'); }
            $allowed = (array)get_site_option('allowedthemes', []);
            if (is_multisite() && $theme !== get_stylesheet() && empty($allowed[$theme])) { throw new \RuntimeException('请先在网络后台启用此主题。'); }
            if ($domain !== null && is_multisite() && $domain !== get_site($id)->domain) {
                $previous = (string)get_site($id)->domain;
                if (!update_blog_details($id,['domain'=>$domain,'path'=>'/'])) { throw new \RuntimeException('无法更新店铺域名，请重试。'); }
                update_option('home','https://'.$domain);
                update_option('siteurl','https://'.$domain);
                delete_option('rewrite_rules');
                Schema::audit('store_domain_updated',$id,0,['before'=>['domain'=>$previous],'after'=>['domain'=>$domain]]);
            }
            update_option('blogname', $name);
            update_option('blogdescription', sanitize_text_field($data['tagline'] ?? ''));
            update_option('blog_public',$public);
            update_option('gsm_store_settings', [
                'uuid' => $old['uuid'] ?? wp_generate_uuid4(), 'support_email' => $support,
                'from_email' => $from, 'from_name' => sanitize_text_field($data['from_name'] ?? $name),
                'mail_enabled' => $enabled,
            ], false);
            if ($theme !== get_stylesheet()) { switch_theme($theme); }
            update_option('gsm_logo_id',$logo,false);
            set_theme_mod('custom_logo', $logo);
            self::purge();
        });
        Schema::audit('store_updated', $id);
    }
    /** Create a draft storefront inside this installation, never a separate WordPress database. */
    public static function create(string $name, string $slug, string $mapped_domain = ''): int {
        self::authorize();
        if (!is_multisite()) { throw new \RuntimeException('请先按部署文档将主站转换为 Multisite。'); }
        if (count(self::all()) >= 25) { throw new \RuntimeException('第一版最多管理 25 家店铺。'); }
        $name = sanitize_text_field($name);
        $slug = sanitize_title($slug);
        if ($name === '' || !preg_match('/^[a-z][a-z0-9-]{1,39}$/', $slug) || in_array($slug, ['wp-admin', 'wp-json', 'shop', 'cart', 'checkout', 'my-account', 'products', 'channel-portal'], true)) {
            throw new \RuntimeException('名称不能为空；标识使用 2–40 位小写字母、数字或短横线，不能与商城路由冲突。');
        }
        $network = get_network();
        $domain = is_subdomain_install() ? $slug . '.' . $network->domain : $network->domain;
        $path = is_subdomain_install() ? $network->path : trailingslashit($network->path) . $slug . '/';
        if ($mapped_domain !== '') { $domain = self::domain($mapped_domain); $path = '/'; }
        $theme = (string)get_blog_option(self::main_id(), 'stylesheet');
        $allowed = (array)get_site_option('allowedthemes', []);
        $allowed[$theme] = true;
        update_site_option('allowedthemes', $allowed);
        $id = wpmu_create_blog($domain, $path, $name, get_current_user_id(), ['public' => 0], $network->id);
        if (is_wp_error($id)) { throw new \RuntimeException($id->get_error_message()); }
        $id = (int)$id;
        try {
            self::within($id, static function () use ($theme, $name, $mapped_domain, $domain): void {
                if ($mapped_domain !== '') {
                    update_option('home','https://'.$domain);
                    update_option('siteurl','https://'.$domain);
                }
                switch_theme($theme);
                update_option('gsm_store_settings', ['uuid' => wp_generate_uuid4(), 'support_email' => '', 'from_email' => '', 'from_name' => $name, 'mail_enabled' => false], false);
                update_option('woocommerce_currency', get_blog_option(self::main_id(), 'woocommerce_currency', 'USD'));
                update_option('woocommerce_enable_myaccount_registration', 'yes');
                update_option('woocommerce_registration_generate_password', 'no');
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                if (!is_plugin_active('woocommerce/woocommerce.php')) {
                    $result = activate_plugin('woocommerce/woocommerce.php');
                    if (is_wp_error($result)) { throw new \RuntimeException($result->get_error_message()); }
                }
                // Action Scheduler initializes only the request's first blog automatically.
                foreach (['ActionScheduler_StoreSchema', 'ActionScheduler_LoggerSchema'] as $schema_class) {
                    if (class_exists($schema_class)) { (new $schema_class())->register_tables(true); }
                }
                \WC_Install::install();
                \WC_Install::create_pages();
                update_option('woocommerce_manage_stock','no');
                // No source orders, users, comments, credentials or SMTP configuration are copied.
                if (is_plugin_active_for_network('trovesia-plugin/trovesia-plugin.php') || in_array('trovesia-plugin/trovesia-plugin.php', (array)get_blog_option(self::main_id(), 'active_plugins', []), true)) {
                    activate_plugin('trovesia-plugin/trovesia-plugin.php');
                }
                update_option('permalink_structure', '/%postname%/');
                flush_rewrite_rules(false);
            });
        } catch (\Throwable $error) {
            // Preserve failed setup for diagnosis; it remains noindex with customer mail disabled.
            Schema::audit('store_setup_failed', $id, 0, ['reason' => get_class($error)]);
            throw $error;
        }
        SharedTaxonomies::reconcile();
        Schema::audit('store_created', $id);
        return $id;
    }
    public static function purge(): void {
        if (class_exists('Trovesia\\Plugin\\Page_Cache')) { \Trovesia\Plugin\Page_Cache::instance()->purge_now('Group Shop Manager update'); }
    }
}
