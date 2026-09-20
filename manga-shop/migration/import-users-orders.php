<?php
/**
 * Import users and legacy CPT WooCommerce orders into the new HPOS store.
 * Run with WP-CLI and an administrator user.
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

if (! class_exists('WooCommerce') || ! function_exists('wc_get_order')) {
    throw new RuntimeException('WooCommerce must be active.');
}

if (
    ! class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
    || ! Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
) {
    throw new RuntimeException('HPOS must be enabled on the destination site.');
}

$inputPath = $args[0] ?? '';
if (! is_string($inputPath) || $inputPath === '' || ! is_readable($inputPath)) {
    throw new RuntimeException('Pass the users/orders JSON export path as the first argument.');
}

$payload = json_decode((string) file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
if (($payload['format'] ?? '') !== 'manga-shop-users-orders-v1') {
    throw new RuntimeException('Unsupported users/orders export format.');
}

global $wpdb;

const MANGA_ORDERS_OLD_ORIGIN = 'https://www.mangaproduct.com';
const MANGA_ORDERS_NEW_ORIGIN = 'https://mangaproduct.com';

// Historical imports must not send mail, deliver webhooks, contact payment
// gateways, or alter current product stock.
add_filter('woocommerce_can_reduce_order_stock', '__return_false', PHP_INT_MAX);
add_filter('woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX);
add_filter('woocommerce_allow_send_queued_transactional_email', '__return_false', PHP_INT_MAX);
foreach (WC()->mailer()->get_emails() as $email) {
    if (isset($email->id)) {
        add_filter('woocommerce_email_enabled_' . $email->id, '__return_false', PHP_INT_MAX);
    }
}

// Saving a newly-created order directly as refunded normally causes WooCommerce
// to create a synthetic full-refund row. Historical refunds are imported below,
// so suppress all order-status side effects for this one-shot CLI process.
foreach (array_keys(wc_get_order_statuses()) as $statusKey) {
    remove_all_actions('woocommerce_order_status_' . preg_replace('/^wc-/', '', $statusKey));
}
remove_all_actions('woocommerce_order_status_changed');
remove_all_actions('woocommerce_order_payment_status_changed');

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<int, array<string, mixed>>>
 */
function manga_group_rows(array $rows, string $idKey): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int) $row[$idKey]][] = $row;
    }

    return $grouped;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, array<int, string>>
 */
function manga_group_meta(array $rows): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(string) $row['meta_key']][] = (string) $row['meta_value'];
    }

    return $grouped;
}

/**
 * @param array<string, array<int, string>> $meta
 */
function manga_meta_first(array $meta, string $key, mixed $default = ''): mixed
{
    return array_key_exists($key, $meta) && array_key_exists(0, $meta[$key])
        ? maybe_unserialize($meta[$key][0])
        : $default;
}

function manga_orders_rewrite(mixed $value): mixed
{
    if (is_string($value)) {
        return str_replace(
            [MANGA_ORDERS_OLD_ORIGIN, 'http://www.mangaproduct.com'],
            [MANGA_ORDERS_NEW_ORIGIN, MANGA_ORDERS_NEW_ORIGIN],
            $value
        );
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = manga_orders_rewrite($item);
        }
    }

    return $value;
}

function manga_import_date(string $date): ?WC_DateTime
{
    if ($date === '' || str_starts_with($date, '0000-00-00')) {
        return null;
    }

    return new WC_DateTime($date, new DateTimeZone('UTC'));
}

/**
 * @param array<string, array<int, string>> $meta
 */
function manga_import_timestamp(array $meta, string $key): ?WC_DateTime
{
    $value = manga_meta_first($meta, $key, 0);
    if (is_numeric($value) && (int) $value > 0) {
        $date = new WC_DateTime('@' . (int) $value);
        $date->setTimezone(new DateTimeZone('UTC'));
        return $date;
    }

    return is_string($value) ? manga_import_date($value) : null;
}

function manga_order_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('H:i:s') . "] {$message}\n");
}

$userMetaByUser = manga_group_rows($payload['user_meta'], 'user_id');
$orderMetaByOrder = manga_group_rows($payload['order_meta'], 'post_id');
$itemsByOrder = manga_group_rows($payload['order_items'], 'order_id');
$itemMetaByItem = manga_group_rows($payload['order_item_meta'], 'order_item_id');
$commentsByOrder = manga_group_rows($payload['comments'], 'comment_post_ID');
$commentMetaByComment = manga_group_rows($payload['comment_meta'], 'comment_id');

/** @var array<int, int> $userMap */
$userMap = [];
/** @var array<int, int> $productMap */
$productMap = [];
/** @var array<int, int> $orderMap */
$orderMap = [];
/** @var array<int, int> $itemMap */
$itemMap = [];
/** @var array<int, int> $commentMap */
$commentMap = [];

foreach ($wpdb->get_results(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_manga_legacy_id'",
    ARRAY_A
) as $row) {
    $productMap[(int) $row['meta_value']] = (int) $row['post_id'];
}

$destinationAdminId = get_current_user_id();
if ($destinationAdminId <= 0 || ! user_can($destinationAdminId, 'manage_woocommerce')) {
    throw new RuntimeException('Run the importer with a WooCommerce administrator user.');
}

manga_order_log('Importing customer accounts.');
$createdUsers = 0;
$mappedUsers = 0;
$excludedUserMeta = [
    'session_tokens',
    'wp_user-settings',
    'wp_user-settings-time',
    'dismissed_wp_pointers',
    'community-events-location',
];

foreach ($payload['users'] as $sourceUser) {
    $oldUserId = (int) $sourceUser['ID'];
    $sourceMetaRows = $userMetaByUser[$oldUserId] ?? [];
    $sourceMeta = manga_group_meta($sourceMetaRows);
    $capabilities = [];
    foreach ($sourceMeta as $key => $values) {
        if (str_ends_with($key, 'capabilities')) {
            $decoded = maybe_unserialize($values[0] ?? '');
            $capabilities = is_array($decoded) ? $decoded : [];
            break;
        }
    }
    $roles = array_keys(array_filter($capabilities));

    if (in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true)) {
        $userMap[$oldUserId] = $destinationAdminId;
        update_user_meta($destinationAdminId, '_manga_legacy_admin_id', $oldUserId);
        continue;
    }

    $existingIds = get_users([
        'meta_key'   => '_manga_legacy_user_id',
        'meta_value' => (string) $oldUserId,
        'fields'     => 'ID',
        'number'     => 1,
    ]);
    $newUserId = isset($existingIds[0]) ? (int) $existingIds[0] : 0;
    $isNew = false;

    if ($newUserId <= 0 && $sourceUser['user_email'] !== '') {
        $existing = get_user_by('email', (string) $sourceUser['user_email']);
        $newUserId = $existing instanceof WP_User ? $existing->ID : 0;
    }
    if ($newUserId <= 0) {
        $existing = get_user_by('login', (string) $sourceUser['user_login']);
        $newUserId = $existing instanceof WP_User ? $existing->ID : 0;
    }

    if ($newUserId <= 0) {
        $newUserId = wp_insert_user([
            'user_login'      => (string) $sourceUser['user_login'],
            'user_pass'       => wp_generate_password(32, true, true),
            'user_nicename'   => (string) $sourceUser['user_nicename'],
            'user_email'      => (string) $sourceUser['user_email'],
            'user_url'        => manga_orders_rewrite((string) $sourceUser['user_url']),
            'user_registered' => (string) $sourceUser['user_registered'],
            'display_name'    => (string) $sourceUser['display_name'],
            'role'            => in_array('subscriber', $roles, true) ? 'subscriber' : 'customer',
        ]);
        if (is_wp_error($newUserId)) {
            throw new RuntimeException("Could not import user {$oldUserId}: " . $newUserId->get_error_message());
        }
        $newUserId = (int) $newUserId;
        $isNew = true;
        $createdUsers++;
    } else {
        $existing = get_userdata($newUserId);
        if ($existing instanceof WP_User && array_intersect(['administrator', 'shop_manager'], $existing->roles) !== []) {
            throw new RuntimeException("Customer {$oldUserId} conflicts with privileged destination user {$newUserId}.");
        }
        $mappedUsers++;
    }

    $role = in_array('subscriber', $roles, true) ? 'subscriber' : 'customer';
    (new WP_User($newUserId))->set_role($role);

    foreach (manga_group_meta($sourceMetaRows) as $key => $values) {
        if (
            str_ends_with($key, 'capabilities')
            || str_ends_with($key, 'user_level')
            || str_starts_with($key, '_woocommerce_persistent_cart')
            || in_array($key, $excludedUserMeta, true)
        ) {
            continue;
        }

        delete_user_meta($newUserId, $key);
        foreach ($values as $rawValue) {
            add_user_meta($newUserId, $key, manga_orders_rewrite(maybe_unserialize($rawValue)));
        }
    }

    update_user_meta($newUserId, '_manga_legacy_user_id', $oldUserId);
    $userMap[$oldUserId] = $newUserId;

    if ($isNew) {
        $wpdb->update(
            $wpdb->users,
            [
                'user_pass'           => (string) $sourceUser['user_pass'],
                'user_activation_key' => (string) $sourceUser['user_activation_key'],
                'user_status'         => (int) $sourceUser['user_status'],
            ],
            ['ID' => $newUserId],
            ['%s', '%s', '%d'],
            ['%d']
        );
        clean_user_cache($newUserId);
    }
}

/**
 * @param array<string, array<int, string>> $meta
 */
function manga_apply_order_data(WC_Order $order, array $source, array $meta, int $customerId): void
{
    $order->set_customer_id($customerId);
    $order->set_currency((string) manga_meta_first($meta, '_order_currency', 'USD'));
    $order->set_prices_include_tax(wc_string_to_bool((string) manga_meta_first($meta, '_prices_include_tax', 'no')));
    $order->set_customer_note(manga_orders_rewrite((string) $source['post_excerpt']));
    $order->set_created_via((string) manga_meta_first($meta, '_created_via', 'admin'));
    $order->set_cart_hash((string) manga_meta_first($meta, '_cart_hash', ''));
    $order->set_order_key((string) manga_meta_first($meta, '_order_key', ''));
    $order->set_customer_ip_address((string) manga_meta_first($meta, '_customer_ip_address', ''));
    $order->set_customer_user_agent((string) manga_meta_first($meta, '_customer_user_agent', ''));
    $order->set_payment_method((string) manga_meta_first($meta, '_payment_method', ''));
    $order->set_payment_method_title((string) manga_meta_first($meta, '_payment_method_title', ''));
    $order->set_transaction_id((string) manga_meta_first($meta, '_transaction_id', ''));

    $billing = [];
    foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
        $billing[$field] = manga_orders_rewrite((string) manga_meta_first($meta, '_billing_' . $field, ''));
    }
    $shipping = [];
    foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'] as $field) {
        $shipping[$field] = manga_orders_rewrite((string) manga_meta_first($meta, '_shipping_' . $field, ''));
    }
    $order->set_address($billing, 'billing');
    $order->set_address($shipping, 'shipping');

    $order->set_discount_total((string) manga_meta_first($meta, '_cart_discount', '0'));
    $order->set_discount_tax((string) manga_meta_first($meta, '_cart_discount_tax', '0'));
    $order->set_shipping_total((string) manga_meta_first($meta, '_order_shipping', '0'));
    $order->set_shipping_tax((string) manga_meta_first($meta, '_order_shipping_tax', '0'));
    $order->set_cart_tax((string) manga_meta_first($meta, '_order_tax', '0'));
    $order->set_total((string) manga_meta_first($meta, '_order_total', '0'));

    $created = manga_import_date((string) $source['post_date_gmt']);
    $modified = manga_import_date((string) $source['post_modified_gmt']);
    if ($created) {
        $order->set_date_created($created);
    }
    if ($modified) {
        $order->set_date_modified($modified);
    }
    $paid = manga_import_timestamp($meta, '_date_paid');
    $completed = manga_import_timestamp($meta, '_date_completed');
    if ($paid) {
        $order->set_date_paid($paid);
    }
    if ($completed) {
        $order->set_date_completed($completed);
    }

    $status = preg_replace('/^wc-/', '', (string) $source['post_status']);
    $order->set_status($status ?: 'pending');
}

/**
 * @param array<string, array<int, string>> $meta
 */
function manga_apply_custom_order_meta(WC_Abstract_Order $order, array $meta): void
{
    $known = [
        '_order_key', '_customer_user', '_payment_method', '_payment_method_title',
        '_transaction_id', '_customer_ip_address', '_customer_user_agent', '_created_via',
        '_date_completed', '_date_paid', '_completed_date', '_paid_date', '_cart_hash',
        '_order_currency', '_cart_discount', '_cart_discount_tax', '_order_shipping',
        '_order_shipping_tax', '_order_tax', '_order_total', '_prices_include_tax',
        '_customer_note', '_refund_amount', '_refunded_by', '_refund_reason',
        '_order_version', '_edit_lock', '_edit_last',
    ];

    foreach ($meta as $key => $values) {
        if (in_array($key, $known, true) || str_starts_with($key, '_billing_') || str_starts_with($key, '_shipping_')) {
            continue;
        }
        $order->delete_meta_data($key);
        foreach ($values as $rawValue) {
            $order->add_meta_data($key, manga_orders_rewrite(maybe_unserialize($rawValue)), false);
        }
    }
}

$ordersTable = $wpdb->prefix . 'wc_orders';
$ordersMetaTable = $wpdb->prefix . 'wc_orders_meta';
foreach ($wpdb->get_results(
    "SELECT order_id, meta_value FROM {$ordersMetaTable} WHERE meta_key = '_manga_legacy_order_id'",
    ARRAY_A
) as $row) {
    $orderMap[(int) $row['meta_value']] = (int) $row['order_id'];
}

$sourceOrders = [];
$sourceRefunds = [];
foreach ($payload['orders'] as $source) {
    if ($source['post_type'] === 'shop_order_refund') {
        $sourceRefunds[] = $source;
    } else {
        $sourceOrders[] = $source;
    }
}

$unmappedProductRefs = 0;
$unmappedVariationRefs = 0;
$importedItems = 0;

/**
 * @param array<string, mixed>              $sourceItem
 * @param array<string, array<int, string>> $meta
 * @param array<int, int>                   $productMap
 */
function manga_build_order_item(array $sourceItem, array $meta, array $productMap, int &$missingProducts, int &$missingVariations): WC_Order_Item
{
    $type = (string) $sourceItem['order_item_type'];
    $name = manga_orders_rewrite((string) $sourceItem['order_item_name']);

    if ($type === 'line_item') {
        $item = new WC_Order_Item_Product();
        $oldProductId = (int) manga_meta_first($meta, '_product_id', 0);
        $oldVariationId = (int) manga_meta_first($meta, '_variation_id', 0);
        $newProductId = $oldProductId > 0 ? ($productMap[$oldProductId] ?? 0) : 0;
        $newVariationId = $oldVariationId > 0 ? ($productMap[$oldVariationId] ?? 0) : 0;
        if ($oldProductId > 0 && $newProductId === 0) {
            $missingProducts++;
        }
        if ($oldVariationId > 0 && $newVariationId === 0) {
            $missingVariations++;
        }
        $item->set_name($name);
        $item->set_product_id($newProductId);
        $item->set_variation_id($newVariationId);
        $item->set_quantity((int) manga_meta_first($meta, '_qty', 0));
        $item->set_tax_class((string) manga_meta_first($meta, '_tax_class', ''));
        $item->set_subtotal((string) manga_meta_first($meta, '_line_subtotal', '0'));
        $item->set_subtotal_tax((string) manga_meta_first($meta, '_line_subtotal_tax', '0'));
        $item->set_total((string) manga_meta_first($meta, '_line_total', '0'));
        $item->set_total_tax((string) manga_meta_first($meta, '_line_tax', '0'));
        $taxes = manga_meta_first($meta, '_line_tax_data', []);
        if (is_array($taxes)) {
            $item->set_taxes($taxes);
        }
        $item->add_meta_data('_manga_legacy_product_id', $oldProductId, true);
        $item->add_meta_data('_manga_legacy_variation_id', $oldVariationId, true);
        $known = [
            '_product_id', '_variation_id', '_qty', '_tax_class', '_line_subtotal',
            '_line_subtotal_tax', '_line_total', '_line_tax', '_line_tax_data',
        ];
    } elseif ($type === 'coupon') {
        $item = new WC_Order_Item_Coupon();
        $item->set_code($name);
        $item->set_discount((string) manga_meta_first($meta, 'discount_amount', '0'));
        $item->set_discount_tax((string) manga_meta_first($meta, 'discount_amount_tax', '0'));
        $known = ['discount_amount', 'discount_amount_tax'];
    } elseif ($type === 'shipping') {
        $item = new WC_Order_Item_Shipping();
        $item->set_method_title($name);
        $item->set_method_id((string) manga_meta_first($meta, 'method_id', ''));
        $item->set_instance_id((string) manga_meta_first($meta, 'instance_id', ''));
        $item->set_total((string) manga_meta_first($meta, 'cost', '0'));
        $taxes = manga_meta_first($meta, 'taxes', []);
        if (is_array($taxes)) {
            $item->set_taxes($taxes);
        }
        $known = ['method_id', 'instance_id', 'cost', 'taxes'];
    } elseif ($type === 'fee') {
        $item = new WC_Order_Item_Fee();
        $item->set_name($name);
        $item->set_tax_class((string) manga_meta_first($meta, '_tax_class', ''));
        $item->set_tax_status((string) manga_meta_first($meta, '_tax_status', 'none'));
        $item->set_amount((string) manga_meta_first($meta, '_fee_amount', '0'));
        $item->set_total((string) manga_meta_first($meta, '_line_total', '0'));
        $item->set_total_tax((string) manga_meta_first($meta, '_line_tax', '0'));
        $taxes = manga_meta_first($meta, '_line_tax_data', []);
        if (is_array($taxes)) {
            $item->set_taxes($taxes);
        }
        $known = ['_tax_class', '_tax_status', '_fee_amount', '_line_total', '_line_tax', '_line_tax_data'];
    } elseif ($type === 'tax') {
        $item = new WC_Order_Item_Tax();
        $item->set_rate_id((int) manga_meta_first($meta, 'rate_id', 0));
        $item->set_label($name);
        $item->set_compound(wc_string_to_bool((string) manga_meta_first($meta, 'compound', '')));
        $item->set_tax_total((string) manga_meta_first($meta, 'tax_amount', '0'));
        $item->set_shipping_tax_total((string) manga_meta_first($meta, 'shipping_tax_amount', '0'));
        $known = ['rate_id', 'label', 'compound', 'tax_amount', 'shipping_tax_amount', 'rate_percent'];
    } else {
        $item = new WC_Order_Item();
        $item->set_name($name);
        $known = [];
    }

    foreach ($meta as $key => $values) {
        if (in_array($key, $known, true)) {
            continue;
        }
        foreach ($values as $rawValue) {
            $item->add_meta_data($key, manga_orders_rewrite(maybe_unserialize($rawValue)), false);
        }
    }

    return $item;
}

manga_order_log('Importing orders and order items.');
foreach ($sourceOrders as $index => $source) {
    $oldOrderId = (int) $source['ID'];
    $meta = manga_group_meta($orderMetaByOrder[$oldOrderId] ?? []);
    $oldCustomerId = (int) manga_meta_first($meta, '_customer_user', 0);
    $customerId = $oldCustomerId > 0 ? ($userMap[$oldCustomerId] ?? 0) : 0;
    if ($oldCustomerId > 0 && $customerId === 0) {
        throw new RuntimeException("Order {$oldOrderId} references an unmapped user {$oldCustomerId}.");
    }

    $order = isset($orderMap[$oldOrderId]) ? wc_get_order($orderMap[$oldOrderId]) : new WC_Order();
    if (! $order instanceof WC_Order || $order instanceof WC_Order_Refund) {
        throw new RuntimeException("Could not initialize order {$oldOrderId}.");
    }

    if ($order->get_id() > 0) {
        foreach ($order->get_items(['line_item', 'coupon', 'fee', 'shipping', 'tax']) as $existingItem) {
            wc_delete_order_item($existingItem->get_id());
        }
    }

    manga_apply_order_data($order, $source, $meta, $customerId);
    manga_apply_custom_order_meta($order, $meta);
    $order->update_meta_data('_manga_legacy_order_id', $oldOrderId);
    $order->save();
    $orderMap[$oldOrderId] = $order->get_id();

    $pendingItemMap = [];
    foreach ($itemsByOrder[$oldOrderId] ?? [] as $sourceItem) {
        $oldItemId = (int) $sourceItem['order_item_id'];
        $itemMeta = manga_group_meta($itemMetaByItem[$oldItemId] ?? []);
        $item = manga_build_order_item(
            $sourceItem,
            $itemMeta,
            $productMap,
            $unmappedProductRefs,
            $unmappedVariationRefs
        );
        $added = $order->add_item($item);
        if ($added === false) {
            throw new RuntimeException("Could not import order item {$oldItemId}.");
        }
        $pendingItemMap[$oldItemId] = $item;
        $importedItems++;
    }
    $order->save();
    foreach ($pendingItemMap as $oldItemId => $item) {
        if ($item->get_id() <= 0) {
            throw new RuntimeException("Order item {$oldItemId} did not persist.");
        }
        $itemMap[$oldItemId] = $item->get_id();
    }

    if (($index + 1) % 50 === 0) {
        manga_order_log('Imported ' . ($index + 1) . '/' . count($sourceOrders) . ' orders.');
    }
}

manga_order_log('Importing refund records without contacting payment gateways.');
foreach ($sourceRefunds as $source) {
    $oldRefundId = (int) $source['ID'];
    $oldParentId = (int) $source['post_parent'];
    if (! isset($orderMap[$oldParentId])) {
        throw new RuntimeException("Refund {$oldRefundId} references missing order {$oldParentId}.");
    }

    $meta = manga_group_meta($orderMetaByOrder[$oldRefundId] ?? []);
    $refund = isset($orderMap[$oldRefundId]) ? wc_get_order($orderMap[$oldRefundId]) : new WC_Order_Refund();
    if (! $refund instanceof WC_Order_Refund) {
        throw new RuntimeException("Could not initialize refund {$oldRefundId}.");
    }

    $refund->set_parent_id($orderMap[$oldParentId]);
    $refund->set_currency((string) manga_meta_first($meta, '_order_currency', 'USD'));
    $refundAmount = (string) manga_meta_first($meta, '_refund_amount', '0');
    $refund->set_amount($refundAmount);
    $refund->set_total((float) $refundAmount * -1);
    $refund->set_reason(manga_orders_rewrite((string) manga_meta_first($meta, '_refund_reason', $source['post_excerpt'])));
    $oldRefundedBy = (int) manga_meta_first($meta, '_refunded_by', 0);
    $refund->set_refunded_by($oldRefundedBy > 0 ? ($userMap[$oldRefundedBy] ?? $destinationAdminId) : 0);
    $created = manga_import_date((string) $source['post_date_gmt']);
    $modified = manga_import_date((string) $source['post_modified_gmt']);
    if ($created) {
        $refund->set_date_created($created);
    }
    if ($modified) {
        $refund->set_date_modified($modified);
    }
    $refund->set_status(preg_replace('/^wc-/', '', (string) $source['post_status']) ?: 'completed');
    manga_apply_custom_order_meta($refund, $meta);
    $refund->update_meta_data('_manga_legacy_order_id', $oldRefundId);
    $refund->save();
    $orderMap[$oldRefundId] = $refund->get_id();
}

manga_order_log('Importing historical order notes without sending notifications.');
$existingComments = [];
foreach ($wpdb->get_results(
    "SELECT comment_id, meta_value FROM {$wpdb->commentmeta} WHERE meta_key = '_manga_legacy_comment_id'",
    ARRAY_A
) as $row) {
    $existingComments[(int) $row['meta_value']] = (int) $row['comment_id'];
}

$allComments = $payload['comments'];
usort($allComments, static fn(array $a, array $b): int => (int) $a['comment_ID'] <=> (int) $b['comment_ID']);
foreach ($allComments as $index => $sourceComment) {
    $oldCommentId = (int) $sourceComment['comment_ID'];
    $oldOrderId = (int) $sourceComment['comment_post_ID'];
    if (! isset($orderMap[$oldOrderId])) {
        continue;
    }

    $oldCommentUserId = (int) $sourceComment['user_id'];
    $commentData = [
        'comment_post_ID'      => $orderMap[$oldOrderId],
        'comment_author'       => (string) $sourceComment['comment_author'],
        'comment_author_email' => (string) $sourceComment['comment_author_email'],
        'comment_author_url'   => manga_orders_rewrite((string) $sourceComment['comment_author_url']),
        'comment_author_IP'    => (string) $sourceComment['comment_author_IP'],
        'comment_date'         => (string) $sourceComment['comment_date'],
        'comment_date_gmt'     => (string) $sourceComment['comment_date_gmt'],
        'comment_content'      => manga_orders_rewrite((string) $sourceComment['comment_content']),
        'comment_karma'        => (int) $sourceComment['comment_karma'],
        'comment_approved'     => (string) $sourceComment['comment_approved'],
        'comment_agent'        => (string) $sourceComment['comment_agent'],
        'comment_type'         => (string) $sourceComment['comment_type'],
        'comment_parent'       => $commentMap[(int) $sourceComment['comment_parent']] ?? 0,
        'user_id'              => $oldCommentUserId > 0 ? ($userMap[$oldCommentUserId] ?? 0) : 0,
    ];

    if (isset($existingComments[$oldCommentId])) {
        $newCommentId = $existingComments[$oldCommentId];
        $commentData['comment_ID'] = $newCommentId;
        $result = wp_update_comment(wp_slash($commentData), true);
        if (is_wp_error($result)) {
            throw new RuntimeException("Could not update order note {$oldCommentId}: " . $result->get_error_message());
        }
    } else {
        $newCommentId = wp_insert_comment(wp_slash($commentData));
        if (! $newCommentId) {
            throw new RuntimeException("Could not import order note {$oldCommentId}.");
        }
    }

    $newCommentId = (int) $newCommentId;
    $commentMap[$oldCommentId] = $newCommentId;
    foreach (manga_group_meta($commentMetaByComment[$oldCommentId] ?? []) as $key => $values) {
        if ($key === '_manga_legacy_comment_id') {
            continue;
        }
        delete_comment_meta($newCommentId, $key);
        foreach ($values as $rawValue) {
            add_comment_meta($newCommentId, $key, manga_orders_rewrite(maybe_unserialize($rawValue)));
        }
    }
    update_comment_meta($newCommentId, '_manga_legacy_comment_id', $oldCommentId);

    if (($index + 1) % 250 === 0) {
        manga_order_log('Imported ' . ($index + 1) . '/' . count($allComments) . ' order notes.');
    }
}

if ($payload['download_permissions'] !== []) {
    throw new RuntimeException('Download permissions exist but this migration has not been approved to import them.');
}

$summary = [
    'users_created'              => $createdUsers,
    'users_mapped_existing'      => $mappedUsers,
    'legacy_admins_mapped'       => count($payload['users']) - $createdUsers - $mappedUsers,
    'orders_imported'            => count($sourceOrders),
    'refunds_imported'           => count($sourceRefunds),
    'order_items_imported'       => $importedItems,
    'order_notes_imported'       => count($commentMap),
    'unmapped_product_refs'      => $unmappedProductRefs,
    'unmapped_variation_refs'    => $unmappedVariationRefs,
    'payment_tokens_imported'    => 0,
];

manga_order_log('IMPORT_COMPLETE ' . wp_json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
