<?php
/** WP-CLI checks with disposable channels, conversion rows and a shop-manager account. */
use Trovesia\Channel\Admin;
use Trovesia\Channel\Database;
use Trovesia\Channel\Portal;
if (! defined('WP_CLI') || ! WP_CLI) { exit(1); }
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$uid = 0; $cid = 0; $checks = [];
$old_get = $_GET; $old_user = get_current_user_id(); $old_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
add_filter('pre_wp_mail', '__return_true');
$assert = static function (bool $ok, string $name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAILED: ' . $name); } $checks[] = $name; };
try {
    $suffix = bin2hex(random_bytes(7));
    $uid = wp_create_user('qa_dash_' . $suffix, wp_generate_password(32), 'qa-dash-' . $suffix . '@example.invalid');
    if (is_wp_error($uid)) { $uid = 0; throw new RuntimeException('Fixture user failed'); }
    $user = new WP_User($uid); $user->set_role('shop_manager'); wp_set_current_user($uid);
    $expiry = time() + HOUR_IN_SECONDS;
    $token = WP_Session_Tokens::get_instance($uid)->create($expiry);
    $auth = wp_generate_auth_cookie($uid, $expiry, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $auth;
    $fields = ['action' => 'trovesia_channel_save_channel', '_wpnonce' => wp_create_nonce('trovesia_channel_save_channel'), 'name' => 'Dashboard verification', 'code' => 'qa-dashboard-' . $suffix, 'status' => 'active'];
    $save = static function (array $body) use ($auth): array {
        $r = wp_remote_post('http://nginx/wp-admin/admin-post.php', ['redirection' => 0, 'timeout' => 25, 'headers' => ['Host' => 'www.trovesia.com', 'X-Forwarded-Proto' => 'https', 'Cookie' => LOGGED_IN_COOKIE . '=' . $auth], 'body' => $body]);
        if (is_wp_error($r)) { throw new RuntimeException('HTTP transport failed'); }
        return ['code' => wp_remote_retrieve_response_code($r), 'location' => wp_remote_retrieve_header($r, 'location')];
    };
    $r = $save($fields);
    $cid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Database::table('channels') . ' WHERE code=%s', $fields['code']));
    $assert($r['code'] === 302 && $cid > 0, 'Create channel through authorized admin handler');
    $assert((float) Database::get_channel($cid)->commission_rate === 0.2, 'New channel defaults to 0.2');
    $fields['channel_id'] = $cid; $fields['commission_rate'] = '0.3375'; $save($fields);
    $assert((float) Database::get_channel($cid)->commission_rate === 0.3375, 'Commission persists four decimal places');
    foreach (['-0.2', '1.01', '20', 'abc', '0.12345', ['0.2']] as $invalid) {
        $r = $save(array_merge($fields, ['commission_rate' => $invalid]));
        $assert(str_contains($r['location'], 'invalid_commission_rate') && (float) Database::get_channel($cid)->commission_rate === 0.3375, 'Invalid commission rejected: ' . (is_array($invalid) ? 'array' : $invalid));
    }
    foreach (['0', '1'] as $boundary) {
        $save(array_merge($fields, ['commission_rate' => $boundary]));
        $assert((float) Database::get_channel($cid)->commission_rate === (float) $boundary, 'Commission accepts boundary ' . $boundary);
    }
    $save($fields);
    $without_rate = $fields; unset($without_rate['commission_rate']); $save($without_rate);
    $assert((float) Database::get_channel($cid)->commission_rate === 0.3375, 'Older form submission preserves existing commission');
    $modal = new ReflectionMethod(Admin::class, 'render_channel_modal');
    ob_start(); $modal->invoke(Admin::instance(), false, $cid); $html = ob_get_clean();
    $assert(str_contains($html, 'name="commission_rate"') && str_contains($html, 'value="0.3375"') && str_contains($html, '0.2 means 20%'), 'Edit modal renders saved commission and ratio guidance');
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(Database::table('users'), ['user_id' => $uid, 'channel_id' => $cid, 'created_at' => $now]);
    $base = 8000000000000000000 + random_int(1, 1000000) * 10;
    foreach ([['processing', 100, 10, $now], ['refunded', 80, 80, $now], ['refunded', 50, 50, gmdate('Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS)]] as $i => $data) {
        $assert($wpdb->insert(Database::table('conversions'), ['order_id' => $base + $i, 'channel_id' => $cid, 'campaign_id' => 0, 'visit_id' => 0, 'status' => $data[0], 'currency' => 'USD', 'order_total' => $data[1], 'refund_total' => $data[2], 'created_at' => $data[3], 'updated_at' => $now]) === 1, 'Create disposable conversion ' . $i);
    }
    $stats = new ReflectionMethod(Portal::class, 'stats');
    $current = $stats->invoke(Portal::instance(), $cid, '7');
    $assert($current['refund_total'] === 90.0, 'Refunds include both partial and fully refunded orders');
    $assert($stats->invoke(Portal::instance(), $cid, 'all')['refund_total'] === 140.0, 'Refunds respect reporting range');
    $assert($current['paid_revenue'] === 100.0 && $current['paid_orders'] === 1, 'Existing paid-order calculation preserved');
    $dates = new ReflectionMethod(Portal::class, 'date_fragments');
    $today = current_datetime()->setTime(0, 0);
    $today_params = $dates->invoke(Portal::instance(), 'today')[2];
    $yesterday_params = $dates->invoke(Portal::instance(), 'yesterday')[2];
    $assert($today_params === [gmdate('Y-m-d H:i:s', $today->getTimestamp()), gmdate('Y-m-d H:i:s', $today->modify('+1 day')->getTimestamp())], 'Today boundaries follow store timezone');
    $assert($yesterday_params === [gmdate('Y-m-d H:i:s', $today->modify('-1 day')->getTimestamp()), $today_params[0]], 'Yesterday ends exactly where today starts');
    $_GET = ['tab' => 'overview', 'range' => '7']; $html = Portal::instance()->render();
    $assert(str_contains($html, 'trovesia-channel-summary') && str_contains($html, '>Refunds<') && !str_contains($html, '>Net revenue<') && !str_contains($html, '<div><span>Overview'), 'Portal renders grouped metrics and refunds without duplicate heading');
    $assert((bool) preg_match('/class="is-active"[^>]*range=7[^>]*aria-current="page"/', $html), 'Numeric reporting range is highlighted');
    $assert(str_contains($html, '>Today<') && str_contains($html, '>Yesterday<'), 'Today and Yesterday reporting options rendered');
    $link_token = (new ReflectionMethod(Portal::class, 'unique_token'))->invoke(Portal::instance());
    $wpdb->insert(Database::table('campaigns'), ['channel_id' => $cid, 'token' => $link_token, 'target_type' => 'home', 'target_id' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $_GET = ['tab' => 'links']; $html = Portal::instance()->render();
    $assert(str_contains($html, 'data-channel-copy') && str_contains($html, 'https://trovesia.com/s/' . $link_token . '/'), 'Each tracking link offers Copy beside full short URL');
} finally {
    $_GET = $old_get;
    if ($cid > 0) {
        foreach (['conversions', 'visits', 'campaigns', 'users'] as $table) { $wpdb->delete(Database::table($table), ['channel_id' => $cid]); }
        $wpdb->delete(Database::table('audit_log'), ['object_type' => 'channel', 'object_id' => $cid]);
        $wpdb->delete(Database::table('channels'), ['id' => $cid]);
    }
    if ($uid > 0) { wp_delete_user($uid); }
    wp_set_current_user($old_user);
    if ($old_cookie === null) { unset($_COOKIE[LOGGED_IN_COOKIE]); } else { $_COOKIE[LOGGED_IN_COOKIE] = $old_cookie; }
}
echo wp_json_encode(['passed' => count($checks), 'checks' => $checks, 'fixtures_cleaned' => true], JSON_PRETTY_PRINT) . PHP_EOL;
