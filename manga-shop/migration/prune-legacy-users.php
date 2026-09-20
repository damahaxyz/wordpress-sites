<?php
/**
 * Delete legacy customer/subscriber accounts that have no WooCommerce orders.
 *
 * A user is preserved when an order references its user ID or when its email
 * matches an order billing email. All privileged and unknown roles are also
 * preserved. Run without --execute for a dry run.
 */

if (! defined('ABSPATH')) {
    $_SERVER['HTTP_HOST'] = 'www.mangaproduct.com';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SERVER_PORT'] = '443';

    require '/var/www/html/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$evalArgs = isset($args) && is_array($args) ? $args : [];
$execute = in_array('--execute', $argv ?? [], true) || in_array('execute', $evalArgs, true);
$allowedRoles = ['customer', 'subscriber'];
$administratorIds = get_users([
    'role'   => 'administrator',
    'fields' => 'ID',
]);
$reassignId = isset($administratorIds[0]) ? (int) $administratorIds[0] : 0;

if ($reassignId <= 0) {
    throw new RuntimeException('No administrator is available for content reassignment.');
}

$candidateIds = array_map('intval', $wpdb->get_col(
    "SELECT DISTINCT u.ID FROM {$wpdb->users} u "
    . "INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = '{$wpdb->prefix}capabilities' "
    . "WHERE (cap.meta_value LIKE '%\"customer\"%' OR cap.meta_value LIKE '%\"subscriber\"%') "
    . "AND cap.meta_value NOT LIKE '%\"administrator\"%' "
    . "AND cap.meta_value NOT LIKE '%\"shop_manager\"%' "
    . "AND cap.meta_value NOT LIKE '%\"editor\"%' "
    . "AND cap.meta_value NOT LIKE '%\"author\"%' "
    . "AND cap.meta_value NOT LIKE '%\"contributor\"%' "
    . "AND NOT EXISTS ("
    . "SELECT 1 FROM {$wpdb->postmeta} cu "
    . "INNER JOIN {$wpdb->posts} orders ON orders.ID = cu.post_id AND orders.post_type = 'shop_order' "
    . "WHERE cu.meta_key = '_customer_user' AND CAST(cu.meta_value AS UNSIGNED) = u.ID"
    . ') '
    . "AND NOT EXISTS ("
    . "SELECT 1 FROM {$wpdb->postmeta} email "
    . "INNER JOIN {$wpdb->posts} email_orders ON email_orders.ID = email.post_id AND email_orders.post_type = 'shop_order' "
    . "WHERE email.meta_key = '_billing_email' "
    . 'AND LOWER(TRIM(email.meta_value)) = LOWER(TRIM(u.user_email))'
    . ') '
    . 'ORDER BY u.ID'
));

if (! $execute) {
    echo json_encode([
        'mode'             => 'dry-run',
        'candidate_count'  => count($candidateIds),
        'reassign_user_id' => $reassignId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$deleted = 0;
$skipped = 0;
$failed = [];

foreach ($candidateIds as $candidateId) {
    if ($candidateId === $reassignId) {
        $skipped++;
        continue;
    }

    $user = get_userdata($candidateId);
    if (! $user instanceof WP_User) {
        $skipped++;
        continue;
    }

    $roles = array_values($user->roles);
    if ($roles === [] || array_diff($roles, $allowedRoles) !== []) {
        $skipped++;
        continue;
    }

    // Recheck immediately before deletion in case an order was created after
    // the initial candidate query started.
    $hasOrder = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$wpdb->posts} orders "
        . "LEFT JOIN {$wpdb->postmeta} cu ON cu.post_id = orders.ID AND cu.meta_key = '_customer_user' "
        . "LEFT JOIN {$wpdb->postmeta} email ON email.post_id = orders.ID AND email.meta_key = '_billing_email' "
        . "WHERE orders.post_type = 'shop_order' "
        . 'AND (CAST(COALESCE(cu.meta_value, 0) AS UNSIGNED) = %d '
        . 'OR LOWER(TRIM(email.meta_value)) = LOWER(TRIM(%s))) LIMIT 1',
        $candidateId,
        $user->user_email
    ));

    if ($hasOrder) {
        $skipped++;
        continue;
    }

    if (wp_delete_user($candidateId, $reassignId)) {
        $deleted++;
    } else {
        $failed[] = $candidateId;
    }

    if (($deleted + $skipped + count($failed)) % 250 === 0) {
        echo 'Processed ' . ($deleted + $skipped + count($failed)) . '/' . count($candidateIds) . PHP_EOL;
    }
}

$summary = [
    'mode'             => 'execute',
    'candidate_count'  => count($candidateIds),
    'deleted_count'    => $deleted,
    'skipped_count'    => $skipped,
    'failed_user_ids'  => $failed,
    'reassign_user_id' => $reassignId,
];

echo 'PRUNE_COMPLETE ' . json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

if ($failed !== []) {
    exit(2);
}
