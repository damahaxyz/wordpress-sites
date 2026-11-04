<?php
/**
 * Import or update the five-colour Christmas spark atmosphere wand gift set.
 *
 * Optional environment variable:
 * - TROVESIA_WAND_STATUS: draft, private, or publish. Defaults to draft.
 *
 * The project asset directory should be mounted at /import.
 */

if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active.');
}

require_once ABSPATH . 'wp-admin/includes/image.php';

function trovesia_wand_import_image(string $relative_path, string $title, string $alt): int
{
    $import_key = 'trovesia-christmas-spark-wand-' . sanitize_key(str_replace(['/', '.'], '-', $relative_path));
    $existing = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_trovesia_import_key',
        'meta_value'     => $import_key,
    ]);
    if ($existing) {
        return (int) $existing[0];
    }

    $source = '/import/' . ltrim($relative_path, '/');
    if (! is_readable($source)) {
        throw new RuntimeException('Missing product image: ' . $source);
    }

    $filename = sanitize_file_name(basename($source));
    $upload = wp_upload_bits($filename, null, file_get_contents($source));
    if (! empty($upload['error'])) {
        throw new RuntimeException((string) $upload['error']);
    }

    $filetype = wp_check_filetype($upload['file']);
    $attachment_id = wp_insert_attachment([
        'post_mime_type' => (string) ($filetype['type'] ?? 'image/jpeg'),
        'post_title'     => $title,
        'post_status'    => 'inherit',
    ], $upload['file']);
    if (is_wp_error($attachment_id)) {
        throw new RuntimeException($attachment_id->get_error_message());
    }

    wp_update_attachment_metadata(
        $attachment_id,
        wp_generate_attachment_metadata($attachment_id, $upload['file'])
    );
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    update_post_meta($attachment_id, '_trovesia_import_key', $import_key);

    return (int) $attachment_id;
}

$price = '99.00';

$status = strtolower(trim((string) (getenv('TROVESIA_WAND_STATUS') ?: 'draft')));
if (! in_array($status, ['draft', 'private', 'publish'], true)) {
    throw new RuntimeException('TROVESIA_WAND_STATUS must be draft, private, or publish.');
}

$category = get_term_by('slug', 'christmas-gifts', 'product_cat');
if (! $category instanceof WP_Term) {
    $created = wp_insert_term('Christmas Gifts', 'product_cat', ['slug' => 'christmas-gifts']);
    if (is_wp_error($created)) {
        throw new RuntimeException($created->get_error_message());
    }
    $category = get_term((int) $created['term_id'], 'product_cat');
}
if (! $category instanceof WP_Term) {
    throw new RuntimeException('Unable to create the Christmas Gifts category.');
}

$images = [
    'hero' => trovesia_wand_import_image(
        'ready/christmas-spark-atmosphere-wand-hero.png',
        'Christmas spark atmosphere wand gift set colour collection',
        'Five Christmas spark atmosphere wand gift sets in silver, green, black, pink and yellow'
    ),
    'silver' => trovesia_wand_import_image(
        'ready/gift-set-silver-en.png',
        'Silver spark atmosphere wand set',
        'Silver handheld spark atmosphere wand gift set'
    ),
    'green' => trovesia_wand_import_image(
        'ready/gift-set-green-en.png',
        'Forest Green spark atmosphere wand set',
        'Forest Green handheld spark atmosphere wand gift set'
    ),
    'black' => trovesia_wand_import_image(
        'ready/gift-set-black-en.png',
        'Black spark atmosphere wand set',
        'Black handheld spark atmosphere wand gift set'
    ),
    'pink' => trovesia_wand_import_image(
        'ready/gift-set-pink-en.png',
        'Pink spark atmosphere wand set',
        'Pink handheld spark atmosphere wand gift set'
    ),
    'yellow' => trovesia_wand_import_image(
        'ready/gift-set-yellow-en.png',
        'Yellow spark atmosphere wand set',
        'Yellow handheld spark atmosphere wand gift set'
    ),
    'detail_outdoor' => trovesia_wand_import_image(
        'ready/d1-en.png',
        'Spark atmosphere wand for outdoor moments',
        'Pink handheld spark atmosphere wand creating a bright outdoor celebration effect'
    ),
    'detail_diagram' => trovesia_wand_import_image(
        'ready/d2-en.png',
        'Spark atmosphere wand component diagram',
        'English diagram identifying the spark atmosphere wand components and controls'
    ),
    'detail_features' => trovesia_wand_import_image(
        'ready/d3-en.png',
        'Spark atmosphere wand features and included accessories',
        'English feature guide for the material chamber, gas filling port and included accessories'
    ),
];

$parent_sku = 'TV-XMAS-SPARK-GIFT-SET';
$parent_id = wc_get_product_id_by_sku($parent_sku);
$product = $parent_id ? wc_get_product($parent_id) : new WC_Product_Variable();
if (! $product instanceof WC_Product_Variable) {
    throw new RuntimeException('The parent SKU is already assigned to a non-variable product.');
}

$shipping_copy = 'Estimated delivery is 8–15 days. The gift set includes one small pressurised gas canister, so destination and carrier eligibility restrictions apply.';

$product->set_name('Christmas Spark Atmosphere Wand Gift Set');
$product->set_slug('christmas-spark-atmosphere-wand-gift-set');
$product->set_status($status);
$product->set_catalog_visibility('visible');
$product->set_sku($parent_sku);
$product->set_category_ids([(int) $category->term_id]);
$product->set_image_id($images['hero']);
$product->set_gallery_image_ids([
    $images['pink'],
    $images['black'],
    $images['green'],
    $images['silver'],
    $images['yellow'],
    $images['detail_outdoor'],
    $images['detail_diagram'],
    $images['detail_features'],
]);
$product->set_short_description(
    '<p>A complete spark-effect gift set for outdoor Christmas photos, festive entrances and celebration moments. Each set includes the wand, presentation box, replaceable spark-material chamber and one small pressurised gas canister.</p>'
);
$product->set_description(
    '<h2>Make outdoor holiday moments shine</h2>'
    . '<p>Create a bright spark effect for Christmas photos, proposals, parties and festive entrances. This is a complete gift-box set, not a wand-only listing. The handheld design combines a replaceable spark-material chamber, a safety lock and a separate ignition control.</p>'
    . '<h3>What is included</h3><ul>'
    . '<li>One spark atmosphere wand in your selected colour</li>'
    . '<li>One presentation gift box</li>'
    . '<li>One replaceable spark-material chamber</li>'
    . '<li>One small dedicated pressurised gas canister, subject to destination and carrier restrictions</li>'
    . '</ul>'
    . '<h3>Safety and use</h3>'
    . '<p>For responsible adult outdoor use only. Read all supplied instructions before use. Keep away from children, faces, hair, clothing, dry vegetation, flammable materials and enclosed spaces. Confirm local laws, venue rules and carrier restrictions before ordering or using this product.</p>'
);

$attribute = new WC_Product_Attribute();
$attribute->set_id(0);
$attribute->set_name('Color');
$attribute->set_options(['Silver', 'Forest Green', 'Black', 'Pink', 'Yellow']);
$attribute->set_position(0);
$attribute->set_visible(true);
$attribute->set_variation(true);
$product->set_attributes([$attribute]);
$product->set_default_attributes(['color' => 'Pink']);
$parent_id = $product->save();

update_post_meta($parent_id, '_trovesia_eyebrow', 'Complete Christmas Gift Set');
update_post_meta($parent_id, '_trovesia_benefits', "Complete gift-box set\nOne small pressurised gas canister included\nReplaceable spark-material chamber\nFive body colours\nEstimated delivery in 8–15 days");
update_post_meta($parent_id, '_trovesia_guarantee', 'Unused items may be returned within 30 days, subject to the condition and safety restrictions in our return policy.');
update_post_meta($parent_id, '_trovesia_shipping', $shipping_copy);
update_post_meta($parent_id, '_trovesia_order_activity', '');
update_post_meta($parent_id, '_trovesia_source_handle', 'focus-spark-atmosphere-wand');
update_post_meta($parent_id, '_trovesia_source_url', '');
update_post_meta($parent_id, '_trovesia_pressurised_gas_included', 'yes');

$variations = [
    'Silver'       => ['sku' => 'TV-XMAS-GIFT-SILVER', 'image' => $images['silver']],
    'Forest Green' => ['sku' => 'TV-XMAS-GIFT-GREEN', 'image' => $images['green']],
    'Black'        => ['sku' => 'TV-XMAS-GIFT-BLACK', 'image' => $images['black']],
    'Pink'         => ['sku' => 'TV-XMAS-GIFT-PINK', 'image' => $images['pink']],
    'Yellow'       => ['sku' => 'TV-XMAS-GIFT-YELLOW', 'image' => $images['yellow']],
];

$variation_ids = [];
foreach ($variations as $colour => $data) {
    $variation_id = wc_get_product_id_by_sku($data['sku']);
    $variation = $variation_id ? wc_get_product($variation_id) : new WC_Product_Variation();
    if (! $variation instanceof WC_Product_Variation) {
        throw new RuntimeException('Variation SKU is already assigned to an unexpected product: ' . $data['sku']);
    }

    $variation->set_parent_id($parent_id);
    $variation->set_status('publish');
    $variation->set_sku($data['sku']);
    $variation->set_attributes(['color' => $colour]);
    $variation->set_regular_price($price);
    $variation->set_sale_price('');
    $variation->set_image_id((int) $data['image']);
    $variation->set_manage_stock(false);
    $variation->set_stock_status('instock');
    $variation->set_description($colour . ' body colour. Package contents and safety requirements are the same for every colour.');
    $variation_ids[] = $variation->save();
}

WC_Product_Variable::sync($parent_id);
wc_delete_product_transients($parent_id);
clean_post_cache($parent_id);

echo wp_json_encode([
    'product_id'          => $parent_id,
    'status'              => get_post_status($parent_id),
    'sku'                 => $parent_sku,
    'price_usd'           => $price,
    'gift_set_contents'   => 'wand, gift box, material chamber, one small pressurised gas canister',
    'delivery'            => '8–15 days',
    'category'            => 'Christmas Gifts',
    'variation_ids'       => $variation_ids,
    'permalink'           => get_permalink($parent_id),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
