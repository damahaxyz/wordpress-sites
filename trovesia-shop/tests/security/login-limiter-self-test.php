<?php
/** Exercise the public password-login limiter without a real account or emails. */
$login = 'trovesia-security-probe-' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24));
$keys = trovesia_security_login_keys($login);
try {
    if (apply_filters('file_mod_allowed', true, 'install_plugins')) {
        throw new RuntimeException('Code modification unexpectedly allowed');
    }
    for ($i = 0; $i < 7; $i++) {
        $response = wp_remote_post('http://nginx/wp-login.php', [
            'timeout' => 20,
            'redirection' => 0,
            'body' => ['log' => $login, 'pwd' => $password, 'testcookie' => '1'],
            'headers' => [
                'Cookie' => 'wordpress_test_cookie=WP%20Cookie%20check',
                'Host' => 'www.trovesia.com',
                'X-Forwarded-Proto' => 'https',
                'X-Real-IP' => '192.0.2.254',
            ],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException('Login endpoint request failed');
        }
        $limited = str_contains(wp_remote_retrieve_body($response), 'Too many sign-in attempts');
        if (($i < 6 && $limited) || ($i === 6 && ! $limited)) {
            throw new RuntimeException('Unexpected limiter behavior at request ' . ($i + 1));
        }
    }
    if (trovesia_security_count($keys['account']) !== 6) {
        throw new RuntimeException('Failed-login audit counter differs from expected six attempts');
    }
    WP_CLI::success('Native login blocks attempt 7 after six failures without a server-side IP whitelist; code modification denied. No real account used.');
} finally {
    // The source-specific key is private to this disposable login and expires in 15 min.
    wp_cache_delete($keys['account'], 'trovesia_security');
    delete_transient($keys['account']);
}
