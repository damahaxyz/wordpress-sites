<?php
/**
 * Export legacy users and WooCommerce CPT orders without loading plugins.
 * Payment tokens are intentionally excluded.
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'www.mangaproduct.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT'] = '443';

define('SHORTINIT', true);
require '/var/www/html/wp-load.php';

global $wpdb;

/**
 * @return array<int, array<string, mixed>>
 */
function manga_order_export_rows(string $sql): array
{
    global $wpdb;

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (! is_array($rows)) {
        throw new RuntimeException($wpdb->last_error ?: 'Database query failed.');
    }

    return $rows;
}

$orders = manga_order_export_rows(
    "SELECT * FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_refund') ORDER BY ID"
);
$orderIds = array_map('intval', array_column($orders, 'ID'));
$orderIdSql = $orderIds === [] ? '0' : implode(',', $orderIds);

$users = manga_order_export_rows(
    "SELECT ID, user_login, user_pass, user_nicename, user_email, user_url, "
    . "user_registered, user_activation_key, user_status, display_name FROM {$wpdb->users} ORDER BY ID"
);
$userIds = array_map('intval', array_column($users, 'ID'));
$userIdSql = $userIds === [] ? '0' : implode(',', $userIds);

$orderItemsTable = $wpdb->prefix . 'woocommerce_order_items';
$orderItemMetaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
$downloadPermissionsTable = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

$orderItems = manga_order_export_rows(
    "SELECT order_item_id, order_item_name, order_item_type, order_id FROM {$orderItemsTable} "
    . "WHERE order_id IN ({$orderIdSql}) ORDER BY order_id, order_item_id"
);
$orderItemIds = array_map('intval', array_column($orderItems, 'order_item_id'));
$orderItemIdSql = $orderItemIds === [] ? '0' : implode(',', $orderItemIds);

$comments = manga_order_export_rows(
    "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID IN ({$orderIdSql}) ORDER BY comment_ID"
);
$commentIds = array_map('intval', array_column($comments, 'comment_ID'));
$commentIdSql = $commentIds === [] ? '0' : implode(',', $commentIds);

$downloadTableExists = (string) $wpdb->get_var(
    $wpdb->prepare('SHOW TABLES LIKE %s', $downloadPermissionsTable)
);

$payload = [
    'format'                   => 'manga-shop-users-orders-v1',
    'exported_at'              => gmdate('c'),
    'source_url'               => 'https://www.mangaproduct.com',
    'users'                    => $users,
    'user_meta'                => manga_order_export_rows(
        "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} "
        . "WHERE user_id IN ({$userIdSql}) ORDER BY user_id, umeta_id"
    ),
    'orders'                   => $orders,
    'order_meta'               => manga_order_export_rows(
        "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} "
        . "WHERE post_id IN ({$orderIdSql}) ORDER BY post_id, meta_id"
    ),
    'order_items'              => $orderItems,
    'order_item_meta'          => manga_order_export_rows(
        "SELECT meta_id, order_item_id, meta_key, meta_value FROM {$orderItemMetaTable} "
        . "WHERE order_item_id IN ({$orderItemIdSql}) ORDER BY order_item_id, meta_id"
    ),
    'comments'                 => $comments,
    'comment_meta'             => manga_order_export_rows(
        "SELECT meta_id, comment_id, meta_key, meta_value FROM {$wpdb->commentmeta} "
        . "WHERE comment_id IN ({$commentIdSql}) ORDER BY comment_id, meta_id"
    ),
    'download_permissions'     => $downloadTableExists === $downloadPermissionsTable
        ? manga_order_export_rows(
            "SELECT * FROM {$downloadPermissionsTable} WHERE order_id IN ({$orderIdSql}) ORDER BY permission_id"
        )
        : [],
    'payment_tokens_excluded'  => true,
];

$json = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);

if (! is_string($json)) {
    throw new RuntimeException('Could not encode users/orders export: ' . json_last_error_msg());
}

fwrite(STDOUT, $json . PHP_EOL);

