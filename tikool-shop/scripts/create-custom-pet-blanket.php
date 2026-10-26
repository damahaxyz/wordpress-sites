<?php
/**
 * Create or update the Custom Pet Blanket WooCommerce product.
 *
 * Run inside the wp-cli container with the product-assets directory mounted at
 * /product-assets.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WooCommerce')) {
    WP_CLI::error('WooCommerce is not active.');
}

$source_item_id = '805318629435';
$source_url = 'https://item.taobao.com/item.htm?id=' . $source_item_id;
$asset_directory = '/product-assets/custom-pet-blanket/final';

$description = <<<'HTML'
<h2>Your favorite face, made for cozy days.</h2>
Turn one clear pet photo into a soft, full-color custom blanket. Choose the size and construction that fits your space, then position your photo and preview the artwork before adding it to your cart.

<h3>How it works</h3>
<ol>
  <li><strong>Choose your blanket.</strong> Select a size and single- or double-sided printing.</li>
  <li><strong>Upload one clear photo.</strong> Use a sharp, well-lit image with your pet's face and markings visible.</li>
  <li><strong>Create your artwork.</strong> Keep the original photo or preview one of the available portrait styles, then crop and position it on the blanket.</li>
  <li><strong>We print and ship.</strong> Your selected artwork is printed on soft flannel fleece, and tracking is emailed when the order leaves us.</li>
</ol>

<h3>Blanket options</h3>
<ul>
  <li><strong>Single-sided:</strong> one-layer 350 gsm flannel fleece with printing on one side.</li>
  <li><strong>Double-sided:</strong> two-layer 520 gsm flannel fleece with printing on both sides.</li>
</ul>

<section class="tikool-spec-section" aria-labelledby="tikool-blanket-size-title">
  <h3 id="tikool-blanket-size-title">Sizes</h3>
  <div class="tikool-spec-table-wrap" role="region" aria-label="Blanket dimensions" tabindex="0">
    <table class="tikool-spec-table">
      <thead><tr><th scope="col">Displayed size</th><th scope="col">Production size</th><th scope="col">Best for</th></tr></thead>
      <tbody>
        <tr><td>50 × 60 in</td><td>127 × 152 cm</td><td>Sofa, reading chair, or personal throw</td></tr>
        <tr><td>60 × 80 in</td><td>150 × 200 cm</td><td>Bed, larger sofa, or full-body coverage</td></tr>
      </tbody>
    </table>
  </div>
  <p class="tikool-spec-note">Inch sizes are familiar rounded labels. The listed centimeter measurements are the factory production dimensions.</p>
</section>

<h3>For the best print</h3>
<ul>
  <li>Upload the highest-resolution JPG, PNG, or WebP available.</li>
  <li>Make sure your pet is in focus and not cropped at the face or ears.</li>
  <li>Avoid screenshots, heavy filters, low light, and very small images.</li>
  <li>This listing includes one pet. Contact us before ordering if you need multiple pets in one design.</li>
</ul>

<h3>What is included</h3>
<ul>
  <li>Custom blanket artwork created from one pet photo</li>
  <li>Interactive crop and placement preview</li>
  <li>Full-color printing on your selected blanket construction</li>
  <li>Tracked delivery</li>
</ul>

Because each blanket is made especially for you, colors may vary slightly between your screen and the finished textile. Follow the care label supplied with your blanket.
HTML;

$short_description = 'Wrap up in your favorite face. Upload one clear pet photo and create a soft, full-color flannel blanket in 50 × 60 or 60 × 80 inches, with single-sided 350 gsm or double-sided 520 gsm printing.';

$product_ids = get_posts([
    'post_type' => 'product',
    'post_status' => 'any',
    'fields' => 'ids',
    'numberposts' => 1,
    'meta_key' => '_tikool_source_item_id',
    'meta_value' => $source_item_id,
]);

$product = $product_ids !== [] ? wc_get_product((int) $product_ids[0]) : null;
if (! $product instanceof WC_Product_Variable) {
    $product = new WC_Product_Variable();
}

$category_ids = [];
foreach (['Custom Blankets', 'Pet Portraits'] as $category_name) {
    $term = term_exists($category_name, 'product_cat');
    if (! $term) {
        $term = wp_insert_term($category_name, 'product_cat');
    }
    if (! is_wp_error($term)) {
        $category_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
    }
}

$sizes = [
    '50 × 60 in (127 × 152 cm)',
    '60 × 80 in (150 × 200 cm)',
];
$print_styles = [
    'Single-Sided · 350 gsm',
    'Double-Sided · 520 gsm',
];

$size_attribute = new WC_Product_Attribute();
$size_attribute->set_id(0);
$size_attribute->set_name('Size');
$size_attribute->set_options($sizes);
$size_attribute->set_position(0);
$size_attribute->set_visible(true);
$size_attribute->set_variation(true);

$print_attribute = new WC_Product_Attribute();
$print_attribute->set_id(0);
$print_attribute->set_name('Print Style');
$print_attribute->set_options($print_styles);
$print_attribute->set_position(1);
$print_attribute->set_visible(true);
$print_attribute->set_variation(true);

$product->set_name('Custom Pet Blanket');
$product->set_slug('custom-pet-blanket');
$product->set_status('publish');
$product->set_catalog_visibility('visible');
$product->set_description($description);
$product->set_short_description($short_description);
$product->set_category_ids(array_values(array_unique($category_ids)));
$product->set_attributes([$size_attribute, $print_attribute]);
$product->set_default_attributes([
    'size' => $sizes[0],
    'print-style' => $print_styles[0],
]);
$product->set_manage_stock(false);
$product->set_stock_status('instock');
$product_id = $product->save();

update_post_meta($product_id, '_tikool_requires_pet_photo', 'yes');
update_post_meta($product_id, '_tikool_fc_enabled', 'yes');
update_post_meta($product_id, '_tikool_fc_template', 'blanket');
update_post_meta($product_id, '_tikool_fc_name_label', "Pet's name");
update_post_meta($product_id, '_tikool_fc_name_required', 'no');
update_post_meta($product_id, '_tikool_fc_photo_label', 'Pet photo');
update_post_meta($product_id, '_tikool_fc_size_attribute', 'size');
delete_post_meta($product_id, '_tikool_fc_frame_attribute');
delete_post_meta($product_id, '_tikool_fc_mat_attribute');
delete_post_meta($product_id, '_tikool_fc_orientation_attribute');
update_post_meta($product_id, '_tikool_fc_default_blanket_color', '#eee7df');
update_post_meta($product_id, '_tikool_fc_default_min_dpi', '100');
update_post_meta($product_id, '_tikool_guarantee', 'Preview and position your artwork before adding the blanket to your cart.');
update_post_meta($product_id, '_tikool_shipping', 'Made to order. Tracking is emailed as soon as your custom blanket ships.');
update_post_meta($product_id, '_tikool_source_item_id', $source_item_id);
update_post_meta($product_id, '_tikool_source_url', $source_url);
update_post_meta($product_id, '_tikool_product_scope', 'one_pet');
if (class_exists('Tikool\\FrameCustomizer\\AI_Styles')) {
    update_post_meta(
        $product_id,
        Tikool\FrameCustomizer\AI_Styles::META_STYLES,
        Tikool\FrameCustomizer\AI_Styles::blanket_defaults()
    );
}

/**
 * Import one project asset once and return its attachment ID.
 */
$import_asset = static function (string $filename, string $title, string $alt) use ($asset_directory, $product_id): int {
    $asset_key = 'custom-pet-blanket/' . $filename;
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

    $path = $asset_directory . '/' . $filename;
    if (! is_readable($path)) {
        WP_CLI::warning('Missing image asset: ' . $path);
        return 0;
    }

    $bits = wp_upload_bits($filename, null, file_get_contents($path));
    if (! empty($bits['error'])) {
        WP_CLI::warning('Could not upload ' . $filename . ': ' . $bits['error']);
        return 0;
    }

    $attachment_id = wp_insert_attachment([
        'post_mime_type' => 'image/png',
        'post_title' => $title,
        'post_status' => 'inherit',
        'post_parent' => $product_id,
    ], $bits['file'], $product_id);
    if (is_wp_error($attachment_id)) {
        WP_CLI::warning('Could not create attachment for ' . $filename . ': ' . $attachment_id->get_error_message());
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $bits['file']));
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    update_post_meta($attachment_id, '_tikool_asset_key', $asset_key);
    return (int) $attachment_id;
};

$image_ids = array_values(array_filter([
    $import_asset(
        '01-custom-pet-blanket-held-thin-flannel.png',
        'Custom Pet Blanket – Lightweight Flannel',
        'Lightweight personalized flannel blanket with a golden retriever portrait held fully open'
    ),
    $import_asset(
        '02-custom-pet-blanket-golden-retriever-thin-flannel.png',
        'Custom Pet Blanket – Golden Retriever',
        'Thin personalized flannel blanket with a golden retriever portrait draped over a sofa'
    ),
    $import_asset(
        '03-custom-pet-blanket-black-cat-thin-flannel.png',
        'Custom Pet Blanket – Black Cat',
        'Thin personalized flannel blanket with a black cat portrait spread across a bed'
    ),
]));
if ($image_ids !== []) {
    $product->set_image_id($image_ids[0]);
    $product->set_gallery_image_ids(array_slice($image_ids, 1));
    $product->save();
}

$variation_specs = [
    [
        'sku' => 'TIK-BLK-5060-1S350',
        'size' => $sizes[0],
        'print' => $print_styles[0],
        'price' => '69.00',
        'width_mm' => 1270,
        'height_mm' => 1520,
    ],
    [
        'sku' => 'TIK-BLK-5060-2S520',
        'size' => $sizes[0],
        'print' => $print_styles[1],
        'price' => '89.00',
        'width_mm' => 1270,
        'height_mm' => 1520,
    ],
    [
        'sku' => 'TIK-BLK-6080-1S350',
        'size' => $sizes[1],
        'print' => $print_styles[0],
        'price' => '89.00',
        'width_mm' => 1500,
        'height_mm' => 2000,
    ],
    [
        'sku' => 'TIK-BLK-6080-2S520',
        'size' => $sizes[1],
        'print' => $print_styles[1],
        'price' => '109.00',
        'width_mm' => 1500,
        'height_mm' => 2000,
    ],
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
        'size' => $spec['size'],
        'print-style' => $spec['print'],
    ]);
    $variation->set_regular_price($spec['price']);
    $variation->set_price($spec['price']);
    $variation->set_status('publish');
    $variation->set_manage_stock(false);
    $variation->set_stock_status('instock');
    $variation_id = $variation->save();
    $variation_ids[] = $variation_id;

    foreach (['outer', 'inner', 'artwork', 'print'] as $dimension) {
        update_post_meta($variation_id, '_tikool_fc_' . $dimension . '_width_mm', (string) $spec['width_mm']);
        update_post_meta($variation_id, '_tikool_fc_' . $dimension . '_height_mm', (string) $spec['height_mm']);
    }
    update_post_meta($variation_id, '_tikool_fc_mat_top_mm', '0');
    update_post_meta($variation_id, '_tikool_fc_mat_right_mm', '0');
    update_post_meta($variation_id, '_tikool_fc_mat_bottom_mm', '0');
    update_post_meta($variation_id, '_tikool_fc_mat_left_mm', '0');
    update_post_meta($variation_id, '_tikool_fc_mat_thickness_mm', '0');
    update_post_meta($variation_id, '_tikool_fc_min_dpi', '100');
}

WC_Product_Variable::sync($product_id);
wc_delete_product_transients($product_id);
clean_post_cache($product_id);

$result = [
    'product_id' => $product_id,
    'status' => get_post_status($product_id),
    'permalink' => get_permalink($product_id),
    'image_ids' => $image_ids,
    'variation_ids' => $variation_ids,
    'prices' => array_column($variation_specs, 'price', 'sku'),
];
WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
