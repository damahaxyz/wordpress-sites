<?php
/**
 * Import the 1688 scalp applicator comb and its two colour variants.
 *
 * Run through WP-CLI with the local `product-assets/1079994456070/ready`
 * directory mounted at /import/ready inside the container.
 */

if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active.');
}

require_once ABSPATH . 'wp-admin/includes/image.php';

function trovesia_comb_import_image(string $filename, string $title, string $alt): int
{
    $key = '1688-1079994456070-' . $filename;
    $existing = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_key' => '_trovesia_import_key',
        'meta_value' => $key,
    ]);
    if ($existing) {
        return (int) $existing[0];
    }

    $source = '/import/ready/' . $filename;
    if (! is_readable($source)) {
        throw new RuntimeException('Missing processed image: ' . $source);
    }

    $upload = wp_upload_bits($filename, null, file_get_contents($source));
    if (! empty($upload['error'])) {
        throw new RuntimeException((string) $upload['error']);
    }

    $attachment_id = wp_insert_attachment([
        'post_mime_type' => 'image/webp',
        'post_title' => $title,
        'post_status' => 'inherit',
    ], $upload['file']);
    if (is_wp_error($attachment_id)) {
        throw new RuntimeException($attachment_id->get_error_message());
    }

    wp_update_attachment_metadata(
        $attachment_id,
        wp_generate_attachment_metadata($attachment_id, $upload['file'])
    );
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    update_post_meta($attachment_id, '_trovesia_import_key', $key);

    return (int) $attachment_id;
}

$category = get_term_by('slug', 'hair-care', 'product_cat');
if (! $category) {
    throw new RuntimeException('Hair Care product category is missing.');
}

$images = [
    'clear' => trovesia_comb_import_image(
        'scalp-applicator-comb-clear-with-accessories.webp',
        'Scalp serum applicator comb in Clear',
        'Clear scalp serum applicator comb with dust cover, fill-port stopper and dropper'
    ),
    'pink' => trovesia_comb_import_image(
        'scalp-applicator-comb-pink-with-accessories.webp',
        'Scalp serum applicator comb in Pink',
        'Pink scalp serum applicator comb with dust cover, fill-port stopper and dropper'
    ),
    'clear_features' => trovesia_comb_import_image(
        'scalp-applicator-comb-clear-features-en.webp',
        'Clear applicator comb features in English',
        'English labels show the clear comb, 10 mL reservoir, roller tips, dust cover, stopper and dropper'
    ),
    'pink_features' => trovesia_comb_import_image(
        'scalp-applicator-comb-pink-features-en.webp',
        'Pink applicator comb features in English',
        'English labels show the pink comb, 10 mL reservoir, roller tips, dust cover, stopper and dropper'
    ),
];

$sku = 'TV-COMB-1079994456070';
$existing_id = wc_get_product_id_by_sku($sku);
$product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Variable();
if (! $product instanceof WC_Product_Variable) {
    throw new RuntimeException('The product SKU belongs to a non-variable product.');
}

$product->set_name('Scalp Serum Applicator Comb');
$product->set_slug('scalp-serum-applicator-comb');
$product->set_status('publish');
$product->set_catalog_visibility('visible');
$product->set_sku($sku);
$product->set_category_ids([(int) $category->term_id]);
$product->set_short_description(
    'A refillable 10 mL applicator comb for scalp serum or hair oil. Includes a filling dropper, fill-port stopper and protective dust cover. Choose Clear or Pink; liquid is not included.'
);
$product->set_description(
    '<p>Make scalp-care application part of your routine with a refillable comb designed to guide your chosen serum or hair oil toward the scalp.</p>'
    . '<ul>'
    . '<li>Choose from transparent Clear or Pink.</li>'
    . '<li>Rounded metal roller tips help distribute liquid as you comb.</li>'
    . '<li>10 mL transparent reservoir lets you see the liquid level.</li>'
    . '<li>Includes a filling dropper, a small stopper for the filling port and a protective dust cover for the tips.</li>'
    . '<li>One reusable plastic applicator comb set per order. Serum and oil are not included.</li>'
    . '</ul>'
    . '<p>Use the included dropper to fill the reservoir with a compatible scalp serum or hair oil. Seal the filling port with the small stopper, apply gently along the scalp, and cover the tips after use. Clean according to the formula you use. Avoid applying to broken or irritated skin.</p>'
);
$product->set_image_id($images['clear']);
$product->set_gallery_image_ids([$images['clear_features'], $images['pink'], $images['pink_features']]);
$product->set_manage_stock(false);

$attribute = new WC_Product_Attribute();
$attribute->set_id(0);
$attribute->set_name('Color');
$attribute->set_options(['Clear', 'Pink']);
$attribute->set_position(0);
$attribute->set_visible(true);
$attribute->set_variation(true);
$product->set_attributes([$attribute]);
$product->set_default_attributes(['color' => 'Clear']);
$product_id = $product->save();

update_post_meta($product_id, '_trovesia_source_url', 'https://detail.1688.com/offer/1079994456070.html');
update_post_meta($product_id, '_trovesia_source_offer_id', '1079994456070');

$weight_unit = get_option('woocommerce_weight_unit', 'kg');
$weight = match ($weight_unit) {
    'kg' => '0.06',
    'g' => '60',
    'lbs' => '0.1323',
    'oz' => '2.1164',
    default => '',
};

foreach ([
    ['name' => 'Clear', 'sku' => 'TV-COMB-CLEAR-6131064129457', 'image' => $images['clear']],
    ['name' => 'Pink', 'sku' => 'TV-COMB-PINK-6131064129458', 'image' => $images['pink']],
] as $position => $record) {
    $variation_id = wc_get_product_id_by_sku($record['sku']);
    $variation = $variation_id ? wc_get_product($variation_id) : new WC_Product_Variation();
    if (! $variation instanceof WC_Product_Variation) {
        throw new RuntimeException('Variation SKU belongs to a different product type: ' . $record['sku']);
    }

    $variation->set_parent_id($product_id);
    $variation->set_status('publish');
    $variation->set_sku($record['sku']);
    $variation->set_attributes(['color' => $record['name']]);
    $variation->set_regular_price('34.90');
    $variation->set_sale_price('');
    $variation->set_description($record['name'] . ' applicator comb with dropper, fill-port stopper and dust cover. Liquid not included.');
    $variation->set_image_id($record['image']);
    $variation->set_menu_order($position);
    $variation->set_manage_stock(false);
    if ($weight !== '') {
        $variation->set_weight($weight);
    }
    $variation->save();
}

WC_Product_Variable::sync($product_id);
wc_delete_product_transients($product_id);
clean_post_cache($product_id);

$url = get_permalink($product_id);
echo wp_json_encode([
    'product_id' => $product_id,
    'url' => $url,
    'price' => '34.90',
    'images' => $images,
    'variations' => array_map(
        fn ($item) => wc_get_product_id_by_sku($item),
        ['TV-COMB-CLEAR-6131064129457', 'TV-COMB-PINK-6131064129458']
    ),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
