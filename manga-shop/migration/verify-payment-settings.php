<?php
/**
 * Verify payment configuration without printing credentials.
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

// WP-CLI has no request transport, so model the production HTTPS request used
// by checkout before asking gateways whether they are available.
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

$stripe = get_option('woocommerce_stripe_settings', []);
$paypal = get_option('woocommerce-ppcp-settings', []);
$paypalGateway = get_option('woocommerce_ppcp-gateway_settings', []);

$stripe = is_array($stripe) ? $stripe : [];
$paypal = is_array($paypal) ? $paypal : [];
$paypalGateway = is_array($paypalGateway) ? $paypalGateway : [];

$stripeTestMode = wc_string_to_bool((string) ($stripe['testmode'] ?? 'no'));
$stripeSecret = (string) ($stripeTestMode ? ($stripe['test_secret_key'] ?? '') : ($stripe['secret_key'] ?? ''));
$stripePublishable = (string) ($stripeTestMode ? ($stripe['test_publishable_key'] ?? '') : ($stripe['publishable_key'] ?? ''));

$paypalSandbox = wc_string_to_bool((string) ($paypal['sandbox_on'] ?? 'no'));
$paypalClientId = (string) ($paypalSandbox
    ? ($paypal['client_id_sandbox'] ?? $paypal['client_id'] ?? '')
    : ($paypal['client_id_production'] ?? $paypal['client_id'] ?? ''));
$paypalClientSecret = (string) ($paypalSandbox
    ? ($paypal['client_secret_sandbox'] ?? $paypal['client_secret'] ?? '')
    : ($paypal['client_secret_production'] ?? $paypal['client_secret'] ?? ''));

$stripeHttp = null;
$stripeLivemode = null;
$stripeWebhookUrls = [];
$stripeWebhookHttp = null;
if ($stripeSecret !== '') {
    $response = wp_remote_get('https://api.stripe.com/v1/account', [
        'headers' => ['Authorization' => 'Bearer ' . $stripeSecret],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) {
        $stripeHttp = 0;
    } else {
        $stripeHttp = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $stripeLivemode = is_array($body) && array_key_exists('livemode', $body) ? (bool) $body['livemode'] : null;
    }

    $webhookResponse = wp_remote_get('https://api.stripe.com/v1/webhook_endpoints?limit=100', [
        'headers' => ['Authorization' => 'Bearer ' . $stripeSecret],
        'timeout' => 20,
    ]);
    if (! is_wp_error($webhookResponse)) {
        $stripeWebhookHttp = wp_remote_retrieve_response_code($webhookResponse);
    }
    if (! is_wp_error($webhookResponse) && $stripeWebhookHttp === 200) {
        $webhookBody = json_decode(wp_remote_retrieve_body($webhookResponse), true);
        foreach (($webhookBody['data'] ?? []) as $endpoint) {
            if (is_array($endpoint) && ! empty($endpoint['url'])) {
                $stripeWebhookUrls[] = (string) $endpoint['url'];
            }
        }
    }
}

$paypalHttp = null;
$paypalTokenPresent = false;
$paypalWebhookUrls = [];
$paypalWebhookHttp = null;
if ($paypalClientId !== '' && $paypalClientSecret !== '') {
    $paypalHost = $paypalSandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    $response = wp_remote_post($paypalHost . '/v1/oauth2/token', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($paypalClientId . ':' . $paypalClientSecret),
            'Accept'        => 'application/json',
            'Accept-Language'=> 'en_US',
        ],
        'body'    => ['grant_type' => 'client_credentials'],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) {
        $paypalHttp = 0;
    } else {
        $paypalHttp = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $paypalTokenPresent = is_array($body) && ! empty($body['access_token']);
        if ($paypalTokenPresent) {
            $webhookResponse = wp_remote_get($paypalHost . '/v1/notifications/webhooks', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $body['access_token'],
                    'Accept'        => 'application/json',
                ],
                'timeout' => 20,
            ]);
            if (! is_wp_error($webhookResponse)) {
                $paypalWebhookHttp = wp_remote_retrieve_response_code($webhookResponse);
            }
            if (! is_wp_error($webhookResponse) && $paypalWebhookHttp === 200) {
                $webhookBody = json_decode(wp_remote_retrieve_body($webhookResponse), true);
                foreach (($webhookBody['webhooks'] ?? []) as $endpoint) {
                    if (is_array($endpoint) && ! empty($endpoint['url'])) {
                        $paypalWebhookUrls[] = (string) $endpoint['url'];
                    }
                }
            }
        }
    }
}

$gateways = WC()->payment_gateways()->payment_gateways();
$stripeGateway = $gateways['stripe'] ?? null;
$stripeWebhookDataKey = $stripeTestMode ? 'test_webhook_data' : 'webhook_data';
$stripeWebhookSecretKey = $stripeTestMode ? 'test_webhook_secret' : 'webhook_secret';
$stripeWebhookData = $stripe[$stripeWebhookDataKey] ?? [];

echo wp_json_encode([
    'stripe' => [
        'plugin_version'            => defined('WC_STRIPE_VERSION') ? WC_STRIPE_VERSION : null,
        'gateway_registered'        => isset($gateways['stripe']),
        'key_format_valid'          => $stripeGateway && method_exists($stripeGateway, 'are_keys_set')
            ? (bool) $stripeGateway->are_keys_set()
            : null,
        'checkout_available'        => $stripeGateway ? $stripeGateway->is_available() : false,
        'enabled'                   => ($stripe['enabled'] ?? 'no') === 'yes',
        'test_mode'                 => $stripeTestMode,
        'publishable_key_present'   => $stripePublishable !== '',
        'secret_key_present'        => $stripeSecret !== '',
        'webhook_secret_present'    => ! empty($stripe[$stripeWebhookSecretKey]),
        'configured_webhook_url'    => is_array($stripeWebhookData) ? ($stripeWebhookData['url'] ?? null) : null,
        'api_http_status'           => $stripeHttp,
        'api_livemode'              => $stripeLivemode,
        'webhook_api_http_status'   => $stripeWebhookHttp,
        'registered_webhook_urls'   => array_values(array_unique($stripeWebhookUrls)),
    ],
    'paypal' => [
        'plugin_version'       => defined('PPCP_VERSION') ? PPCP_VERSION : null,
        'gateway_registered'   => isset($gateways['ppcp-gateway']),
        'checkout_available'   => isset($gateways['ppcp-gateway']) && $gateways['ppcp-gateway']->is_available(),
        'enabled'              => ($paypalGateway['enabled'] ?? $paypal['enabled'] ?? 'no') === 'yes',
        'sandbox_mode'         => $paypalSandbox,
        'client_id_present'    => $paypalClientId !== '',
        'client_secret_present'=> $paypalClientSecret !== '',
        'api_http_status'      => $paypalHttp,
        'access_token_received'=> $paypalTokenPresent,
        'webhook_api_http_status' => $paypalWebhookHttp,
        'registered_webhook_urls' => array_values(array_unique($paypalWebhookUrls)),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
