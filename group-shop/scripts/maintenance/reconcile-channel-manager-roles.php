<?php
/** WP-CLI only: inspect orphan roles, or repair one explicitly selected user. */
use Trovesia\Channel\Database;
if (! defined('WP_CLI') || ! WP_CLI) { exit(1); }
global $wpdb;
$target = (int) ($args[0] ?? 0);
$apply = ($args[1] ?? '') === 'apply';
if ($apply && $target <= 0) { WP_CLI::error('Select one user ID before applying a repair.'); }
$rows = [];
foreach (get_users(['role' => 'trovesia_channel_manager']) as $user) {
    if ($target > 0 && $user->ID !== $target) { continue; }
    $bound = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Database::table('users') . ' WHERE user_id = %d', $user->ID));
    $row = ['user_id' => $user->ID, 'login' => $user->user_login, 'bindings' => $bound, 'roles_before' => $user->roles, 'changed' => false];
    if ($apply && $bound === 0) {
        // Retain the WordPress account, all other roles, passwords and business data.
        $user->remove_role('trovesia_channel_manager');
        Database::audit('orphan_manager_role_removed', 'user', $user->ID, ['reason' => 'no_channel_binding', 'retained_roles' => $user->roles]);
        $row['changed'] = true;
    }
    $row['roles_after'] = $user->roles;
    $rows[] = $row;
}
echo wp_json_encode(['apply' => $apply, 'users' => $rows], JSON_PRETTY_PRINT) . PHP_EOL;
