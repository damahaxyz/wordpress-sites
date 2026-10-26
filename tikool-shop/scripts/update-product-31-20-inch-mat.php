<?php

$product_id = 31;
$backup_key = '_tikool_content_backup_20260921_before_20in_mat_320x420';
$content = get_post_field('post_content', $product_id);

if (!metadata_exists('post', $product_id, $backup_key)) {
    add_post_meta($product_id, $backup_key, $content, true);
}

$old_row = '<tr><td>20 in</td><td>16 × 20 in</td><td>303 × 405 mm</td><td>293 × 395 mm</td></tr>';
$new_row = '<tr><td>20 in</td><td>16 × 20 in</td><td>330 × 430 mm</td><td>320 × 420 mm</td></tr>';

if (strpos($content, $new_row) === false) {
    if (strpos($content, $old_row) === false) {
        throw new RuntimeException('The existing 20-inch mat row was not found; product content was not changed.');
    }
    $content = str_replace($old_row, $new_row, $content);
    $result = wp_update_post(
        array(
            'ID' => $product_id,
            'post_content' => $content,
        ),
        true
    );
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
}

$product = wc_get_product($product_id);
if (!$product instanceof WC_Product_Variable) {
    throw new RuntimeException('Product 31 is not a variable product.');
}

$geometry = array(
    '_tikool_fc_artwork_width_mm' => '320',
    '_tikool_fc_artwork_height_mm' => '420',
    '_tikool_fc_print_width_mm' => '330',
    '_tikool_fc_print_height_mm' => '430',
    '_tikool_fc_mat_top_mm' => '40.5',
    '_tikool_fc_mat_right_mm' => '39.5',
    '_tikool_fc_mat_bottom_mm' => '40.5',
    '_tikool_fc_mat_left_mm' => '39.5',
);

$updated_variations = array();
foreach ($product->get_children() as $variation_id) {
    $variation = wc_get_product($variation_id);
    if (!$variation instanceof WC_Product_Variation) {
        continue;
    }

    $size = strtolower((string) $variation->get_attribute('size'));
    $mat = strtolower((string) $variation->get_attribute('mat-style'));
    $is_20_inch = preg_match('/16\D+20/', $size) === 1;
    $has_mat = $mat !== '' && strpos($mat, 'no mat') === false;
    if (!$is_20_inch || !$has_mat) {
        continue;
    }

    $variation_backup_key = '_tikool_fc_backup_20260921_20in_mat';
    if (!metadata_exists('post', $variation_id, $variation_backup_key)) {
        $old_geometry = array();
        foreach (array_keys($geometry) as $meta_key) {
            $old_geometry[$meta_key] = get_post_meta($variation_id, $meta_key, true);
        }
        add_post_meta($variation_id, $variation_backup_key, wp_json_encode($old_geometry), true);
    }

    foreach ($geometry as $meta_key => $value) {
        update_post_meta($variation_id, $meta_key, $value);
    }
    $updated_variations[] = $variation_id;
}

if (count($updated_variations) !== 3) {
    throw new RuntimeException('Expected to update 3 matted 20-inch variations, updated ' . count($updated_variations) . '.');
}

wc_delete_product_transients($product_id);
clean_post_cache($product_id);

echo 'updated-variations=' . implode(',', $updated_variations);
