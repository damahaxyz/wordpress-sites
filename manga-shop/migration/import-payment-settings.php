<?php
/**
 * Import approved Stripe and PayPal gateway settings.
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

$inputPath = $args[0] ?? '';
if (! is_string($inputPath) || $inputPath === '' || ! is_readable($inputPath)) {
    throw new RuntimeException('Pass the payment settings JSON path as the first argument.');
}

$payload = json_decode((string) file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
if (($payload['format'] ?? '') !== 'manga-shop-payment-settings-v1') {
    throw new RuntimeException('Unsupported payment settings export format.');
}

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

$imported = [];
foreach ($payload['options'] as $row) {
    $name = (string) $row['option_name'];
    if (! in_array($name, $approvedOptions, true)) {
        throw new RuntimeException("Unapproved payment option in export: {$name}");
    }

    $value = maybe_unserialize((string) $row['option_value']);

    // Webhook registrations belong to a concrete site URL. Never copy an old
    // webhook identifier to the apex-domain site; retain credentials/settings
    // and let each gateway establish a webhook for the new endpoint.
    if (is_array($value)) {
        foreach (array_keys($value) as $key) {
            $normalizedKey = is_string($key) ? strtolower($key) : '';
            if (
                in_array($normalizedKey, ['webhook_secret', 'test_webhook_secret'], true)
                || str_contains($normalizedKey, 'webhook_id')
            ) {
                unset($value[$key]);
            }
        }
    }

    $autoload = in_array((string) $row['autoload'], ['yes', 'on', 'auto-on'], true);
    update_option($name, $value, $autoload);
    $imported[] = $name;
}

if (class_exists('WC_Cache_Helper')) {
    WC_Cache_Helper::invalidate_cache_group('settings');
}
wp_cache_flush();

echo 'PAYMENT_SETTINGS_IMPORT_COMPLETE ' . wp_json_encode([
    'imported_options' => $imported,
    'webhook_ids_copied' => false,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
