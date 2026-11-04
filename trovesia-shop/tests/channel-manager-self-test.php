<?php
/** Disposable account/binding regressions. No real users or orders are edited. */
use Trovesia\Channel\Admin;
use Trovesia\Channel\Database;
if (! defined('WP_CLI') || ! WP_CLI) { exit(1); }
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$suffix = bin2hex(random_bytes(7));
$users = []; $channels = []; $checks = [];
$old_user = get_current_user_id(); $old_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
$mail_filter = static fn () => true;
add_filter('pre_wp_mail', $mail_filter);
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) { throw new RuntimeException('FAILED: ' . $name); }
    $checks[] = $name;
};
$channel = static function (string $key) use ($wpdb, &$channels, $suffix): int {
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(Database::table('channels'), ['uuid' => wp_generate_uuid4(), 'name' => 'Manager regression', 'code' => 'qa-manager-' . $suffix . '-' . $key, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) { throw new RuntimeException('Channel fixture failed'); }
    $channels[] = $id;
    return $id;
};
try {
    $a = $channel('a'); $b = $channel('b');
    $upsert = new ReflectionMethod(Admin::class, 'upsert_manager');
    $login = 'qa_manager_' . $suffix;
    $email = 'qa-manager-' . $suffix . '@example.invalid';
    $changed_email = 'qa-renamed-' . $suffix . '@example.invalid';
    $password = wp_generate_password(24);
    $result = $upsert->invoke(Admin::instance(), $a, $email, $login, $password);
    $uid = (int) email_exists($email); if ($uid > 0) { $users[] = $uid; }
    $assert($result === 'manager_created' && $uid > 0, 'Create and bind one manager');
    $binding_before = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::table('users') . ' WHERE channel_id=%d', $a), ARRAY_A);
    $count_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
    $result = $upsert->invoke(Admin::instance(), $a, $changed_email, $login, '');
    clean_user_cache($uid);
    $assert($result === 'manager_updated' && (int) email_exists($changed_email) === $uid, 'Changing email updates the original account');
    $assert(! email_exists($email), 'Old login email is no longer attached');
    $assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}") === $count_before, 'Email update does not create another WordPress user');
    $assert(get_userdata($uid)->user_login === $login && wp_check_password($password, get_userdata($uid)->user_pass, $uid), 'Username and blank-password hash remain intact');
    $assert($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Database::table('users') . ' WHERE channel_id=%d', $a), ARRAY_A) === $binding_before, 'User ID, channel ID and binding timestamp stay intact');
    $result = $upsert->invoke(Admin::instance(), $a, $changed_email, 'another_' . $suffix, '');
    $assert(is_wp_error($result) && $result->get_error_code() === 'manager_username_locked', 'Forged username edits are rejected');
    $second_email = 'qa-second-' . $suffix . '@example.invalid';
    $second = wp_create_user('qa_second_' . $suffix, wp_generate_password(24), $second_email);
    if (is_wp_error($second)) { throw new RuntimeException('Second account failed'); }
    $users[] = $second;
    $result = $upsert->invoke(Admin::instance(), $a, $second_email, $login, '');
    $assert(is_wp_error($result) && $result->get_error_code() === 'manager_email_in_use' && ! user_can($second, 'view_trovesia_channel_portal'), 'Occupied email cannot replace a manager or grant channel access');
    $assert((int) email_exists($changed_email) === $uid && (int) Database::get_user_channel($uid)->id === $a, 'Rejected edits preserve original email and channel');
    $result = $upsert->invoke(Admin::instance(), $b, $changed_email, '', '');
    $assert(is_wp_error($result) && $result->get_error_code() === 'manager_already_assigned' && (int) Database::get_user_channel($uid)->id === $a, 'Binding to another channel cannot steal an existing manager');
    $result = $upsert->invoke(Admin::instance(), $b, $second_email, '', '');
    $assert($result === 'manager_assigned' && (int) Database::get_user_channel($second)->id === $b && user_can($second, 'view_trovesia_channel_portal'), 'An unassigned existing account can be linked');
    $replacement = wp_generate_password(24);
    $result = $upsert->invoke(Admin::instance(), $a, $changed_email, $login, $replacement);
    clean_user_cache($uid);
    $assert($result === 'manager_password_updated' && wp_check_password($replacement, get_userdata($uid)->user_pass, $uid), 'Explicit password reset applies to the same manager');
    $result = $upsert->invoke(Admin::instance(), $a, $changed_email, $login, 'short');
    $assert(is_wp_error($result) && $result->get_error_code() === 'manager_password_invalid', 'Invalid password cannot modify the bound user');

    $operator = wp_create_user('qa_operator_' . $suffix, wp_generate_password(24), 'qa-operator-' . $suffix . '@example.invalid');
    if (is_wp_error($operator)) { throw new RuntimeException('Operator fixture failed'); }
    $users[] = $operator; (new WP_User($operator))->set_role('shop_manager'); wp_set_current_user($operator);
    $expiry = time() + HOUR_IN_SECONDS; $token = WP_Session_Tokens::get_instance($operator)->create($expiry);
    $auth = wp_generate_auth_cookie($operator, $expiry, 'logged_in', $token); $_COOKIE[LOGGED_IN_COOKIE] = $auth;
    $body = ['action' => 'trovesia_channel_save_channel', '_wpnonce' => wp_create_nonce('trovesia_channel_save_channel'), 'channel_id' => $a, 'name' => 'Manager regression', 'code' => Database::get_channel($a)->code, 'status' => 'active', 'manager_email' => $changed_email, 'manager_username' => $login];
    $save = static function (array $body) use ($auth): array {
        $r = wp_remote_post('http://nginx/wp-admin/admin-post.php', ['redirection' => 0, 'timeout' => 25, 'headers' => ['Host' => 'www.trovesia.com', 'X-Forwarded-Proto' => 'https', 'Cookie' => LOGGED_IN_COOKIE . '=' . $auth], 'body' => $body]);
        if (is_wp_error($r)) { throw new RuntimeException('HTTP transport failed'); }
        return ['code' => wp_remote_retrieve_response_code($r), 'location' => wp_remote_retrieve_header($r, 'location')];
    };
    $r = $save($body);
    $assert($r['code'] === 302 && str_contains($r['location'], 'manager_updated'), 'Authorized admin save updates the existing manager');
    $r = $save(array_merge($body, ['name' => 'Must not save', 'manager_email' => $second_email]));
    $assert(str_contains($r['location'], 'manager_email_in_use') && Database::get_channel($a)->name === 'Manager regression', 'Occupied email rejects the entire admin submission before saving channel details');
    $r = $save(array_merge($body, ['name' => 'Must not save', 'manager_username' => 'tampered_' . $suffix]));
    $assert(str_contains($r['location'], 'manager_username_locked') && Database::get_channel($a)->name === 'Manager regression', 'Username tampering cannot partially save channel details');
    $r = $save(array_merge($body, ['_wpnonce' => 'invalid']));
    $assert($r['code'] === 403, 'Invalid admin nonce is rejected');
    $modal = new ReflectionMethod(Admin::class, 'render_channel_modal');
    ob_start(); $modal->invoke(Admin::instance(), false, $a); $html = ob_get_clean();
    $assert((bool) preg_match('/id="manager-username"[^>]*readonly/', $html) && str_contains($html, esc_attr($changed_email)), 'Edit modal shows the saved email and locks the current username');
    ob_start(); $modal->invoke(Admin::instance(), false, 0); $html = ob_get_clean();
    $assert(! preg_match('/id="manager-username"[^>]*readonly/', $html), 'New account username remains editable');
    $assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Database::table('users') . ' WHERE channel_id IN (%d,%d)', $a, $b)) === 2, 'Two channels retain exactly two bindings after all edits');
} finally {
    foreach ($channels as $cid) { $wpdb->delete(Database::table('users'), ['channel_id' => $cid]); $wpdb->delete(Database::table('audit_log'), ['object_type' => 'channel', 'object_id' => $cid]); $wpdb->delete(Database::table('channels'), ['id' => $cid]); }
    foreach ($users as $uid) { wp_delete_user($uid); }
    wp_set_current_user($old_user);
    if ($old_cookie === null) { unset($_COOKIE[LOGGED_IN_COOKIE]); } else { $_COOKIE[LOGGED_IN_COOKIE] = $old_cookie; }
    remove_filter('pre_wp_mail', $mail_filter);
}
echo wp_json_encode(['passed' => count($checks), 'checks' => $checks, 'fixtures_cleaned' => true], JSON_PRETTY_PRINT) . PHP_EOL;
