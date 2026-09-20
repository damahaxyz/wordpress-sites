<?php
/**
 * Remove deterministic WooCommerce side effects from the first historical order import.
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

global $wpdb;

$ordersTable = $wpdb->prefix . 'wc_orders';
$ordersMetaTable = $wpdb->prefix . 'wc_orders_meta';

$unmappedRefundIds = array_map(
    'intval',
    $wpdb->get_col(
        "SELECT orders.id FROM {$ordersTable} orders "
        . "LEFT JOIN {$ordersMetaTable} meta ON meta.order_id = orders.id AND meta.meta_key = '_manga_legacy_order_id' "
        . "WHERE orders.type = 'shop_order_refund' AND meta.id IS NULL ORDER BY orders.id"
    )
);

$unmappedNoteIds = array_map(
    'intval',
    $wpdb->get_col(
        "SELECT comments.comment_ID FROM {$wpdb->comments} comments "
        . "INNER JOIN {$ordersMetaTable} order_meta ON order_meta.order_id = comments.comment_post_ID "
        . "AND order_meta.meta_key = '_manga_legacy_order_id' "
        . "LEFT JOIN {$wpdb->commentmeta} comment_meta ON comment_meta.comment_id = comments.comment_ID "
        . "AND comment_meta.meta_key = '_manga_legacy_comment_id' "
        . "WHERE comments.comment_type = 'order_note' AND comment_meta.meta_id IS NULL ORDER BY comments.comment_ID"
    )
);

if (count($unmappedRefundIds) !== 25 || count($unmappedNoteIds) !== 314) {
    throw new RuntimeException(
        'Safety check failed; expected 25 synthetic refunds and 314 synthetic notes, found '
        . count($unmappedRefundIds) . ' and ' . count($unmappedNoteIds) . '.'
    );
}

foreach ($unmappedRefundIds as $refundId) {
    $refund = wc_get_order($refundId);
    if (! $refund instanceof WC_Order_Refund || $refund->get_meta('_manga_legacy_order_id', true) !== '') {
        throw new RuntimeException("Refund {$refundId} failed its deletion safety check.");
    }
    $refund->delete(true);
}

foreach ($unmappedNoteIds as $commentId) {
    if (! wp_delete_comment($commentId, true)) {
        throw new RuntimeException("Could not remove synthetic order note {$commentId}.");
    }
}

$mappedRefundIds = array_map(
    'intval',
    $wpdb->get_col(
        "SELECT orders.id FROM {$ordersTable} orders "
        . "INNER JOIN {$ordersMetaTable} meta ON meta.order_id = orders.id AND meta.meta_key = '_manga_legacy_order_id' "
        . "WHERE orders.type = 'shop_order_refund' ORDER BY orders.id"
    )
);
if (count($mappedRefundIds) !== 29) {
    throw new RuntimeException('Safety check failed; expected 29 mapped legacy refunds.');
}

foreach ($mappedRefundIds as $refundId) {
    $refund = wc_get_order($refundId);
    if (! $refund instanceof WC_Order_Refund) {
        throw new RuntimeException("Mapped refund {$refundId} could not be loaded.");
    }
    $refund->set_total((float) $refund->get_amount() * -1);
    $refund->save();
}

echo wp_json_encode([
    'synthetic_refunds_deleted' => count($unmappedRefundIds),
    'synthetic_notes_deleted'   => count($unmappedNoteIds),
    'legacy_refunds_normalized' => count($mappedRefundIds),
]) . PHP_EOL;
