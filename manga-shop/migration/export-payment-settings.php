<?php
/**
 * Export the approved Stripe and PayPal gateway settings only.
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'www.mangaproduct.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT'] = '443';

define('SHORTINIT', true);
require '/var/www/html/wp-load.php';

global $wpdb;

$approvedOptions = [
    'woocommerce_stripe_settings',
    'woocommerce_gateway_stripe_retention',
    'woocommerce-ppcp-settings',
    'woocommerce_ppcp-gateway_settings',
    'woocommerce_ppcp-axo-gateway_settings',
    'woocommerce_ppcp-recaptcha_settings',
    'woocommerce_ppcp-is_pay_later_settings_migrated',
    'woocommerce_ppcp-is_smart_button_settings_migrated',
];

$placeholders = implode(',', array_fill(0, count($approvedOptions), '%s'));
$query = $wpdb->prepare(
    "SELECT option_name, option_value, autoload FROM {$wpdb->options} "
    . "WHERE option_name IN ({$placeholders}) ORDER BY option_name",
    ...$approvedOptions
);
$rows = $wpdb->get_results($query, ARRAY_A);

if (! is_array($rows)) {
    throw new RuntimeException($wpdb->last_error ?: 'Could not read payment settings.');
}

$payload = [
    'format'          => 'manga-shop-payment-settings-v1',
    'exported_at'     => gmdate('c'),
    'source_url'      => 'https://www.mangaproduct.com',
    'approved_options'=> $approvedOptions,
    'options'         => $rows,
];

$json = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);

if (! is_string($json)) {
    throw new RuntimeException('Could not encode payment settings: ' . json_last_error_msg());
}

fwrite(STDOUT, $json . PHP_EOL);

