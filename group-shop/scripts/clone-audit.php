<?php
/** Read-only aggregate audit; no personal data, credentials or mail. */
global $wpdb;
$tables = $wpdb->get_col('SHOW TABLES');
$counts = [];
foreach ($tables as $table) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) { continue; }
    if (preg_match('/(?:posts|postmeta|users|usermeta|comments|commentmeta|terms|term_taxonomy|term_relationships|termmeta|wc_orders|wc_order_addresses|wc_order_operational_data|wc_orders_meta|woocommerce_order_items|woocommerce_order_itemmeta|trovesia_.*)$/', $table)) {
        $counts[$table] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
    }
}
ksort($counts);
$report = ['site' => home_url('/'), 'name' => get_bloginfo('name'), 'core_counts' => $counts,
    'posts' => $wpdb->get_results("SELECT post_type, post_status, COUNT(*) AS count FROM {$wpdb->posts} GROUP BY post_type, post_status ORDER BY post_type, post_status", ARRAY_A),
    'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
    'currency' => get_option('woocommerce_currency'),
    'mail_encryption_ok' => !function_exists('fluentMailEncryptDecrypt') || fluentMailEncryptDecrypt((string)(get_option('fluentmail-settings')['test'] ?? ''), 'd') === 'test'];
$stripe = get_option('woocommerce_stripe_settings', []);
$report['stripe'] = ['enabled' => $stripe['enabled'] ?? 'unset', 'testmode' => $stripe['testmode'] ?? 'unset', 'has_secret_key' => !empty($stripe['secret_key']), 'has_webhook_secret' => !empty($stripe['webhook_secret'])];
$ppcp = get_option('woocommerce-ppcp-settings', []);
$report['paypal'] = ['settings_present' => !empty($ppcp), 'option_keys' => is_array($ppcp) ? array_keys($ppcp) : []];
$as_table = $wpdb->prefix . 'actionscheduler_actions';
if (in_array($as_table, $tables, true)) {
    $report['queued_actions'] = $wpdb->get_results("SELECT status, COUNT(*) AS count FROM `$as_table` GROUP BY status ORDER BY status", ARRAY_A);
}
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
