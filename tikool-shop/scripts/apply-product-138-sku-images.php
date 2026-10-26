<?php

$productId = 138;

if (count($args) !== 4) {
    WP_CLI::error(
        'Usage: wp eval-file scripts/apply-product-138-sku-images.php '
        . '<teak-no-mat-id> <teak-mat-id> <black-walnut-no-mat-id> <black-walnut-mat-id>'
    );
}

$imageIds = [
    'teak|no-mat' => (int) $args[0],
    'teak|classic-white-mat' => (int) $args[1],
    'black-walnut|no-mat' => (int) $args[2],
    'black-walnut|classic-white-mat' => (int) $args[3],
];

foreach ($imageIds as $key => $attachmentId) {
    if ($attachmentId <= 0 || get_post_type($attachmentId) !== 'attachment') {
        WP_CLI::error(sprintf('Invalid attachment ID %d for %s.', $attachmentId, $key));
    }
}

$product = wc_get_product($productId);
if (!$product instanceof WC_Product_Variable) {
    WP_CLI::error('Product 138 is not a variable product.');
}

$backup = [
    'created_at' => gmdate('c'),
    'product_attributes' => $product->get_attributes(),
    'variation_image_ids' => [],
    'variation_skus' => [],
    'variation_attributes' => [],
];
foreach ($product->get_children() as $variationId) {
    $variation = wc_get_product($variationId);
    $backup['variation_image_ids'][$variationId] = $variation ? $variation->get_image_id() : 0;
    $backup['variation_skus'][$variationId] = $variation ? $variation->get_sku() : '';
    $backup['variation_attributes'][$variationId] = $variation ? $variation->get_attributes() : [];
}
add_option('tikool_product_138_sku_image_backup_20261003', $backup, '', false);

$updated = [];
foreach ($product->get_children() as $variationId) {
    $variation = wc_get_product($variationId);
    if (!$variation instanceof WC_Product_Variation) {
        WP_CLI::error('Unable to load variation ' . $variationId . '.');
    }

    $variationAttributes = $variation->get_attributes();
    $wood = sanitize_title($variation->get_attribute('wood-material'));
    if ($wood === 'beech') {
        $variationAttributes['wood-material'] = 'Teak';
        $variation->set_attributes($variationAttributes);
        $variation->set_sku(str_replace('-BEECH-', '-TEAK-', $variation->get_sku()));
        $wood = 'teak';
    }
    $mat = sanitize_title($variation->get_attribute('mat-style'));
    $key = $wood . '|' . $mat;
    if (!isset($imageIds[$key])) {
        WP_CLI::error(sprintf('Unmapped attributes on variation %d: %s.', $variationId, $key));
    }

    $variation->set_image_id($imageIds[$key]);
    $variation->save();
    $updated[] = [
        'variation_id' => $variationId,
        'sku' => $variation->get_sku(),
        'wood' => $variation->get_attribute('wood-material'),
        'mat' => $variation->get_attribute('mat-style'),
        'image_id' => $imageIds[$key],
    ];
}

// Save the parent attribute after the variations so WooCommerce cannot rebuild it
// from the previous Beech values during variation synchronization.
$product = wc_get_product($productId);
$productAttributes = $product->get_attributes();
foreach ($productAttributes as $attributeKey => $attribute) {
    if (!$attribute instanceof WC_Product_Attribute || sanitize_title($attribute->get_name()) !== 'wood-material') {
        continue;
    }

    $attribute->set_options(['Teak', 'Black Walnut']);
    $productAttributes[$attributeKey] = $attribute;
}
$product->set_attributes($productAttributes);
$product->set_short_description(str_ireplace('beech', 'teak', $product->get_short_description()));
$product->set_description(str_ireplace('beech', 'teak', $product->get_description()));
$product->save();

// Custom (non-taxonomy) product attributes are stored as a pipe-separated
// string. Write the canonical value explicitly because mutating an existing
// WC_Product_Attribute object may not mark the parent attributes prop dirty.
$storedAttributes = get_post_meta($productId, '_product_attributes', true);
if (!isset($storedAttributes['wood-material'])) {
    WP_CLI::error('Missing wood-material parent attribute on product 138.');
}
$storedAttributes['wood-material']['value'] = 'Teak | Black Walnut';
update_post_meta($productId, '_product_attributes', $storedAttributes);

$benefits = get_post_meta($productId, '_tikool_benefits', true);
if (is_string($benefits) && $benefits !== '') {
    update_post_meta($productId, '_tikool_benefits', str_ireplace('beech', 'teak', $benefits));
}

$altText = [
    'teak|no-mat' => 'Teak NFC pet photo frame without mat',
    'teak|classic-white-mat' => 'Teak NFC pet photo frame with classic white mat',
    'black-walnut|no-mat' => 'Black walnut NFC pet photo frame without mat',
    'black-walnut|classic-white-mat' => 'Black walnut NFC pet photo frame with classic white mat',
];
foreach ($imageIds as $key => $attachmentId) {
    update_post_meta($attachmentId, '_wp_attachment_image_alt', $altText[$key]);
}

wc_delete_product_transients($productId);
clean_post_cache($productId);

WP_CLI::log(wp_json_encode([
    'product_id' => $productId,
    'updated_count' => count($updated),
    'images' => $imageIds,
    'variations' => $updated,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('Replaced Beech with Teak and updated all 20 product 138 variation images by wood material and mat style.');
