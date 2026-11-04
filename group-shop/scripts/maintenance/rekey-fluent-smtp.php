<?php
/** Root CLI only: re-encrypt existing FluentSMTP values after authentication-salt rotation. */
if (! defined('WP_CLI') || ! WP_CLI || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    WP_CLI::error('Root CLI required.');
}
$source = file_get_contents('/run/trovesia-pre-rotation-wp-config.php');
if (! is_string($source) || ! function_exists('fluentMailEncryptDecrypt')) {
    WP_CLI::error('Private pre-rotation config and FluentSMTP are required.');
}
// Tokenize the old config as data, never include/evaluate incident evidence.
$tokens = array_values(array_filter(token_get_all($source), static fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
$decode = static function (string $literal): string {
    if ($literal[0] !== "'") {
        throw new RuntimeException('Expected a single-quoted configuration literal');
    }
    $inside = substr($literal, 1, -1);
    $decoded = '';
    for ($i = 0; $i < strlen($inside); $i++) {
        if ($inside[$i] === '\\' && isset($inside[$i + 1]) && in_array($inside[$i + 1], ['\\', "'"], true)) {
            $decoded .= $inside[++$i];
        } else {
            $decoded .= $inside[$i];
        }
    }
    return $decoded;
};
$old = [];
foreach ($tokens as $i => $token) {
    if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'define' || ($tokens[$i + 1] ?? null) !== '(') {
        continue;
    }
    $depth = 1;
    $literals = [];
    for ($j = $i + 2; isset($tokens[$j]) && $depth; $j++) {
        $part = $tokens[$j];
        if ($part === '(') {
            $depth++;
        } elseif ($part === ')') {
            $depth--;
        } elseif (is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literals[] = $decode($part[1]);
        }
    }
    if (in_array($literals[0] ?? '', ['LOGGED_IN_KEY', 'LOGGED_IN_SALT'], true) && count($literals) >= 2) {
        $old[$literals[0]] = end($literals);
    }
}
if (count($old) !== 2 || strlen($old['LOGGED_IN_KEY']) < 32 || strlen($old['LOGGED_IN_SALT']) < 32) {
    WP_CLI::error('Could not safely recover old encryption inputs from private evidence.');
}
$decrypt = static function (string $value) use ($old) {
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= 16) {
        return false;
    }
    $plain = @openssl_decrypt(substr($raw, 16), 'aes-256-ctr', $old['LOGGED_IN_KEY'], 0, substr($raw, 0, 16));
    $salt = $old['LOGGED_IN_SALT'];
    return is_string($plain) && str_ends_with($plain, $salt) ? substr($plain, 0, -strlen($salt)) : false;
};
$settings = get_option('fluentmail-settings');
if (! is_array($settings) || ! isset($settings['test']) || $decrypt($settings['test']) !== 'test') {
    WP_CLI::error('Old key did not match the FluentSMTP verification sentinel; nothing changed.');
}
$backup = '/reports/fluent-settings-before-rekey-' . gmdate('Ymd\THis\Z') . '.json';
if (file_put_contents($backup, wp_json_encode($settings)) === false || ! chmod($backup, 0600)) {
    WP_CLI::error('Private rollback snapshot could not be saved; nothing changed.');
}
$count = 0;
$migrate = static function ($value) use (&$migrate, &$count, $decrypt) {
    if (is_array($value)) {
        return array_map($migrate, $value);
    }
    if (! is_string($value) || ($plain = $decrypt($value)) === false) {
        return $value;
    }
    $encrypted = fluentMailEncryptDecrypt($plain, 'e');
    if (! is_string($encrypted) || fluentMailEncryptDecrypt($encrypted, 'd') !== $plain) {
        throw new RuntimeException('New encryption round-trip failed; nothing changed');
    }
    $count++;
    return $encrypted;
};
$updated = $migrate($settings);
if ($count < 3 || fluentMailEncryptDecrypt($updated['test'], 'd') !== 'test') {
    WP_CLI::error('Insufficient verified encryption fields; nothing changed.');
}
update_option('fluentmail-settings', $updated);
WP_CLI::success('Re-encrypted ' . $count . ' existing mail configuration values; new login salts retained. No credential values printed.');
