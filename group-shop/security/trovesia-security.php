<?php
/**
 * Plugin Name: Trovesia Security Baseline (must use)
 * Description: Deployment-managed authentication, file modification policy and audit.
 * Version: 1.2.0
 */
declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

function trovesia_security_maintenance(): bool
{
    return defined('WP_CLI') && WP_CLI && getenv('TROVESIA_MAINTENANCE') === '1';
}

if (! defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}
if (! defined('DISALLOW_FILE_MODS')) {
    define('DISALLOW_FILE_MODS', ! trovesia_security_maintenance());
}
add_filter('file_mod_allowed', static fn ($allowed): bool => trovesia_security_maintenance() && (bool) $allowed, PHP_INT_MAX);
add_action('admin_init', static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'update.php'
        && ($_GET['action'] ?? '') === 'upload-plugin') {
        trovesia_security_audit('code_upload_denied', ['user_id' => get_current_user_id(), 'reason' => 'deployment_managed']);
    }
});

function trovesia_security_source(): string
{
    // The loopback-bound upstream receives X-Real-IP overwritten by the host proxy.
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $forwarded = (string) ($_SERVER['HTTP_X_REAL_IP'] ?? '');
    if (getenv('TROVESIA_TRUST_PROXY_HEADERS') === '1' && filter_var($forwarded, FILTER_VALIDATE_IP)) {
        return $forwarded;
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

// Administrator IP access is managed exclusively by Cloudflare.
// Source addresses below are used only for rate limiting and redacted audit.

function trovesia_security_audit(string $event, array $fields = []): void
{
    // Never log passwords, cookies, tokens, email addresses, request bodies or query strings.
    $allowed = array_intersect_key($fields, array_flip(['user_id', 'reason', 'plugin', 'role', 'result', 'count']));
    $request_id = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    error_log('[trovesia-security] ' . wp_json_encode(array_merge([
        'time' => gmdate('c'),
        'event' => $event,
        'actor' => substr(hash_hmac('sha256', trovesia_security_source(), wp_salt('auth')), 0, 20),
        'request_id' => preg_match('/^[a-f0-9]{32}$/', $request_id) ? $request_id : '',
        'path' => substr((string) $path, 0, 200),
    ], $allowed), JSON_UNESCAPED_SLASHES));
}

function trovesia_security_login_keys(string $login): array
{
    $login = strtolower(trim($login));
    $user = is_email($login) ? get_user_by('email', $login) : get_user_by('login', $login);
    $identity = $user instanceof WP_User ? 'user:' . $user->ID : 'login:' . $login;
    $salt = wp_salt('auth');
    return [
        'pair' => 'ts_' . hash_hmac('sha256', $identity . '|' . trovesia_security_source(), $salt),
        'account' => 'ts_' . hash_hmac('sha256', $identity, $salt),
    ];
}

function trovesia_security_count(string $key): int
{
    return wp_using_ext_object_cache()
        ? (int) wp_cache_get($key, 'trovesia_security')
        : (int) get_transient($key);
}

function trovesia_security_increment(string $key): int
{
    if (wp_using_ext_object_cache()) {
        if (wp_cache_add($key, 1, 'trovesia_security', 900)) {
            return 1;
        }
        return (int) wp_cache_incr($key, 1, 'trovesia_security');
    }
    $count = (int) get_transient($key) + 1;
    set_transient($key, $count, 900);
    return $count;
}

function trovesia_security_authenticate($user, string $login, string $password)
{
    if ($login === '' || $password === '' || (defined('WP_CLI') && WP_CLI)) {
        return $user;
    }
    $keys = trovesia_security_login_keys($login);
    if (trovesia_security_count($keys['pair']) >= 6 || trovesia_security_count($keys['account']) >= 30) {
        return new WP_Error('trovesia_login_throttled', __('Too many sign-in attempts. Please try again in 15 minutes.', 'trovesia'));
    }
    if ($user instanceof WP_User && user_can($user, 'manage_options')) {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== 'wp-login.php' || wp_doing_ajax()) {
            return new WP_Error('trovesia_admin_secure_login', sprintf(
                __('Administrators must use secure sign-in: %s', 'trovesia'), wp_login_url()
            ));
        }
    }
    return $user;
}
add_filter('authenticate', 'trovesia_security_authenticate', PHP_INT_MAX, 3);

add_action('wp_login_failed', static function (string $login, $error = null): void {
    $reason = $error instanceof WP_Error ? $error->get_error_code() : 'authentication_failed';
    if ($reason !== 'trovesia_login_throttled' && $reason !== 'trovesia_admin_secure_login') {
        foreach (trovesia_security_login_keys($login) as $key) {
            trovesia_security_increment($key);
        }
    }
    $user = is_email($login) ? get_user_by('email', $login) : get_user_by('login', $login);
    trovesia_security_audit('login_failed', ['user_id' => $user instanceof WP_User ? $user->ID : 0, 'reason' => $reason]);
}, 10, 2);

add_action('wp_login', static function (string $login, WP_User $user): void {
    if (! user_can($user, 'manage_options')) {
        foreach (trovesia_security_login_keys($login) as $key) {
            wp_cache_delete($key, 'trovesia_security');
            delete_transient($key);
        }
        trovesia_security_audit('login_success', ['user_id' => $user->ID]);
    }
}, 100, 2);
add_action('two_factor_user_authenticated', static function (WP_User $user): void {
    foreach (trovesia_security_login_keys($user->user_login) as $key) {
        wp_cache_delete($key, 'trovesia_security');
        delete_transient($key);
    }
    trovesia_security_audit('login_2fa_success', ['user_id' => $user->ID]);
});

// Two Factor manages its own optional provider selection; no forced enrollment.
add_filter('two_factor_user_api_login_enable', static fn ($enabled, $id): bool => ! user_can($id, 'manage_options') && (bool) $enabled, PHP_INT_MAX, 2);
add_filter('wp_is_application_passwords_available_for_user', static fn ($available, $user): bool => ! user_can($user, 'manage_options') && (bool) $available, PHP_INT_MAX, 2);

add_action('activated_plugin', static function (string $plugin): void {
    trovesia_security_audit('plugin_activated', ['user_id' => get_current_user_id(), 'plugin' => sanitize_text_field($plugin)]);
});
add_action('deactivated_plugin', static function (string $plugin): void {
    trovesia_security_audit('plugin_deactivated', ['user_id' => get_current_user_id(), 'plugin' => sanitize_text_field($plugin)]);
});
add_action('upgrader_process_complete', static function ($upgrader, array $options): void {
    trovesia_security_audit('code_upgrade', ['user_id' => get_current_user_id(), 'reason' => sanitize_key((string) ($options['type'] ?? 'unknown'))]);
}, 10, 2);
add_action('set_user_role', static function (int $id, string $role, array $old_roles): void {
    $privileged = user_can($id, 'manage_options');
    foreach ($old_roles as $old_role) {
        $definition = get_role($old_role);
        $privileged = $privileged || ($definition && $definition->has_cap('manage_options'));
    }
    trovesia_security_audit('role_changed', ['user_id' => $id, 'role' => sanitize_key($role), 'result' => $privileged ? 'privileged_change' : 'ordinary_change']);
}, 10, 3);
add_action('after_password_reset', static function (WP_User $user): void {
    trovesia_security_audit('password_reset', ['user_id' => $user->ID]);
});
