<?php

$customizer = \Tikool\FrameCustomizer\Customizer::instance();
$hidden = apply_filters('woocommerce_hidden_order_itemmeta', []);
foreach ([
    '_tikool_fc_schema_version',
    '_tikool_fc_original_path',
    '_tikool_fc_preview_path',
    '_tikool_fc_cart_preview_path',
    '_tikool_fc_crop_recipe',
    '_tikool_fc_snapshot',
    '_tikool_fc_quality',
] as $key) {
    if (! in_array($key, $hidden, true)) {
        WP_CLI::error('Order item meta is visible: ' . $key);
    }
}

global $wpdb;
$item_id = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT order_item_id FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta WHERE meta_key = %s ORDER BY order_item_id DESC LIMIT 1',
    '_tikool_fc_preview_path'
));
$item = $item_id > 0 ? WC_Order_Factory::get_order_item($item_id) : null;
if (! $item instanceof WC_Order_Item_Product) {
    WP_CLI::error('No framed portrait order item is available to test.');
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if ($admins === []) {
    WP_CLI::error('No administrator is available for the order preview test.');
}
wp_set_current_user((int) $admins[0]);
if (! defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}
ob_start();
$customizer->render_admin_order_data($item_id, $item, null);
$html = (string) ob_get_clean();
if (! str_contains($html, '<img ') || ! str_contains($html, 'inline=1') || ! str_contains($html, 'target="_blank"')) {
    WP_CLI::error('Admin thumbnail or large-image link was not rendered.');
}
if (str_contains($html, '/var/www/html/wp-content/uploads/tikool-customer-photos/')) {
    WP_CLI::error('A private file path leaked into the order preview markup.');
}
if (! str_contains($html, '<details class="tikool-fc-production-summary">')) {
    WP_CLI::error('Collapsed production details were not rendered.');
}

WP_CLI::success('Verified hidden raw metadata, private thumbnail, large-image link, and collapsed production details.');
