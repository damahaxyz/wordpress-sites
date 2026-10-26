<?php
/**
 * Create or update the Personalized Pet Keepsake Box WooCommerce product.
 *
 * Run inside the wp-cli container with the project product-assets directory
 * mounted read-only at /product-assets.
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WooCommerce')) {
    WP_CLI::error('WooCommerce is not active.');
}

$product_key = 'pet-keepsake-box';
$asset_root = '/product-assets/pet-keepsake-box';

$description = <<<'HTML'
<h2>A keepsake you can hold—and a story you can revisit.</h2>
Keep a lock of fur, a baby tooth, whiskers, or another tiny reminder close in a personalized solid-wood box. Your pet's photo is printed inside the lid, their name is laser engraved on top, and a hidden NFC tag opens an editable Tikool Memory Page from a compatible phone.

<h3>How it works</h3>
<ol>
  <li><strong>Choose the wood and size.</strong> Select walnut or beech, then choose a two-vial small box or three-vial large box.</li>
  <li><strong>Add your pet's name and photo.</strong> The name is used for the lid engraving and the photo is printed inside the lid.</li>
  <li><strong>Claim your Memory Page after payment.</strong> Your order email includes a secure link to claim and edit the page.</li>
  <li><strong>Tap to revisit.</strong> Hold an NFC-enabled phone over the center of the closed lid to open the permanent Memory Page URL.</li>
</ol>

<h3>Your editable Memory Page</h3>
<p>Add and update photos, short videos, audio, your pet's story, and timeline memories from your Tikool account. The NFC tag is hidden in the center of the lid behind the printed photo, so no electronics are visible.</p>

<section class="tikool-spec-section" aria-labelledby="tikool-keepsake-size-title">
  <h3 id="tikool-keepsake-size-title">Sizes</h3>
  <div class="tikool-spec-table-wrap" role="region" aria-label="Keepsake box dimensions" tabindex="0">
    <table class="tikool-spec-table">
      <thead><tr><th scope="col">Size</th><th scope="col">Box</th><th scope="col">Inside photo</th><th scope="col">Vials</th></tr></thead>
      <tbody>
        <tr><td>Small</td><td>5 × 5 × 3.5 cm<br>Approx. 2.0 × 2.0 × 1.4 in</td><td>4 × 4 cm<br>Approx. 1.6 × 1.6 in</td><td>2</td></tr>
        <tr><td>Large</td><td>6 × 6 × 4 cm<br>Approx. 2.4 × 2.4 × 1.6 in</td><td>5 × 5 cm<br>Approx. 2.0 × 2.0 in</td><td>3</td></tr>
      </tbody>
    </table>
  </div>
</section>

<h3>What is included</h3>
<ul>
  <li>Personalized solid-wood keepsake box in your selected finish and size</li>
  <li>Laser-engraved pet name and paw design on the lid</li>
  <li>One printed pet photo fitted inside the lid</li>
  <li>Two or three corked glass keepsake vials, depending on size</li>
  <li>Hidden NFC tag linked to one editable Tikool Memory Page</li>
</ul>

<h3>Natural wood notice</h3>
<p>Every piece of wood is unique. Grain, tone, mineral lines, and small natural variations will differ from the photographs and are part of the character of a solid-wood product. Measurements may vary slightly because the boxes are finished by hand.</p>
HTML;

$short_description = 'A personalized solid-wood keepsake box for fur, a baby tooth, whiskers, and other tiny memories. Includes an inside pet photo, laser-engraved name, glass vials, and a hidden NFC tag that opens an editable Tikool Memory Page.';

$existing_ids = get_posts([
    'post_type' => 'product',
    'post_status' => 'any',
    'fields' => 'ids',
    'numberposts' => 1,
    'meta_key' => '_tikool_product_key',
    'meta_value' => $product_key,
]);

$product = $existing_ids !== [] ? wc_get_product((int) $existing_ids[0]) : null;
if (! $product instanceof WC_Product_Variable) {
    $product = new WC_Product_Variable();
}

$category = term_exists('Pet Memorials', 'product_cat');
if (! $category) {
    $category = wp_insert_term('Pet Memorials', 'product_cat', [
        'slug' => 'pet-memorials',
        'description' => 'Personalized keepsakes and memory products for beloved pets.',
    ]);
}
$category_ids = [];
if (! is_wp_error($category)) {
    $category_ids[] = (int) (is_array($category) ? $category['term_id'] : $category);
}

$woods = ['Walnut', 'Beech'];
$sizes = [
    'Small · 5 × 5 × 3.5 cm · 2 Vials',
    'Large · 6 × 6 × 4 cm · 3 Vials',
];

$wood_attribute = new WC_Product_Attribute();
$wood_attribute->set_id(0);
$wood_attribute->set_name('Wood');
$wood_attribute->set_options($woods);
$wood_attribute->set_position(0);
$wood_attribute->set_visible(true);
$wood_attribute->set_variation(true);

$size_attribute = new WC_Product_Attribute();
$size_attribute->set_id(0);
$size_attribute->set_name('Size');
$size_attribute->set_options($sizes);
$size_attribute->set_position(1);
$size_attribute->set_visible(true);
$size_attribute->set_variation(true);

$product->set_name('Personalized Pet Keepsake Box with NFC Memory Page');
$product->set_slug('personalized-pet-keepsake-box-nfc-memory-page');
$product->set_status('draft');
$product->set_catalog_visibility('visible');
$product->set_description($description);
$product->set_short_description($short_description);
$product->set_category_ids($category_ids);
$product->set_attributes([$wood_attribute, $size_attribute]);
$product->set_default_attributes([
    'wood' => $woods[0],
    'size' => $sizes[0],
]);
$product->set_manage_stock(false);
$product->set_stock_status('instock');
$product->set_tax_status('taxable');
$product_id = $product->save();

update_post_meta($product_id, '_tikool_product_key', $product_key);
delete_post_meta($product_id, '_tikool_requires_pet_photo');
update_post_meta($product_id, '_tikool_fc_enabled', 'yes');
update_post_meta($product_id, '_tikool_fc_template', 'keepsake-box');
update_post_meta($product_id, '_tikool_fc_name_label', "Pet's name");
update_post_meta($product_id, '_tikool_fc_photo_label', 'Inside lid photo');
update_post_meta($product_id, '_tikool_fc_size_attribute', 'size');
update_post_meta($product_id, '_tikool_fc_wood_attribute', 'wood');
update_post_meta($product_id, '_tikool_memory_page_enabled', 'yes');
update_post_meta($product_id, '_tikool_product_scope', 'one_pet');
update_post_meta($product_id, '_tikool_benefits', implode("\n", [
    'Hidden NFC tag under the lid photo',
    'Editable Memory Page for photos, video, audio, stories, and milestones',
    'Personalized pet photo inside the lid',
    'Laser-engraved pet name on the solid-wood lid',
    'Choose two or three glass keepsake vials',
]));
update_post_meta($product_id, '_tikool_guarantee', 'Position your pet photo before adding the keepsake box to your cart.');
update_post_meta($product_id, '_tikool_shipping', 'Made to order. Tracking is emailed as soon as your personalized keepsake box ships.');

/**
 * Import one project asset once and return its attachment ID.
 */
$import_asset = static function (string $relative_path, string $title, string $alt) use ($asset_root, $product_id, $product_key): int {
    $asset_key = $product_key . '/' . $relative_path;
    $existing = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'fields' => 'ids',
        'numberposts' => 1,
        'meta_key' => '_tikool_asset_key',
        'meta_value' => $asset_key,
    ]);
    if ($existing !== []) {
        return (int) $existing[0];
    }

    $path = $asset_root . '/' . $relative_path;
    if (! is_readable($path)) {
        WP_CLI::error('Missing image asset: ' . $path);
    }

    $filename = basename($relative_path);
    $bits = wp_upload_bits($filename, null, file_get_contents($path));
    if (! empty($bits['error'])) {
        WP_CLI::error('Could not upload ' . $filename . ': ' . $bits['error']);
    }

    $filetype = wp_check_filetype($bits['file']);
    $attachment_id = wp_insert_attachment([
        'post_mime_type' => $filetype['type'] ?: 'image/png',
        'post_title' => $title,
        'post_status' => 'inherit',
        'post_parent' => $product_id,
    ], $bits['file'], $product_id);
    if (is_wp_error($attachment_id)) {
        WP_CLI::error('Could not create attachment for ' . $filename . ': ' . $attachment_id->get_error_message());
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $bits['file']));
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    update_post_meta($attachment_id, '_tikool_asset_key', $asset_key);
    return (int) $attachment_id;
};

$gallery_assets = [
    ['gallery/01-main-nfc-memory-page.png', 'Pet Keepsake Box with NFC Memory Page', 'Personalized walnut pet keepsake box with three glass vials and a phone opening an editable NFC Memory Page'],
    ['gallery/02-physical-photo-digital-memory.png', 'Pet Keepsake Box – Three Ways to Remember', 'Engraved pet keepsake box, inside pet photo, glass vials, and editable digital Memory Page'],
    ['gallery/03-nfc-tap-steps.png', 'Pet Keepsake Box – NFC Tap Steps', 'Three steps showing a phone tapping the keepsake box and opening the editable pet Memory Page'],
    ['gallery/04-personalized-details.png', 'Pet Keepsake Box – Personalized Details', 'Walnut keepsake box with laser-engraved pet name and custom photo inside the lid'],
    ['gallery/05-wood-size-options.png', 'Pet Keepsake Box – Wood and Size Options', 'Walnut and beech pet keepsake boxes in small two-vial and large three-vial sizes'],
    ['gallery/06-keepsake-contents.png', 'Pet Keepsake Box – Fur Tooth and Whiskers', 'Three glass vials for pet fur, a baby tooth, and whiskers'],
    ['gallery/07-solid-wood-craftsmanship.png', 'Pet Keepsake Box – Solid Wood Details', 'Solid walnut grain, magnetic closure, glass and cork vials, and laser engraving details'],
    ['gallery/08-memorial-lifestyle.png', 'Pet Keepsake Box – Memorial Display', 'Personalized pet keepsake box displayed with a golden retriever photo and collar'],
];

$gallery_ids = [];
foreach ($gallery_assets as [$path, $title, $alt]) {
    $gallery_ids[] = $import_asset($path, $title, $alt);
}
$product->set_image_id($gallery_ids[0]);
$product->set_gallery_image_ids(array_slice($gallery_ids, 1));
$product->save();

$sku_assets = [
    'TIK-NFC-KB-S-WAL' => ['sku/walnut-small-2-vials.png', 'Walnut Small Pet Keepsake Box', 'Small walnut pet keepsake box with custom lid photo and two glass vials'],
    'TIK-NFC-KB-L-WAL' => ['sku/walnut-large-3-vials.png', 'Walnut Large Pet Keepsake Box', 'Large walnut pet keepsake box with custom lid photo and three glass vials'],
    'TIK-NFC-KB-S-BEE' => ['sku/beech-small-2-vials.png', 'Beech Small Pet Keepsake Box', 'Small beech pet keepsake box with custom lid photo and two glass vials'],
    'TIK-NFC-KB-L-BEE' => ['sku/beech-large-3-vials.png', 'Beech Large Pet Keepsake Box', 'Large beech pet keepsake box with custom lid photo and three glass vials'],
];
$sku_image_ids = [];
foreach ($sku_assets as $sku => [$path, $title, $alt]) {
    $sku_image_ids[$sku] = $import_asset($path, $title, $alt);
}

$variation_specs = [
    ['sku' => 'TIK-NFC-KB-S-WAL', 'wood' => 'Walnut', 'size' => $sizes[0], 'price' => '39.90'],
    ['sku' => 'TIK-NFC-KB-L-WAL', 'wood' => 'Walnut', 'size' => $sizes[1], 'price' => '49.90'],
    ['sku' => 'TIK-NFC-KB-S-BEE', 'wood' => 'Beech', 'size' => $sizes[0], 'price' => '39.90'],
    ['sku' => 'TIK-NFC-KB-L-BEE', 'wood' => 'Beech', 'size' => $sizes[1], 'price' => '49.90'],
];

$variation_ids = [];
foreach ($variation_specs as $spec) {
    $variation_id = wc_get_product_id_by_sku($spec['sku']);
    $variation = $variation_id > 0 ? wc_get_product($variation_id) : null;
    if (! $variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $product_id) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);
    }

    $variation->set_sku($spec['sku']);
    $variation->set_attributes([
        'wood' => $spec['wood'],
        'size' => $spec['size'],
    ]);
    $variation->set_regular_price($spec['price']);
    $variation->set_price($spec['price']);
    $variation->set_image_id($sku_image_ids[$spec['sku']]);
    $variation->set_status('publish');
    $variation->set_manage_stock(false);
    $variation->set_stock_status('instock');
    $variation_ids[] = $variation->save();
}

WC_Product_Variable::sync($product_id);
wc_delete_product_transients($product_id);
clean_post_cache($product_id);

WP_CLI::line(wp_json_encode([
    'product_id' => $product_id,
    'status' => get_post_status($product_id),
    'edit_url' => admin_url('post.php?post=' . $product_id . '&action=edit'),
    'permalink' => get_permalink($product_id),
    'gallery_image_ids' => $gallery_ids,
    'sku_image_ids' => $sku_image_ids,
    'variation_ids' => $variation_ids,
    'prices' => array_column($variation_specs, 'price', 'sku'),
    'memory_page_enabled' => get_post_meta($product_id, '_tikool_memory_page_enabled', true),
    'requires_pet_photo' => get_post_meta($product_id, '_tikool_requires_pet_photo', true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
