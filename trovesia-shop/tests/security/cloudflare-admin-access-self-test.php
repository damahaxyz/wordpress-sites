<?php
/** Root-operated checks against the private gateway. Never prints cookies/nonces. */
$deny = '192.0.2.254';
$checks = 0;
$request = static function (string $path, string $ip, int $status, array $body = [], array $extra_headers = []) use (&$checks): void {
    $response = wp_remote_request('http://nginx' . $path, [
        'method' => $body === [] ? 'GET' : 'POST',
        'redirection' => 0,
        'timeout' => 20,
        'headers' => array_merge(['Host' => 'www.trovesia.com', 'X-Forwarded-Proto' => 'https', 'X-Real-IP' => $ip], $extra_headers),
        'body' => $body,
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== $status) {
        throw new RuntimeException('Unexpected status for ' . strtok($path, '?') . ': expected ' . $status . ', got ' . (is_wp_error($response) ? 'transport error' : wp_remote_retrieve_response_code($response)));
    }
    $checks++;
};
$request('/wp-login.php', $deny, 200);
$request('/wp-login.php', '2001:db8::1', 200);
$request('/wp-login.php?action=rp', $deny, 302);
$request('/wp-login.php?action=logout&_wpnonce=' . rawurlencode(wp_create_nonce('log-out')), $deny, 302);
$request('/wp-admin/admin-ajax.php', $deny, 401, [
    'action' => 'trovesia_account_login', 'nonce' => wp_create_nonce('trovesia_account_modal'),
    'login' => 'trovesia-nonexistent-' . bin2hex(random_bytes(8)), 'password' => bin2hex(random_bytes(24)),
]);
$request('/channel-portal/', $deny, 200);
$request('/wp-json/wc/store/v1/cart', $deny, 200);

// A disposable administrator session tests cookie-authenticated AJAX/REST paths.
// It is destroyed in finally; no existing session is changed.
$admin = get_user_by('login', 'damaha');
$sessions = WP_Session_Tokens::get_instance($admin->ID);
$expires = time() + 300;
$token = $sessions->create($expires);
$old_user = get_current_user_id();
$old_cookie = $_COOKIE[LOGGED_IN_COOKIE] ?? null;
try {
    $cookie = wp_generate_auth_cookie($admin->ID, $expires, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
    wp_set_current_user($admin->ID);
    $nonce = wp_create_nonce('wp_rest');
    $cookies = LOGGED_IN_COOKIE . '=' . $cookie
        . '; ' . SECURE_AUTH_COOKIE . '=' . wp_generate_auth_cookie($admin->ID, $expires, 'secure_auth', $token)
        . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie($admin->ID, $expires, 'auth', $token);
    $headers = ['Cookie' => $cookies, 'X-WP-Nonce' => $nonce];
    $request('/wp-admin/index.php', $deny, 200, [], $headers);
    $request('/wp-admin/admin-ajax.php', $deny, 200, [
        'action' => 'heartbeat', '_nonce' => wp_create_nonce('heartbeat-nonce'),
    ], $headers);
    $request('/wp-json/wp/v2/users/me', $deny, 200, [], $headers);
    $request('/wp-json/wp/v2/users/me', '2001:db8::1', 200, [], $headers);
} finally {
    $sessions->destroy($token);
    if ($old_cookie === null) {
        unset($_COOKIE[LOGGED_IN_COOKIE]);
    } else {
        $_COOKIE[LOGGED_IN_COOKIE] = $old_cookie;
    }
    wp_set_current_user($old_user);
}
if (function_exists('trovesia_security_admin_source_allowed') || function_exists('trovesia_security_admin_policy')) {
    throw new RuntimeException('Server-side IP policy was not removed');
}
WP_CLI::success($checks . ' real private-gateway HTTP checks passed: login/backend/AJAX/REST no longer use a PHP IP whitelist; recovery, logout, channel and cart still work. Public Cloudflare blocking must be checked separately.');
