<?php

$productId = 31;
$mappingFile = '/import/image-ids.tsv';

if (!file_exists($mappingFile)) {
    WP_CLI::error('Missing image mapping: ' . $mappingFile);
}

$imageIds = [];
foreach (file($mappingFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    [$filename, $id] = array_pad(explode("\t", trim($line), 2), 2, null);
    if ($filename && $id && ctype_digit($id)) {
        $imageIds[$filename] = (int) $id;
    }
}

$required = [
    '01-product-main-three-finishes.png',
    '02-product-mat-comparison.png',
    '03-product-size-proportions.png',
    '04-product-natural-wood-lifestyle.png',
    '05-product-no-mat-finishes.png',
];
foreach (['8x10', '12x16', '16x20'] as $size) {
    foreach (['natural', 'walnut', 'black'] as $finish) {
        foreach (['no-mat', 'classic-white-mat'] as $mat) {
            $required[] = sprintf('sku-%s-%s-%s.png', $size, $finish, $mat);
        }
    }
}
foreach ($required as $filename) {
    if (!isset($imageIds[$filename])) {
        WP_CLI::error('Imported attachment is missing for ' . $filename);
    }
}

$product = wc_get_product($productId);
if (!$product instanceof WC_Product_Variable) {
    WP_CLI::error('Product 31 is not a variable product.');
}

$backup = [
    'created_at' => gmdate('c'),
    'featured_image_id' => $product->get_image_id(),
    'gallery_image_ids' => $product->get_gallery_image_ids(),
    'variation_image_ids' => [],
];
foreach ($product->get_children() as $variationId) {
    $variation = wc_get_product($variationId);
    $backup['variation_image_ids'][$variationId] = $variation ? $variation->get_image_id() : 0;
}
update_option('tikool_product_31_image_backup_before_ai_v2_20260921', $backup, false);

$sizeMap = ['8 × 10 in' => '8x10', '12 × 16 in' => '12x16', '16 × 20 in' => '16x20'];
$finishMap = ['Natural Wood' => 'natural', 'Walnut' => 'walnut', 'Black Wood' => 'black'];
$matMap = ['No Mat' => 'no-mat', 'Classic White Mat' => 'classic-white-mat'];

foreach ($product->get_children() as $variationId) {
    $variation = wc_get_product($variationId);
    if (!$variation) {
        WP_CLI::error('Unable to load variation ' . $variationId);
    }
    $size = $sizeMap[$variation->get_attribute('size')] ?? null;
    $finish = $finishMap[$variation->get_attribute('frame-color')] ?? null;
    $mat = $matMap[$variation->get_attribute('mat-style')] ?? null;
    if (!$size || !$finish || !$mat) {
        WP_CLI::error('Unmapped attributes on variation ' . $variationId);
    }
    $filename = sprintf('sku-%s-%s-%s.png', $size, $finish, $mat);
    $variation->set_image_id($imageIds[$filename]);
    $variation->save();
}

$product->set_image_id($imageIds['01-product-main-three-finishes.png']);
$product->set_gallery_image_ids([
    $imageIds['02-product-mat-comparison.png'],
    $imageIds['03-product-size-proportions.png'],
    $imageIds['04-product-natural-wood-lifestyle.png'],
    $imageIds['05-product-no-mat-finishes.png'],
]);
$product->save();

foreach ($imageIds as $filename => $attachmentId) {
    $label = ucwords(str_replace(['-', '.png'], [' ', ''], $filename));
    wp_update_post(['ID' => $attachmentId, 'post_title' => $label]);
    update_post_meta($attachmentId, '_wp_attachment_image_alt', 'Photorealistic custom pet portrait frame – ' . $label);
}

wc_delete_product_transients($productId);
clean_post_cache($productId);
WP_CLI::success('Applied AI-direct featured, gallery, and 18 variation images to product 31.');
