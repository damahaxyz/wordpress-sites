<?php
/** WP-CLI read-only policy checks. Does not send mail or authenticate a user. */
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$errors = [];
if (! function_exists('trovesia_security_authenticate') || ! defined('DISALLOW_FILE_MODS') || ! DISALLOW_FILE_MODS || ! DISALLOW_FILE_EDIT) {
    $errors[] = 'Must-use security policy/file modification lock is missing.';
}
if (apply_filters('file_mod_allowed', true, 'install_plugins')) {
    $errors[] = 'Ordinary CLI/web context can modify code.';
}
if (is_plugin_active('two-factor/two-factor.php') && ! class_exists('Two_Factor_Core')) {
    $errors[] = 'The enabled Two Factor plugin did not load.';
}
if (function_exists('trovesia_security_admin_source_allowed') || function_exists('trovesia_security_admin_policy')
    || file_exists(__DIR__ . '/admin-access.json')) {
    $errors[] = 'Retired server-side administrator IP policy is still present.';
}
$allowlist = json_decode(file_get_contents(__DIR__ . '/plugin-allowlist.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys(get_plugins()) as $plugin) {
    $slug = str_contains($plugin, '/') ? explode('/', $plugin)[0] : pathinfo($plugin, PATHINFO_FILENAME);
    if (! in_array($slug, $allowlist, true)) {
        $errors[] = 'Unapproved plugin: ' . $slug;
    }
}
$admin_count = 0;
foreach (get_users() as $user) {
    if (! user_can($user, 'manage_options')) {
        continue;
    }
    $admin_count++;
}
if ($admin_count === 0) {
    $errors[] = 'No administrator found.';
}
$mail_settings = get_option('fluentmail-settings');
if (is_array($mail_settings) && ! empty($mail_settings['use_encrypt'])
    && (! function_exists('fluentMailEncryptDecrypt') || empty($mail_settings['test']) || fluentMailEncryptDecrypt($mail_settings['test'], 'd') !== 'test')) {
    $errors[] = 'Encrypted mail settings cannot be decrypted; repair before relying on email MFA/alerts.';
}
if ($errors) {
    WP_CLI::error(implode("\n", $errors));
}
WP_CLI::success('MU policy, code lock, plugin allowlist and mail encryption verified. Administrator IP rules: Cloudflare only. 2FA: user-controlled. Administrators: ' . $admin_count);
