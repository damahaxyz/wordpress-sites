<?php
/**
 * Read-only snapshot of the live legacy users/orders dataset.
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'www.mangaproduct.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT'] = '443';

define('SHORTINIT', true);
require '/var/www/html/wp-load.php';

global $wpdb;

$orderItemsTable = $wpdb->prefix . 'woocommerce_order_items';

$summary = [
    'users_total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    'status_counts' => $wpdb->get_results(
        "SELECT post_type AS type, post_status AS status, COUNT(*) AS total FROM {$wpdb->posts} "
        . "WHERE post_type IN ('shop_order', 'shop_order_refund') GROUP BY post_type, post_status ORDER BY post_type, post_status",
        ARRAY_A
    ),
    'max_order_id' => (int) $wpdb->get_var(
        "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_refund')"
    ),
    'last_order_modified_gmt' => (string) $wpdb->get_var(
        "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_refund')"
    ),
    'registered_customer_orders' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} posts INNER JOIN {$wpdb->postmeta} customer "
        . "ON customer.post_id = posts.ID AND customer.meta_key = '_customer_user' "
        . "WHERE posts.post_type = 'shop_order' AND CAST(customer.meta_value AS UNSIGNED) > 0"
    ),
    'guest_orders' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} posts LEFT JOIN {$wpdb->postmeta} customer "
        . "ON customer.post_id = posts.ID AND customer.meta_key = '_customer_user' "
        . "WHERE posts.post_type = 'shop_order' AND COALESCE(CAST(customer.meta_value AS UNSIGNED), 0) = 0"
    ),
    'payment_method_counts' => $wpdb->get_results(
        "SELECT COALESCE(payment.meta_value, '') AS payment_method, COUNT(*) AS total FROM {$wpdb->posts} posts "
        . "LEFT JOIN {$wpdb->postmeta} payment ON payment.post_id = posts.ID AND payment.meta_key = '_payment_method' "
        . "WHERE posts.post_type = 'shop_order' GROUP BY payment_method ORDER BY total DESC",
        ARRAY_A
    ),
    'order_item_counts' => $wpdb->get_results(
        "SELECT items.order_item_type, COUNT(*) AS total FROM {$orderItemsTable} items "
        . "INNER JOIN {$wpdb->posts} posts ON posts.ID = items.order_id "
        . "WHERE posts.post_type IN ('shop_order', 'shop_order_refund') GROUP BY items.order_item_type ORDER BY items.order_item_type",
        ARRAY_A
    ),
    'order_notes' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments} comments INNER JOIN {$wpdb->posts} posts "
        . "ON posts.ID = comments.comment_post_ID WHERE posts.post_type IN ('shop_order', 'shop_order_refund')"
    ),
    'orders_total_amount' => (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(CAST(total.meta_value AS DECIMAL(20,8))), 0) FROM {$wpdb->posts} posts "
        . "INNER JOIN {$wpdb->postmeta} total ON total.post_id = posts.ID AND total.meta_key = '_order_total' "
        . "WHERE posts.post_type = 'shop_order'"
    ),
    'refund_amount' => (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(CAST(amount.meta_value AS DECIMAL(20,8))), 0) FROM {$wpdb->posts} posts "
        . "INNER JOIN {$wpdb->postmeta} amount ON amount.post_id = posts.ID AND amount.meta_key = '_refund_amount' "
        . "WHERE posts.post_type = 'shop_order_refund'"
    ),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
