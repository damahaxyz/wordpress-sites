<?php
/**
 * Configure the current Stripe mode's webhook through the official plugin API.
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

if (! class_exists('WC_Stripe') || ! class_exists('WC_Stripe_Helper')) {
    throw new RuntimeException('WooCommerce Stripe Gateway is not active.');
}

$settings = WC_Stripe_Helper::get_stripe_settings();
$testMode = wc_string_to_bool((string) ($settings['testmode'] ?? 'no'));
$mode = $testMode ? 'test' : 'live';
$secretKey = $testMode ? 'test_webhook_secret' : 'webhook_secret';
$dataKey = $testMode ? 'test_webhook_data' : 'webhook_data';
$webhookUrl = WC_Stripe_Helper::get_webhook_url();

if (! str_starts_with($webhookUrl, 'https://www.mangaproduct.com/')) {
    throw new RuntimeException('Refusing to configure an unexpected Stripe webhook URL.');
}

$existingData = $settings[$dataKey] ?? [];
if (
    ! empty($settings[$secretKey])
    && is_array($existingData)
    && ($existingData['url'] ?? '') === $webhookUrl
) {
    echo wp_json_encode([
        'configured' => true,
        'created'    => false,
        'mode'       => $mode,
        'url'        => $webhookUrl,
    ]) . PHP_EOL;
    return;
}

$stripe = WC_Stripe::get_instance();
if (! isset($stripe->account) || ! $stripe->account instanceof WC_Stripe_Account) {
    throw new RuntimeException('Stripe account service is unavailable.');
}

$stripe->account->configure_webhooks($mode);
$updated = WC_Stripe_Helper::get_stripe_settings();
$updatedData = $updated[$dataKey] ?? [];
if (
    empty($updated[$secretKey])
    || ! is_array($updatedData)
    || ($updatedData['url'] ?? '') !== $webhookUrl
) {
    throw new RuntimeException('Stripe created a webhook but its local signing configuration was not saved.');
}

echo wp_json_encode([
    'configured' => true,
    'created'    => true,
    'mode'       => $mode,
    'url'        => $webhookUrl,
]) . PHP_EOL;
