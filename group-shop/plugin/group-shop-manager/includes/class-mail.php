<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
final class Mail {
    public static function boot(): void {
        // FluentSMTP caches settings inside a request. Use one authoritative network
        // configuration, with exact sender mappings, instead of switching credentials.
        add_filter('pre_option_fluentmail-settings', static function ($pre) {
            if (get_current_blog_id() === Stores::main_id()) { return $pre; }
            return get_blog_option(Stores::main_id(), 'fluentmail-settings', []);
        });
        add_filter('pre_update_option_fluentmail-settings', static function ($value, $old) {
            if (get_current_blog_id() !== Stores::main_id()) {
                update_blog_option(Stores::main_id(), 'fluentmail-settings', $value);
                return $old;
            }
            return $value;
        }, 10, 2);
        add_filter('wp_mail_from', [self::class, 'from'], 100);
        add_filter('woocommerce_email_from_address', [self::class, 'from'], 100);
        add_filter('wp_mail_from_name', [self::class, 'name'], 100);
        add_filter('woocommerce_email_from_name', [self::class, 'name'], 100);
        add_filter('wp_mail', [self::class, 'headers'], 100);
        add_filter('pre_wp_mail', [self::class, 'guard'], 20, 2);
        add_filter('woocommerce_email_footer_text', [self::class, 'footer'], 100);
        add_filter('woocommerce_email_header_image', static fn($url) => has_custom_logo() ? wp_get_attachment_url((int)get_theme_mod('custom_logo')) : $url, 100);
    }
    public static function from(string $value): string { return (Stores::settings()['from_email'] ?? '') ?: $value; }
    public static function name(string $value): string { return Stores::settings()['from_name'] ?? get_bloginfo('name'); }
    public static function headers(array $mail): array {
        $headers = $mail['headers'] ?? [];
        $headers = is_array($headers) ? $headers : preg_split('/\r?\n/', $headers);
        $headers = array_values(array_filter($headers, static fn($line) => !preg_match('/^(?:Reply-To|X-Group-Shop-Store):/i', (string)$line)));
        $support = Stores::settings()['support_email'] ?? '';
        if (is_email($support)) { $headers[] = 'Reply-To: ' . $support; }
        $headers[] = 'X-Group-Shop-Store: ' . get_current_blog_id();
        $mail['headers'] = $headers;
        return $mail;
    }
    public static function guard(mixed $result, array $mail): mixed {
        if ($result !== null) { return $result; }
        $settings = Stores::settings();
        if (!empty($settings['mail_enabled'])) {
            if (get_current_blog_id() === Stores::main_id()) { return null; }
            $smtp = (array)get_option('fluentmail-settings', []);
            $from = $settings['from_email'] ?? '';
            $connection = $smtp['mappings'][$from] ?? '';
            if (function_exists('fluentMailGetSettings') && $connection && !empty($smtp['connections'][$connection])) { return null; }
            Schema::audit('mail_blocked', get_current_blog_id(), 0, ['reason' => 'missing_exact_smtp_sender_mapping']);
            return false;
        }
        // Fail closed for an unconfigured new store, rather than leaking the master brand.
        Schema::audit('mail_blocked', get_current_blog_id(), 0, ['reason' => 'store_mail_disabled']);
        return false;
    }
    public static function footer(string $value): string {
        $support = Stores::settings()['support_email'] ?? '';
        return sprintf('<p><strong>%s</strong></p><p><a href="%s">Shop</a> · <a href="%s">My account</a> · <a href="%s">Privacy</a></p><p><a href="mailto:%s">%s</a></p>',
            esc_html(get_bloginfo('name')), esc_url(home_url('/')), esc_url(wc_get_page_permalink('myaccount')), esc_url(get_privacy_policy_url() ?: home_url('/privacy-policy/')), esc_attr($support), esc_html($support));
    }
}
