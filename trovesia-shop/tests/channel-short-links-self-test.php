<?php
/** WP-CLI HTTP checks with disposable channel/link records; no persisted orders. */
use Trovesia\Channel\Attribution;
use Trovesia\Channel\Database;
use Trovesia\Channel\Order_Attribution;
use Trovesia\Channel\Portal;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

global $wpdb;
$channel_id = 0;
$campaign_id = 0;
$checks = [];
$old_cookie = $_COOKIE['trovesia_channel_visit'] ?? null;
$assert = static function (bool $condition, string $name) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException('FAILED: ' . $name);
    }
    $checks[] = $name;
};
$request = static function (string $url, bool $internal = true): array {
    $response = wp_remote_get($url, [
        'redirection' => 0,
        'timeout' => 25,
        'user-agent' => 'Mozilla/5.0 TrovesiaChannelVerification',
        'headers' => $internal ? ['Host' => 'www.trovesia.com', 'X-Forwarded-Proto' => 'https'] : [],
    ]);
    if (is_wp_error($response)) {
        throw new RuntimeException('HTTP transport failed: ' . $response->get_error_code());
    }
    return $response;
};

try {
    $suffix = bin2hex(random_bytes(8));
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(Database::table('channels'), ['uuid' => wp_generate_uuid4(), 'name' => 'Short link verification', 'code' => 'qa-short-' . $suffix, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $channel_id = (int) $wpdb->insert_id;
    $assert($channel_id > 0, 'Disposable channel created');
    $generator = new ReflectionMethod(Portal::class, 'unique_token');
    $token = $generator->invoke(Portal::instance());
    $assert((bool) preg_match('/^[A-Za-z0-9]{6}$/', $token), 'Generated token has six alphanumeric characters');
    $url = Attribution::link_url($token);
    $assert($url === 'https://trovesia.com/s/' . $token . '/', 'Share URL has no www and uses /s/');
    $wpdb->insert(Database::table('campaigns'), ['channel_id' => $channel_id, 'token' => $token, 'name' => 'Disposable link', 'target_type' => 'shop', 'target_id' => 0, 'status' => 'active', 'created_by' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $campaign_id = (int) $wpdb->insert_id;
    $assert($campaign_id > 0, 'Disposable six-character link created');

    $root_response = $request($url, false);
    $assert(wp_remote_retrieve_response_code($root_response) === 301 && wp_remote_retrieve_header($root_response, 'location') === 'https://www.trovesia.com/s/' . $token . '/', 'Root domain forwards intact to canonical tracking host');

    $response = $request('http://nginx/s/' . $token . '/');
    $assert(wp_remote_retrieve_response_code($response) === 302 && wp_remote_retrieve_header($response, 'location') === wc_get_page_permalink('shop'), 'Six-character route redirects to selected destination');
    $assert(wp_remote_retrieve_header($response, 'x-trovesia-cache') === 'BYPASS', 'Tracking route bypasses page cache');
    $assert(str_contains(wp_remote_retrieve_header($response, 'cache-control'), 'no-store'), 'Tracking response forbids caching');
    $cookies = wp_remote_retrieve_cookies($response);
    $visit_cookie = null;
    foreach ($cookies as $cookie) {
        if ($cookie->name === 'trovesia_channel_visit') {
            $visit_cookie = $cookie;
        }
    }
    $assert($visit_cookie !== null && (bool) preg_match('/^[a-f0-9]{64}$/', $visit_cookie->value), 'Visit attribution cookie issued');
    $_COOKIE['trovesia_channel_visit'] = $visit_cookie->value;
    $visit = Attribution::current_visit();
    $assert($visit !== null && (int) $visit->channel_id === $channel_id && (int) $visit->campaign_id === $campaign_id, 'Visit belongs to the correct channel and link');
    $order = new WC_Order();
    Order_Attribution::instance()->capture($order);
    $assert((int) $order->get_meta(Order_Attribution::CHANNEL_META) === $channel_id && (int) $order->get_meta(Order_Attribution::CAMPAIGN_META) === $campaign_id && $order->get_id() === 0, 'Unsaved order receives correct attribution without creating an order');

    $wpdb->update(Database::table('campaigns'), ['status' => 'paused'], ['id' => $campaign_id]);
    $response = $request('http://nginx/s/' . $token . '/');
    $assert(wp_remote_retrieve_header($response, 'location') === home_url('/'), 'Paused short link safely falls back to homepage');
    $assert(Attribution::current_visit() === null, 'Paused link cannot supply a new attribution');
    $wpdb->delete(Database::table('campaigns'), ['id' => $campaign_id]);
    $response = $request('http://nginx/s/' . $token . '/');
    $assert(wp_remote_retrieve_header($response, 'location') === home_url('/'), 'Removed short link safely falls back to homepage');
} finally {
    if ($channel_id > 0) {
        $wpdb->delete(Database::table('visits'), ['channel_id' => $channel_id]);
        $wpdb->delete(Database::table('campaigns'), ['channel_id' => $channel_id]);
        $wpdb->delete(Database::table('channels'), ['id' => $channel_id]);
    }
    if ($old_cookie === null) {
        unset($_COOKIE['trovesia_channel_visit']);
    } else {
        $_COOKIE['trovesia_channel_visit'] = $old_cookie;
    }
}

echo wp_json_encode(['version' => TROVESIA_CHANNEL_VERSION, 'passed' => count($checks), 'checks' => $checks, 'fixtures_cleaned' => true], JSON_PRETTY_PRINT) . PHP_EOL;
