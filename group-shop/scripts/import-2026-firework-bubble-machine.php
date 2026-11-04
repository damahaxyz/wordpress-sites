<?php
/**
 * Idempotent media/product import. Mount the asset directory at /import.
 * First run creates a draft; pass TROVESIA_BUBBLE_STATUS=publish after checking it.
 * Execute using the existing UID 33 WP-CLI service, after a private backup.
 */
if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce is required.');
}
require_once ABSPATH . 'wp-admin/includes/image.php';

// Historical full-kit bootstrap. Existing listings now use supplies sold separately.
// Never replay the old package contents, initial prices or name over the live product.
if (wc_get_product_id_by_sku('TV-2026-FIREWORK-BUBBLE')) {
    throw new RuntimeException('Initial import is archived for existing products. Use update-bubble-machine-no-supplies.php and review current settings.');
}

$status = getenv('TROVESIA_BUBBLE_STATUS') ?: 'draft';
if (! in_array($status, ['draft', 'publish'], true)) {
    throw new RuntimeException('Unsupported product status.');
}
$manifest = json_decode(file_get_contents('/import/ready/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$definitions = [
    'hero' => ['firework-bubble-machine-2026-hero-en.webp', '2026 automatic firework bubble machine with ambient lights', 'Red 2026 electric firework bubble machine producing soap bubbles with ambient lighting'],
    'single' => ['firework-bubble-machine-single-set-en.webp', 'Single firework bubble machine set and included accessories', 'One red bubble machine with four AA batteries, one bubble solution bottle, ten concentrate sachets and one sticker sheet'],
    'double' => ['firework-bubble-machine-double-set-en.webp', 'Two-machine firework bubble set and included accessories', 'Two red bubble machines with eight AA batteries, two bubble solution bottles, twenty concentrate sachets and two sticker sheets'],
    'controls' => ['firework-bubble-machine-one-touch-en.webp', 'One-touch start for automatic bubble play', 'Close-up of the red bubble machine round start button beneath a star decoration'],
    'outlets' => ['firework-bubble-machine-outlets-en.webp', 'Multi-opening bubble design with ambient light', 'Close-up of the bubble machine outlets producing soap bubbles with ambient light'],
    'reservoir' => ['firework-bubble-machine-reservoir-en.webp', 'Refillable bubble solution reservoir', 'Open red bubble machine compartment with the depicted solution bottle and clear feed tube'],
    'dimensions' => ['firework-bubble-machine-dimensions-en.webp', 'Firework bubble machine approximate dimensions', 'Red cylindrical bubble machine measuring approximately 15.5 cm high and 6.7 cm wide'],
];

// Check all encoded inputs before creating any attachments or product records.
foreach ($definitions as [$filename]) {
    $row = $manifest[$filename] ?? null;
    $path = '/import/ready/' . $filename;
    if (! $row || ! is_readable($path) || hash_file('sha256', $path) !== $row['sha256']) {
        throw new RuntimeException('Missing or changed optimized asset: ' . $filename);
    }
    $info = wp_getimagesize($path);
    if (! $info || ($info['mime'] ?? '') !== 'image/webp' || filesize($path) > 500000) {
        throw new RuntimeException('Asset must be WebP under 500 KB: ' . $filename);
    }
}

$images = [];
foreach ($definitions as $key => [$filename, $title, $alt]) {
    $import_key = 'trovesia-2026-bubble-' . sanitize_key(pathinfo($filename, PATHINFO_FILENAME));
    $existing = get_posts([
        'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1,
        'fields' => 'ids', 'meta_key' => '_trovesia_import_key', 'meta_value' => $import_key,
    ]);
    if ($existing) {
        $id = (int) $existing[0];
        $file = get_attached_file($id);
        if (! is_readable($file) || hash_file('sha256', $file) !== $manifest[$filename]['sha256']) {
            throw new RuntimeException('Previously imported media changed: ' . $filename);
        }
    } else {
        $upload = wp_upload_bits($filename, null, file_get_contents('/import/ready/' . $filename));
        if (! empty($upload['error'])) {
            throw new RuntimeException((string) $upload['error']);
        }
        $id = wp_insert_attachment([
            'post_mime_type' => 'image/webp', 'post_title' => $title, 'post_status' => 'inherit',
        ], $upload['file'], 0, true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $metadata = wp_generate_attachment_metadata($id, $upload['file']);
        if (empty($metadata['width']) || empty($metadata['sizes']['woocommerce_single'])) {
            throw new RuntimeException('Required responsive images were not generated.');
        }
        wp_update_attachment_metadata($id, $metadata);
        update_post_meta($id, '_trovesia_import_key', $import_key);
    }
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    $images[$key] = (int) $id;
}

$category = get_term_by('slug', 'party-celebration', 'product_cat');
if (! $category instanceof WP_Term) {
    $created = wp_insert_term('Party & Celebration', 'product_cat', ['slug' => 'party-celebration']);
    if (is_wp_error($created)) {
        throw new RuntimeException($created->get_error_message());
    }
    $category = get_term((int) $created['term_id'], 'product_cat');
}
if (! $category instanceof WP_Term) {
    throw new RuntimeException('Product category unavailable.');
}

$parent_sku = 'TV-2026-FIREWORK-BUBBLE';
$parent_id = (int) wc_get_product_id_by_sku($parent_sku);
$product = $parent_id ? wc_get_product($parent_id) : new WC_Product_Variable();
if (! $product instanceof WC_Product_Variable) {
    throw new RuntimeException('Parent SKU is assigned to another product type.');
}
if ($parent_id && (string) get_post_meta($parent_id, '_trovesia_source_handle', true) !== '2026-firework-bubble-machine') {
    throw new RuntimeException('Parent SKU collision.');
}

$attribute = new WC_Product_Attribute();
$attribute->set_id(0);
$attribute->set_name('Pack');
$attribute->set_options(['Single Machine Set', 'Two-Machine Set']);
$attribute->set_position(0);
$attribute->set_visible(true);
$attribute->set_variation(true);

$product->set_name('2026 Firework Bubble Machine with Ambient Lights');
$product->set_slug('2026-firework-bubble-machine-with-ambient-lights');
$product->set_sku($parent_sku);
$product->set_status('draft');
$product->set_catalog_visibility('visible');
$product->set_category_ids([(int) $category->term_id]);
$product->set_attributes([$attribute]);
$product->set_default_attributes([]);
$product->set_image_id($images['hero']);
$product->set_gallery_image_ids([
    $images['single'], $images['double'], $images['controls'],
    $images['outlets'], $images['reservoir'], $images['dimensions'],
]);
$product->set_manage_stock(false);
$product->set_stock_status('instock');
$product->set_reviews_allowed(true);
$product->set_short_description('<p>New for 2026: a fully automatic electric bubble machine with ambient lights and a festive firework-inspired design. Enjoy one-touch bubble play with the AA battery edition. Choose a complete single-machine set or a two-machine set.</p>');
$product->set_description(
    '<h2>Light up playtime with bubbles</h2>'
    . '<p>The 2026 Firework Bubble Machine combines automatic electric bubble making with ambient lighting. Its red cylindrical body, festive decorations and upward bubble display bring a playful touch to parties and celebration moments. The firework-inspired effect is created with soap bubbles and light.</p>'
    . '<h3>Three reasons to choose it</h3><ul>'
    . '<li><strong>New for 2026:</strong> a festive bubble-machine design for the new season.</li>'
    . '<li><strong>Fully automatic electric operation:</strong> prepare the solution and batteries, then press the start button for bubble play.</li>'
    . '<li><strong>Ambient lights:</strong> illuminated bubble effects add atmosphere to your celebration.</li></ul>'
    . '<h3>Choose your set</h3>'
    . '<table><thead><tr><th>Included</th><th>Single Machine Set</th><th>Two-Machine Set</th></tr></thead><tbody>'
    . '<tr><td>Bubble machines</td><td>1</td><td>2</td></tr>'
    . '<tr><td>AA batteries</td><td>4</td><td>8</td></tr>'
    . '<tr><td>Bubble solution bottles</td><td>1</td><td>2</td></tr>'
    . '<tr><td>Concentrate sachets</td><td>10</td><td>20</td></tr>'
    . '<tr><td>Sticker sheets</td><td>1</td><td>2</td></tr></tbody></table>'
    . '<p>Both options are the AA battery edition. Accessory colours and packaging may vary; batteries are not guaranteed to be a specific brand.</p>'
    . '<h3>Compact and refillable</h3><p>Each machine measures approximately 15.5 cm high and 6.7 cm wide. The solution reservoir can be refilled; follow the supplied instructions for concentrate dilution, filling and battery installation. Measurements are approximate.</p>'
    . '<h3>Care and use</h3><p>Use with adult supervision. Keep bubble solution away from eyes and mouths, keep the electrical body dry, and wipe up spills to avoid slippery surfaces. Place the machine upright on a stable surface and follow the supplied instructions.</p>'
    . '<h3>Delivery</h3><p>Estimated delivery: 5–15 days. Destination and carrier restrictions may apply to included batteries and liquids.</p>'
);
$parent_id = $product->save();
update_post_meta($parent_id, '_trovesia_source_handle', '2026-firework-bubble-machine');
update_post_meta($parent_id, '_trovesia_source_url', '');
update_post_meta($parent_id, '_trovesia_eyebrow', 'New for 2026 · Automatic Bubble Play');
update_post_meta($parent_id, '_trovesia_benefits', "2026 new model\nFully automatic electric operation\nAmbient lights\nAA batteries and bubble supplies included\nSingle-machine or two-machine set\nEstimated delivery in 5–15 days");
update_post_meta($parent_id, '_trovesia_shipping', 'Estimated delivery is 5–15 days. Destination and carrier restrictions may apply to the included batteries and liquids.');
update_post_meta($parent_id, '_trovesia_delivery_min_days', 5);
update_post_meta($parent_id, '_trovesia_delivery_max_days', 15);
update_post_meta($parent_id, '_trovesia_guarantee', 'Returns are handled according to our store return policy. Please contact customer care for assistance.');
update_post_meta($parent_id, '_trovesia_order_activity', '');

$variation_ids = [];
foreach ([
    'Single Machine Set' => ['sku' => 'TV-BUBBLE-2026-SINGLE', 'price' => '29.90', 'image' => $images['single'], 'contents' => '1 bubble machine, 4 AA batteries, 1 bubble solution bottle, 10 concentrate sachets and 1 sticker sheet.'],
    'Two-Machine Set' => ['sku' => 'TV-BUBBLE-2026-DUO', 'price' => '54.90', 'image' => $images['double'], 'contents' => '2 bubble machines, 8 AA batteries, 2 bubble solution bottles, 20 concentrate sachets and 2 sticker sheets.'],
] as $label => $data) {
    $id = (int) wc_get_product_id_by_sku($data['sku']);
    $variation = $id ? wc_get_product($id) : new WC_Product_Variation();
    if (! $variation instanceof WC_Product_Variation || ($id && $variation->get_parent_id() !== $parent_id)) {
        throw new RuntimeException('Variation SKU collision.');
    }
    $variation->set_parent_id($parent_id);
    $variation->set_status('publish');
    $variation->set_sku($data['sku']);
    $variation->set_attributes(['pack' => $label]);
    $variation->set_regular_price($data['price']);
    $variation->set_sale_price('');
    $variation->set_image_id($data['image']);
    $variation->set_manage_stock(false);
    $variation->set_stock_status('instock');
    $variation->set_description($data['contents']);
    $variation_ids[$data['sku']] = $variation->save();
}
WC_Product_Variable::sync($parent_id);
wc_delete_product_transients($parent_id);
$product = new WC_Product_Variable($parent_id);
if (count($product->get_children()) !== 2 || $product->get_default_attributes() !== []) {
    throw new RuntimeException('Unexpected final variations or defaults; product left in draft.');
}
$prices = [];
foreach ($product->get_children() as $id) {
    $v = wc_get_product($id);
    $prices[$v->get_sku()] = wc_format_decimal($v->get_regular_price(), 2);
}
if (($prices['TV-BUBBLE-2026-SINGLE'] ?? '') !== '29.90' || ($prices['TV-BUBBLE-2026-DUO'] ?? '') !== '54.90') {
    throw new RuntimeException('Price verification failed; product left in draft.');
}
$product->set_status($status);
$product->save();
wc_delete_product_transients($parent_id);
clean_post_cache($parent_id);
echo wp_json_encode([
    'product_id' => $parent_id, 'status' => $product->get_status(),
    'permalink' => get_permalink($parent_id), 'images' => $images,
    'variations' => $variation_ids, 'prices' => $prices,
    'default_attributes' => $product->get_default_attributes(), 'delivery_days' => [5, 15],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
