<?php

$product = wc_get_product(31);
if (!$product instanceof WC_Product_Variable) {
    WP_CLI::error('Product 31 is not a variable product.');
}

$sizeMap = ['8 × 10 in' => '8x10', '12 × 16 in' => '12x16', '16 × 20 in' => '16x20'];
$finishMap = ['Natural Wood' => 'natural', 'Walnut' => 'walnut', 'Black Wood' => 'black'];
$matMap = ['No Mat' => 'no-mat', 'Classic White Mat' => 'classic-white-mat'];
$rows = [];

foreach ($product->get_children() as $variationId) {
    $variation = wc_get_product($variationId);
    $size = $sizeMap[$variation->get_attribute('size')] ?? '';
    $finish = $finishMap[$variation->get_attribute('frame-color')] ?? '';
    $mat = $matMap[$variation->get_attribute('mat-style')] ?? '';
    $expected = sprintf('sku-%s-%s-%s.png', $size, $finish, $mat);
    $attachmentId = $variation->get_image_id();
    $actual = basename((string) get_attached_file($attachmentId));
    if ($actual !== $expected) {
        WP_CLI::error(sprintf('Variation %d expected %s but has %s', $variationId, $expected, $actual));
    }
    $rows[] = [$variationId, $expected, $attachmentId, wp_get_attachment_url($attachmentId)];
}

if (count($rows) !== 18) {
    WP_CLI::error('Expected 18 variations; found ' . count($rows));
}

$featured = $product->get_image_id();
$gallery = $product->get_gallery_image_ids();
if (basename((string) get_attached_file($featured)) !== '01-product-main-three-finishes.png') {
    WP_CLI::error('Featured image filename mismatch.');
}
if (count($gallery) !== 4) {
    WP_CLI::error('Expected four gallery images.');
}

foreach ($rows as $row) {
    WP_CLI::log(implode("\t", $row));
}
WP_CLI::log('FEATURED' . "\t" . $featured . "\t" . wp_get_attachment_url($featured));
foreach ($gallery as $attachmentId) {
    WP_CLI::log('GALLERY' . "\t" . $attachmentId . "\t" . wp_get_attachment_url($attachmentId));
}
WP_CLI::success('Verified 18 AI-direct variation images plus featured and gallery mappings.');
