<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
final class Mail {
    private static ?int $mail_brand=null;
    private static array $templates=[];
    public static function boot(): void {
        // FluentSMTP caches settings inside a request. Use one authoritative network
        // configuration, with exact sender mappings, instead of switching credentials.
        add_action('woocommerce_email',[self::class,'scope_notifications'],PHP_INT_MAX);
        add_action('shutdown',static function (): void { while (self::$templates) { $old=array_pop(self::$templates); if ($old!==null) { Brands::leave($old); } } },PHP_INT_MAX);
        add_action('woocommerce_before_template_part',static function ($name,$path,$located,$args): void {
            if (!str_starts_with($name,'emails/')) { return; }
            $order=$args['order']??($args['email']->object??null); $brand=$order instanceof \WC_Order?(int)$order->get_meta('_gsm_store_id'):0;
            self::$templates[]=$brand&&isset(Stores::all()[$brand])?Brands::enter($brand):null;
        },0,4);
        add_action('woocommerce_after_template_part',static function ($name): void { if (str_starts_with($name,'emails/')&&self::$templates) { $old=array_pop(self::$templates); if ($old!==null) { Brands::leave($old); } } },PHP_INT_MAX);
        add_filter('woocommerce_email_headers',static function ($headers,$id,$object,$email) { $brand=self::email_brand($email); return $headers.'X-Group-Shop-Store: '.$brand."\r\n"; },90,4);
        add_filter('woocommerce_email_format_string_find',static function ($find) { $find['gsm-blogname']='{blogname}'; return $find; },50);
        add_filter('woocommerce_email_format_string_replace',static function ($replace,$email) { $brand=self::email_brand($email); $replace['site-title']=Brands::name($brand); $replace['site-url']=Brands::url($brand); $replace['gsm-blogname']=Brands::name($brand); return $replace; },50,2);

        add_filter('wp_mail_from', [self::class, 'from'], 100);
        add_filter('woocommerce_email_from_address', [self::class, 'from'], 100,2);
        add_filter('wp_mail_from_name', [self::class, 'name'], 100);
        add_filter('woocommerce_email_from_name', [self::class, 'name'], 100,2);
        add_filter('wp_mail', [self::class, 'headers'], 100);
        add_filter('pre_wp_mail', [self::class, 'guard'], 20, 2);
        add_filter('woocommerce_email_footer_text', [self::class, 'footer'], 100);
        add_filter('woocommerce_email_header_image', static fn($url) => has_custom_logo() ? wp_get_attachment_url((int)get_theme_mod('custom_logo')) : $url, 100);
    }
    public static function scope_notifications($mailer): void {
        global $wp_filter;
        foreach ($wp_filter as $hook=>$object) {
            if (!str_starts_with($hook,'woocommerce_')||!str_ends_with($hook,'_notification')) { continue; }
            foreach ($object->callbacks as $priority=>$items) { foreach ($items as $item) {
                $callback=$item['function'];
                if (!is_array($callback)||!($callback[0] instanceof \WC_Email)) { continue; }
                remove_action($hook,$callback,$priority);
                add_action($hook,static function (...$args) use ($callback): void {
                    $order=null; foreach ($args as $arg) { if ($arg instanceof \WC_Order) { $order=$arg; break; } }
                    if (!$order&&!empty($args[0])&&is_numeric($args[0])) { $order=wc_get_order((int)$args[0]); }
                    $brand=$order?(int)$order->get_meta('_gsm_store_id'):0;
                    if ($brand&&isset(Stores::all()[$brand])) { Stores::within($brand,static fn()=>call_user_func_array($callback,$args)); }
                    else { call_user_func_array($callback,$args); }
                },$priority,$item['accepted_args']);
            } }
        }
    }
    private static function email_brand(mixed $email=null): int { $object=$email->object??null; return $object instanceof \WC_Order?((int)$object->get_meta('_gsm_store_id')?:Brands::id()):Brands::id(); }
    public static function from(string $value,mixed $email=null): string { $brand=$email?self::email_brand($email):(self::$mail_brand??Brands::id()); return (Brands::raw_options($brand)['gsm_store_settings']['from_email']??'')?:$value; }
    public static function name(string $value,mixed $email=null): string { $brand=$email?self::email_brand($email):(self::$mail_brand??Brands::id()); return (Brands::raw_options($brand)['gsm_store_settings']['from_name']??'')?:Brands::name($brand); }
    public static function headers(array $mail): array {
        $headers = $mail['headers'] ?? [];
        $headers = is_array($headers) ? $headers : preg_split('/\r?\n/', $headers);
        $brand=Brands::id(); foreach ($headers as $line) { if (preg_match('/^X-Group-Shop-Store:\s*(\d+)/i',(string)$line,$m)&&isset(Stores::all()[(int)$m[1]])) { $brand=(int)$m[1]; } } self::$mail_brand=$brand;
        $headers = array_values(array_filter($headers, static fn($line) => !preg_match('/^(?:Reply-To|X-Group-Shop-Store):/i', (string)$line)));
        $support = Brands::raw_options(self::$mail_brand??Brands::id())['gsm_store_settings']['support_email']??'';
        if (is_email($support)) { $headers[] = 'Reply-To: ' . $support; }
        $headers[] = 'X-Group-Shop-Store: ' . $brand;
        $mail['headers'] = $headers;
        return $mail;
    }
    public static function guard(mixed $result, array $mail): mixed {
        if ($result !== null) { return $result; }
        $brand=self::$mail_brand??Brands::id(); $settings=Brands::raw_options($brand)['gsm_store_settings']??[];
        if (!empty($settings['mail_enabled'])) {
            if ($brand === Stores::main_id()) { return null; }
            $smtp = (array)get_option('fluentmail-settings', []);
            $from = $settings['from_email'] ?? '';
            $connection = $smtp['mappings'][$from] ?? '';
            if (function_exists('fluentMailGetSettings') && $connection && !empty($smtp['connections'][$connection])) { return null; }
            Schema::audit('mail_blocked', $brand, 0, ['reason' => 'missing_exact_smtp_sender_mapping']);
            return false;
        }
        // Fail closed for an unconfigured new store, rather than leaking the master brand.
        Schema::audit('mail_blocked', $brand, 0, ['reason' => 'store_mail_disabled']);
        return false;
    }
    public static function footer(string $value): string {
        $support = Stores::settings()['support_email'] ?? '';
        return sprintf('<p><strong>%s</strong></p><p><a href="%s">Shop</a> · <a href="%s">My account</a> · <a href="%s">Privacy</a></p><p><a href="mailto:%s">%s</a></p>',
            esc_html(get_bloginfo('name')), esc_url(home_url('/')), esc_url(wc_get_page_permalink('myaccount')), esc_url(get_privacy_policy_url() ?: home_url('/privacy-policy/')), esc_attr($support), esc_html($support));
    }
}
