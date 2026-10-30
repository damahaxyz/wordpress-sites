<?php
/** WP-CLI integration checks using disposable accounts and the private HTTP gateway. */
use Trovesia\Channel\Admin;
use Trovesia\Channel\Database;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$suffix = bin2hex(random_bytes(7));
$user_ids = [];
$channel_ids = [];
$checks = [];
$mail_count = 0;
$old_user = get_current_user_id();
$old_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
$mail_filter = static function () use (&$mail_count) { $mail_count++; return true; };
add_filter('pre_wp_mail', $mail_filter);
$assert = static function (bool $condition, string $name) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException('FAILED: ' . $name);
    }
    $checks[] = $name;
};
$make_channel = static function (string $suffix) use ($wpdb, &$channel_ids): int {
    $wpdb->insert(Database::table('channels'), ['uuid' => wp_generate_uuid4(), 'name' => 'Portal preview', 'code' => 'qa-' . $suffix, 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) { throw new RuntimeException('Fixture channel creation failed'); }
    $channel_ids[] = $id;
    return $id;
};
$identity = static function (int $id): array {
    clean_user_cache($id);
    $expiry = time() + HOUR_IN_SECONDS;
    $token = WP_Session_Tokens::get_instance($id)->create($expiry);
    $cookie = wp_generate_auth_cookie($id, $expiry, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    wp_set_current_user($id);
    return ['token' => $token, 'nonce' => wp_create_nonce('trovesia_channel_change_password'), 'cookies' => LOGGED_IN_COOKIE . '=' . $cookie . '; ' . SECURE_AUTH_COOKIE . '=' . wp_generate_auth_cookie($id, $expiry, 'secure_auth', $token)];
};
$request = static function (?array $who, array $fields = [], string $method = 'POST', string $path = '/wp-admin/admin-ajax.php'): array {
    $body = array_merge(['action' => 'trovesia_channel_change_password', 'nonce' => $who['nonce'] ?? ''], $fields);
    if ($method === 'GET' && $path === '/wp-admin/admin-ajax.php') { $path .= '?action=trovesia_channel_change_password'; }
    $response = wp_remote_request('http://nginx' . $path, [
        'method' => $method, 'redirection' => 0, 'timeout' => 25,
        'headers' => ['Host' => 'www.trovesia.com', 'X-Forwarded-Proto' => 'https', 'X-Real-IP' => '192.0.2.254', 'Cookie' => $who['cookies'] ?? ''],
        'body' => $method === 'POST' ? $body : null,
    ]);
    if (is_wp_error($response)) { throw new RuntimeException('HTTP transport failed'); }
    return ['status' => wp_remote_retrieve_response_code($response), 'json' => json_decode(wp_remote_retrieve_body($response), true), 'body' => wp_remote_retrieve_body($response), 'cookies' => wp_remote_retrieve_cookies($response)];
};

try {
    $channel_id = $make_channel($suffix);
    $upsert = new ReflectionMethod(Admin::class, 'upsert_manager');
    $email = 'qa-channel-' . $suffix . '@example.invalid';
    foreach (['' => 'manager_password_required', '12345' => 'manager_password_invalid'] as $password => $code) {
        $result = $upsert->invoke(Admin::instance(), $channel_id, $email, 'qa_channel_' . $suffix, (string) $password);
        $assert(is_wp_error($result) && $result->get_error_code() === $code && ! email_exists($email), 'New account rejects ' . ($password === '' ? 'missing' : 'five-character') . ' initial password');
    }
    $initial = bin2hex(random_bytes(3)); // Exactly six characters, never printed.
    $result = $upsert->invoke(Admin::instance(), $channel_id, $email, 'qa_channel_' . $suffix, $initial);
    $user_id = (int) email_exists($email);
    if ($user_id > 0) { $user_ids[] = $user_id; }
    $assert($result === 'manager_created' && $user_id > 0, 'New account accepts six-character initial password');
    $user = get_userdata($user_id);
    $assert(wp_check_password($initial, $user->user_pass, $user_id), 'Initial password can authenticate');
    $assert($mail_count === 0 && $user->user_activation_key === '', 'No account setup mail or reset key generated');
    $result = $upsert->invoke(Admin::instance(), $channel_id, $email, '', '');
    clean_user_cache($user_id);
    $assert($result === 'manager_assigned' && wp_check_password($initial, get_userdata($user_id)->user_pass, $user_id), 'Editing with blank password preserves existing password');
    $manager = $identity($user_id);
    $other_token = WP_Session_Tokens::get_instance($user_id)->create(time() + HOUR_IN_SECONDS);
    $customer_id = wp_create_user('qa_customer_' . $suffix, wp_generate_password(24), 'qa-customer-' . $suffix . '@example.invalid');
    if (is_wp_error($customer_id)) { throw new RuntimeException('Fixture customer creation failed'); }
    $user_ids[] = $customer_id;
    $customer = $identity($customer_id);

    $assert($request(null)['status'] === 401, 'Anonymous password change rejected');
    $assert($request($manager, [], 'GET')['status'] === 405, 'GET cannot change a password');
    $assert($request($manager, ['nonce' => 'invalid'])['status'] === 403, 'Invalid CSRF nonce rejected');
    $assert($request($customer)['status'] === 403, 'Ordinary customer cannot use channel password endpoint');
    $wpdb->update(Database::table('channels'), ['status' => 'archived'], ['id' => $channel_id]);
    $assert($request($manager)['status'] === 403, 'Archived channel cannot change password');
    $wpdb->update(Database::table('channels'), ['status' => 'active'], ['id' => $channel_id]);
    $new = "A'&\\7!";
    $valid = ['current_password' => $initial, 'new_password' => $new, 'confirm_password' => $new];
    $assert($request($manager, array_merge($valid, ['current_password' => 'wrong']))['status'] === 400, 'Incorrect current password rejected');
    $assert($request($manager, array_merge($valid, ['confirm_password' => 'different']))['status'] === 400, 'Mismatched confirmation rejected');
    $assert($request($manager, array_merge($valid, ['new_password' => '12345', 'confirm_password' => '12345']))['status'] === 400, 'Five-character new password rejected');
    $assert($request($manager, ['current_password' => $initial, 'new_password' => $initial, 'confirm_password' => $initial])['status'] === 400, 'Unchanged password rejected');
    $assert($request($manager, array_merge($valid, ['current_password' => ['invalid']]))['status'] === 400, 'Array password input rejected');
    $portal = $request($manager, [], 'GET', '/channel-portal/');
    $assert($portal['status'] === 200 && str_contains($portal['body'], 'data-channel-password-open') && str_contains($portal['body'], '<dialog '), 'Signed-in portal renders reset button and dialog');
    $response = $request($manager, array_merge($valid, ['user_id' => $customer_id]));
    $assert($response['status'] === 200 && ($response['json']['success'] ?? false), 'Six-character password with quotes and slash updates successfully');
    clean_user_cache($user_id);
    $hash = get_userdata($user_id)->user_pass;
    $assert(! wp_check_password($initial, $hash, $user_id) && wp_check_password($new, $hash, $user_id), 'Old password rejected and new password accepted');
    clean_user_cache($customer_id);
    $assert(! wp_check_password($new, get_userdata($customer_id)->user_pass, $customer_id), 'Posted target user ID cannot change another account');
    $sessions = WP_Session_Tokens::get_instance($user_id);
    $assert($sessions->verify($manager['token']) && ! $sessions->verify($other_token), 'Current session kept and other sessions revoked');
    $assert(count($response['cookies']) > 0, 'Updated authentication cookies returned');
    $assert(! str_contains($request($manager, [], 'GET', '/channel-portal/')['body'], 'data-channel-password-open'), 'Old pre-change authentication cookie no longer authenticates');
    $new_cookie_header = implode('; ', array_map(static fn ($cookie) => $cookie->name . '=' . $cookie->value, $response['cookies']));
    $manager['cookies'] = $new_cookie_header;
    $assert(str_contains($request($manager, [], 'GET', '/channel-portal/')['body'], 'data-channel-password-open'), 'Browser remains signed in with returned cookies');
    $second = bin2hex(random_bytes(3));
    $response = $request($manager, ['current_password' => $new, 'new_password' => $second, 'confirm_password' => $second]);
    $assert($response['status'] === 200 && ($response['json']['success'] ?? false), 'Dialog can submit again after changing password without page refresh');
    $manager['cookies'] = implode('; ', array_map(static fn ($cookie) => $cookie->name . '=' . $cookie->value, $response['cookies']));
    for ($i = 0; $i < 5; $i++) {
        $assert($request($manager, ['current_password' => 'wrong', 'new_password' => 'abcdef', 'confirm_password' => 'abcdef'])['status'] === 400, 'Incorrect old password rate-limit attempt ' . ($i + 1));
    }
    $assert($request($manager, ['current_password' => $second, 'new_password' => 'abcdef', 'confirm_password' => 'abcdef'])['status'] === 429, 'Rate limit rejects sixth attempt');
    delete_transient('trovesia_channel_password_' . $user_id);
    $assert($mail_count === 0, 'Account creation did not send email');
} finally {
    foreach ($user_ids as $id) {
        delete_transient('trovesia_channel_password_' . $id);
        $wpdb->delete(Database::table('users'), ['user_id' => $id]);
        wp_delete_user($id);
    }
    foreach ($channel_ids as $id) {
        $wpdb->delete(Database::table('audit_log'), ['object_type' => 'channel', 'object_id' => $id]);
        $wpdb->delete(Database::table('channels'), ['id' => $id]);
    }
    remove_filter('pre_wp_mail', $mail_filter);
    if ($old_cookie === null) { unset($_COOKIE[LOGGED_IN_COOKIE]); } else { $_COOKIE[LOGGED_IN_COOKIE] = $old_cookie; }
    wp_set_current_user($old_user);
}
echo wp_json_encode(['passed' => count($checks), 'checks' => $checks, 'fixtures_removed' => true], JSON_PRETTY_PRINT) . PHP_EOL;
