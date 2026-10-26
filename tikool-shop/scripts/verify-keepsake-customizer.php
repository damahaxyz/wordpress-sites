<?php
/**
 * Verify the keepsake-box customizer profile against the WooCommerce product.
 * Run with: wp eval-file /path/to/verify-keepsake-customizer.php
 */

if (! class_exists('WooCommerce') || ! class_exists(\Tikool\FrameCustomizer\Config::class)) {
    WP_CLI::error('WooCommerce or Tikool Product Customizer is not active.');
}

$product_ids = get_posts([
    'post_type' => 'product',
    'post_status' => 'any',
    'fields' => 'ids',
    'numberposts' => 1,
    'meta_key' => '_tikool_product_key',
    'meta_value' => 'pet-keepsake-box',
]);
if ($product_ids === []) {
    WP_CLI::error('The pet keepsake-box product was not found.');
}

$product_id = (int) $product_ids[0];
$product = wc_get_product($product_id);
if (! $product instanceof WC_Product_Variable) {
    WP_CLI::error('The keepsake-box product is not variable.');
}

$settings = \Tikool\FrameCustomizer\Config::product_settings($product_id);
$expected_settings = [
    'template' => 'keepsake-box',
    'renderer' => 'keepsake',
    'supports_ai' => false,
    'supports_name_overlay' => false,
    'requires_engraving' => true,
    'name_max_length' => 15,
];
foreach ($expected_settings as $key => $expected) {
    if (($settings[$key] ?? null) !== $expected) {
        WP_CLI::error(sprintf('Unexpected %s setting: %s', $key, wp_json_encode($settings[$key] ?? null)));
    }
}
if (get_post_meta($product_id, '_tikool_requires_pet_photo', true) !== '') {
    WP_CLI::error('Legacy pet-photo metadata is still enabled.');
}
if (get_post_meta($product_id, '_tikool_memory_page_enabled', true) !== 'yes') {
    WP_CLI::error('NFC Memory Page is not enabled for the keepsake product.');
}

$verified = [];
foreach ($product->get_children() as $variation_id) {
    $variation = wc_get_product($variation_id);
    if (! $variation instanceof WC_Product_Variation) {
        continue;
    }
    $snapshot = \Tikool\FrameCustomizer\Config::variation_snapshot($variation);
    $roles = (array) ($snapshot['roles'] ?? []);
    $geometry = (array) ($snapshot['geometry'] ?? []);
    $large = str_contains(strtolower((string) ($roles['size'] ?? '')), 'large');
    $beech = str_contains(strtolower((string) ($roles['wood'] ?? '')), 'beech');
    $expected_box = $large ? 60.0 : 50.0;
    $expected_photo = $large ? 50.0 : 40.0;
    $expected_height = $large ? 40.0 : 35.0;
    $expected_vials = $large ? 3.0 : 2.0;
    $expected_wood = $beech ? '#c9975b' : '#6b3f25';
    foreach ([
        'outer_width_mm' => $expected_box,
        'outer_height_mm' => $expected_box,
        'box_height_mm' => $expected_height,
        'print_width_mm' => $expected_photo,
        'print_height_mm' => $expected_photo,
        'vial_count' => $expected_vials,
    ] as $key => $expected) {
        if (abs((float) ($geometry[$key] ?? 0) - $expected) > 0.01) {
            WP_CLI::error(sprintf('Variation %d has incorrect %s: %s', $variation_id, $key, wp_json_encode($geometry[$key] ?? null)));
        }
    }
    if (($geometry['wood_color'] ?? '') !== $expected_wood) {
        WP_CLI::error(sprintf('Variation %d has incorrect wood color.', $variation_id));
    }
    if (($snapshot['renderer'] ?? '') !== 'keepsake' || ! empty($snapshot['supportsAI']) || ! empty($snapshot['supportsNameOverlay']) || empty($snapshot['requiresEngraving'])) {
        WP_CLI::error(sprintf('Variation %d has incorrect keepsake capabilities.', $variation_id));
    }
    $verified[] = sprintf('%s / %s', $roles['wood'] ?? 'unknown wood', $roles['size'] ?? 'unknown size');
}

if (count($verified) !== 4) {
    WP_CLI::error(sprintf('Expected four keepsake variations, verified %d.', count($verified)));
}

WP_CLI::success(sprintf('Keepsake customizer verified for product %d: %s', $product_id, implode('; ', $verified)));
