<?php
/** WP-CLI HTTP regression checks; all fixtures are disposable and cleaned. */
use Trovesia\Channel\Database;
use Trovesia\Channel\Portal;

if (! defined('WP_CLI') || ! WP_CLI) { exit(1); }
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$channel_ids = [];
$campaign_ids = [];
$user_ids = [];
$checks = [];
$old_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) { throw new RuntimeException('FAILED: ' . $name); }
    $checks[] = $name;
};
$request = static function (string $path, string $cookie, array $body = [], string $method = 'POST'): array {
    $r = wp_remote_request('http://nginx' . $path, ['method' => $method, 'redirection' => 0, 'timeout' => 25,
        'headers' => ['Host' => 'www.trovesia.com', 'X-Forwarded-Proto' => 'https', 'Cookie' => $cookie],
        'body' => $method === 'POST' ? $body : null]);
    if (is_wp_error($r)) { throw new RuntimeException('HTTP transport failed'); }
    return ['status' => wp_remote_retrieve_response_code($r), 'location' => wp_remote_retrieve_header($r, 'location'), 'body' => wp_remote_retrieve_body($r)];
};
$campaign_status = static function (int $id) use ($wpdb): string {
    return (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . Database::table('campaigns') . ' WHERE id=%d', $id));
};
add_filter('pre_wp_mail', '__return_true');
try {
    $suffix = bin2hex(random_bytes(7));
    $now = gmdate('Y-m-d H:i:s');
    for ($i = 0; $i < 2; $i++) {
        $assert($wpdb->insert(Database::table('channels'), ['uuid' => wp_generate_uuid4(), 'code' => 'qa-delete-' . $suffix . '-' . $i, 'name' => 'Delete link verification', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]) === 1, 'Create disposable channel ' . $i);
        $channel_ids[] = (int) $wpdb->insert_id;
    }
    $uid = wp_create_user('qa_delete_' . $suffix, wp_generate_password(32), 'qa-delete-' . $suffix . '@example.invalid');
    $assert(! is_wp_error($uid), 'Create disposable manager');
    $user_ids[] = (int) $uid;
    $user = new WP_User($uid);
    $user->set_role('trovesia_channel_manager');
    $wpdb->insert(Database::table('users'), ['user_id' => $uid, 'channel_id' => $channel_ids[0], 'created_at' => $now]);
    wp_set_current_user($uid);
    $expiry = time() + HOUR_IN_SECONDS;
    $session = WP_Session_Tokens::get_instance($uid)->create($expiry);
    $auth = wp_generate_auth_cookie($uid, $expiry, 'logged_in', $session);
    $_COOKIE[LOGGED_IN_COOKIE] = $auth;
    $cookie = LOGGED_IN_COOKIE . '=' . $auth;
    foreach (['active', 'paused', 'paused'] as $i => $status) {
        $token = (new ReflectionMethod(Portal::class, 'unique_token'))->invoke(Portal::instance());
        $assert($wpdb->insert(Database::table('campaigns'), ['channel_id' => $channel_ids[$i === 2 ? 1 : 0], 'token' => $token, 'name' => 'Fixture ' . $i, 'target_type' => 'home', 'target_id' => 0, 'status' => $status, 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]) === 1, 'Create disposable link ' . $i);
        $campaign_ids[] = (int) $wpdb->insert_id;
    }
    $fields = static function (int $id): array {
        return ['action' => 'trovesia_channel_delete_link', 'campaign_id' => $id, '_wpnonce' => wp_create_nonce('trovesia_channel_delete_link_' . $id)];
    };
    $path = '/wp-admin/admin-post.php';
    $r = $request($path, $cookie, $fields($campaign_ids[0]));
    $assert(str_contains($r['location'], 'link_delete_failed') && $campaign_status($campaign_ids[0]) === 'active', 'Active link cannot be deleted even with valid nonce');
    $r = $request($path, $cookie, $fields($campaign_ids[2]));
    $assert(str_contains($r['location'], 'link_delete_failed') && $campaign_status($campaign_ids[2]) === 'paused', 'Another channel link cannot be deleted');
    $bad = $fields($campaign_ids[1]); $bad['_wpnonce'] = 'invalid';
    $r = $request($path, $cookie, $bad);
    $assert($r['status'] === 403 && $campaign_status($campaign_ids[1]) === 'paused', 'CSRF failure cannot delete link');
    $r = $request($path . '?action=trovesia_channel_delete_link&campaign_id=' . $campaign_ids[1], $cookie, [], 'GET');
    $assert($r['status'] === 405 && $campaign_status($campaign_ids[1]) === 'paused', 'GET cannot delete link');
    $r = $request($path, '', $fields($campaign_ids[1]));
    $assert($campaign_status($campaign_ids[1]) === 'paused', 'Anonymous request cannot delete link');
    $html = $request('/channel-portal/?tab=links', $cookie, [], 'GET')['body'];
    $assert(str_contains($html, 'data-channel-delete-open data-campaign-id="' . $campaign_ids[1] . '"') && ! str_contains($html, 'data-channel-delete-open data-campaign-id="' . $campaign_ids[0] . '"'), 'Only paused rows offer delete confirmation');
    $assert(str_contains($html, 'id="trovesia-channel-delete-dialog"'), 'Confirmation dialog rendered');
    $wpdb->insert(Database::table('visits'), ['visit_token' => bin2hex(random_bytes(32)), 'visitor_token' => bin2hex(random_bytes(32)), 'channel_id' => $channel_ids[0], 'campaign_id' => $campaign_ids[1], 'landing_url' => home_url('/'), 'is_bot' => 0, 'clicked_at' => $now, 'last_seen_at' => $now]);
    $fake_order_id = 8000000000000000000 + random_int(1, 1000000);
    $wpdb->insert(Database::table('conversions'), ['order_id' => $fake_order_id, 'channel_id' => $channel_ids[0], 'campaign_id' => $campaign_ids[1], 'visit_id' => 0, 'status' => 'processing', 'currency' => 'USD', 'order_total' => 15, 'refund_total' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $stats_method = new ReflectionMethod(Portal::class, 'stats');
    $before = $stats_method->invoke(Portal::instance(), $channel_ids[0], 'all');
    $r = $request($path, $cookie, $fields($campaign_ids[1]));
    $assert($r['status'] === 302 && str_contains($r['location'], 'tab=links') && str_contains($r['location'], 'link_deleted') && $campaign_status($campaign_ids[1]) === 'deleted', 'Own paused link deleted with redirect to links tab');
    $assert($stats_method->invoke(Portal::instance(), $channel_ids[0], 'all') === $before && $before['visits'] === 1 && $before['paid_orders'] === 1, 'Historical visits and order statistics preserved');
    $token = (string) $wpdb->get_var($wpdb->prepare('SELECT token FROM ' . Database::table('campaigns') . ' WHERE id=%d', $campaign_ids[1]));
    $assert(Database::get_campaign_by_token($token)?->status === 'deleted', 'Deleted token stays reserved');
    $r = $request('/s/' . $token . '/', '', [], 'GET');
    $assert($r['status'] === 302 && $r['location'] === home_url('/'), 'Deleted link cannot track and falls back to store');
    $links = (new ReflectionMethod(Portal::class, 'links'))->invoke(Portal::instance(), $channel_ids[0]);
    $assert(count($links) === 1 && (int) $links[0]->id === $campaign_ids[0], 'Deleted link hidden from portal list');
    $nonce = wp_create_nonce('trovesia_channel_toggle_link_' . $campaign_ids[1]);
    $r = $request($path . '?action=trovesia_channel_toggle_link&campaign_id=' . $campaign_ids[1] . '&_wpnonce=' . $nonce, $cookie, [], 'GET');
    $assert($campaign_status($campaign_ids[1]) === 'deleted', 'Old activate URL cannot restore deleted link');
    $r = $request($path, $cookie, $fields($campaign_ids[1]));
    $assert(str_contains($r['location'], 'link_delete_failed'), 'Repeated delete cannot falsely succeed');
} finally {
    foreach ($channel_ids as $id) {
        foreach (['visits', 'conversions', 'campaigns', 'users'] as $table) { $wpdb->delete(Database::table($table), ['channel_id' => $id]); }
        $wpdb->delete(Database::table('channels'), ['id' => $id]);
    }
    foreach ($campaign_ids as $id) { $wpdb->delete(Database::table('audit_log'), ['object_type' => 'campaign', 'object_id' => $id]); }
    foreach ($user_ids as $id) { wp_delete_user($id); }
    wp_set_current_user(0);
    if ($old_cookie === null) { unset($_COOKIE[LOGGED_IN_COOKIE]); } else { $_COOKIE[LOGGED_IN_COOKIE] = $old_cookie; }
}
echo wp_json_encode(['passed' => count($checks), 'checks' => $checks, 'fixtures_cleaned' => true], JSON_PRETTY_PRINT) . PHP_EOL;
