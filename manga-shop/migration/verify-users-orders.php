<?php
/**
 * Verify the migrated HPOS users/orders dataset.
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

global $wpdb;

$ordersTable = $wpdb->prefix . 'wc_orders';
$ordersMetaTable = $wpdb->prefix . 'wc_orders_meta';
$orderItemsTable = $wpdb->prefix . 'woocommerce_order_items';
$commentMetaTable = $wpdb->commentmeta;

$statusCounts = $wpdb->get_results(
    "SELECT type, status, COUNT(*) AS total FROM {$ordersTable} "
    . "WHERE type IN ('shop_order', 'shop_order_refund') GROUP BY type, status ORDER BY type, status",
    ARRAY_A
);
$paymentCounts = $wpdb->get_results(
    "SELECT payment_method, COUNT(*) AS total FROM {$ordersTable} "
    . "WHERE type = 'shop_order' GROUP BY payment_method ORDER BY total DESC",
    ARRAY_A
);
$itemCounts = $wpdb->get_results(
    "SELECT i.order_item_type, COUNT(*) AS total FROM {$orderItemsTable} i "
    . "INNER JOIN {$ordersTable} orders ON orders.id = i.order_id "
    . "WHERE orders.type IN ('shop_order', 'shop_order_refund') GROUP BY i.order_item_type ORDER BY i.order_item_type",
    ARRAY_A
);

$sampleOrderId = (int) $wpdb->get_var(
    "SELECT order_id FROM {$ordersMetaTable} WHERE meta_key = '_manga_legacy_order_id' ORDER BY order_id LIMIT 1"
);
$sampleOrder = $sampleOrderId > 0 ? wc_get_order($sampleOrderId) : false;

$sourceUserHashChecked = 0;
$sourceUserHashMismatches = 0;
$sourceFile = $args[0] ?? '';
if ($sourceFile !== '' && is_readable($sourceFile)) {
    $source = json_decode((string) file_get_contents($sourceFile), true);
    if (is_array($source) && isset($source['users']) && is_array($source['users'])) {
        foreach ($source['users'] as $sourceUser) {
            $legacyId = isset($sourceUser['ID']) ? (int) $sourceUser['ID'] : 0;
            if ($legacyId <= 0 || empty($sourceUser['user_pass'])) {
                continue;
            }

            $newUserId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_manga_legacy_user_id' AND meta_value = %s LIMIT 1",
                    (string) $legacyId
                )
            );
            if ($newUserId <= 0) {
                continue;
            }

            $newHash = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $newUserId)
            );
            $sourceUserHashChecked++;
            if (! hash_equals((string) $sourceUser['user_pass'], $newHash)) {
                $sourceUserHashMismatches++;
            }
        }
    }
}

$summary = [
    'users_total'                 => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    'legacy_customer_mappings'    => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '_manga_legacy_user_id'"
    ),
    'password_hashes_checked'     => $sourceUserHashChecked,
    'password_hash_mismatches'    => $sourceUserHashMismatches,
    'status_counts'               => $statusCounts,
    'legacy_order_mappings'       => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersMetaTable} WHERE meta_key = '_manga_legacy_order_id'"
    ),
    'registered_customer_orders'  => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable} WHERE type = 'shop_order' AND customer_id > 0"
    ),
    'guest_orders'                => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable} WHERE type = 'shop_order' AND customer_id = 0"
    ),
    'payment_method_counts'       => $paymentCounts,
    'order_item_counts'           => $itemCounts,
    'legacy_order_notes'          => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$commentMetaTable} WHERE meta_key = '_manga_legacy_comment_id'"
    ),
    'unmapped_order_notes'        => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments} comments "
        . "INNER JOIN {$ordersMetaTable} order_meta ON order_meta.order_id = comments.comment_post_ID "
        . "AND order_meta.meta_key = '_manga_legacy_order_id' "
        . "LEFT JOIN {$commentMetaTable} comment_meta ON comment_meta.comment_id = comments.comment_ID "
        . "AND comment_meta.meta_key = '_manga_legacy_comment_id' "
        . "WHERE comments.comment_type = 'order_note' AND comment_meta.meta_id IS NULL"
    ),
    'legacy_refund_amount'        => (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(CAST(amount.meta_value AS DECIMAL(20,8))), 0) FROM {$ordersTable} refunds "
        . "INNER JOIN {$ordersMetaTable} legacy ON legacy.order_id = refunds.id AND legacy.meta_key = '_manga_legacy_order_id' "
        . "INNER JOIN {$ordersMetaTable} amount ON amount.order_id = refunds.id AND amount.meta_key = '_refund_amount' "
        . "WHERE refunds.type = 'shop_order_refund'"
    ),
    'legacy_refund_total'         => (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(refunds.total_amount), 0) FROM {$ordersTable} refunds "
        . "INNER JOIN {$ordersMetaTable} legacy ON legacy.order_id = refunds.id AND legacy.meta_key = '_manga_legacy_order_id' "
        . "WHERE refunds.type = 'shop_order_refund'"
    ),
    'orders_total_amount'         => (string) $wpdb->get_var(
        "SELECT COALESCE(SUM(total_amount), 0) FROM {$ordersTable} WHERE type = 'shop_order'"
    ),
    'orders_missing_currency'     => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable} WHERE type = 'shop_order' AND currency = ''"
    ),
    'orders_missing_legacy_id'    => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$ordersTable} orders LEFT JOIN {$ordersMetaTable} meta "
        . "ON meta.order_id = orders.id AND meta.meta_key = '_manga_legacy_order_id' "
        . "WHERE orders.type IN ('shop_order', 'shop_order_refund') AND meta.id IS NULL"
    ),
    'sample_display_order_number' => $sampleOrder instanceof WC_Abstract_Order ? $sampleOrder->get_order_number() : null,
];

echo wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
