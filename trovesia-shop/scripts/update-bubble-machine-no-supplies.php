<?php
/**
 * Update only machine SKU images, supply copy and user-approved prices.
 * Mount revisions/20261102-no-supplies at /revision for UID 33 WP-CLI.
 * Back up first. Keeps current title, slug, status, defaults and other product settings.
 */
if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce required.');
}
require_once ABSPATH . 'wp-admin/includes/image.php';
$product = wc_get_product(wc_get_product_id_by_sku('TV-2026-FIREWORK-BUBBLE'));
if (! $product instanceof WC_Product_Variable || $product->get_id() !== 175) {
    throw new RuntimeException('Expected published machine product not found.');
}
$preserved = [
    'name' => $product->get_name(), 'slug' => $product->get_slug(), 'status' => $product->get_status(),
    'hero' => $product->get_image_id(), 'default_attributes' => $product->get_default_attributes(),
    'category_ids' => $product->get_category_ids(),
];
$definitions = [
    'single' => [
        'file' => 'firework-bubble-machine-single-no-supplies-en.webp',
        'sku' => 'TV-BUBBLE-2026-SINGLE', 'count' => 1, 'batteries' => 4, 'price' => '29.90',
        'title' => 'Single bubble machine and sticker sheet; supplies sold separately',
        'alt' => 'One red bubble machine and one sticker sheet; bubble solution sold separately and four AA batteries required but not included',
    ],
    'double' => [
        'file' => 'firework-bubble-machine-double-no-supplies-en.webp',
        'sku' => 'TV-BUBBLE-2026-DUO', 'count' => 2, 'batteries' => 8, 'price' => '49.90',
        'title' => 'Two bubble machines and two sticker sheets; supplies sold separately',
        'alt' => 'Two red bubble machines and two sticker sheets; bubble solution sold separately and eight AA batteries required but not included',
    ],
];
$manifest = json_decode(file_get_contents('/revision/ready/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($definitions as $key => &$data) {
    $file = '/revision/ready/' . $data['file'];
    $info = is_readable($file) ? wp_getimagesize($file) : false;
    if (! isset($manifest[$data['file']]) || ! $info || ($info['mime'] ?? '') !== 'image/webp'
        || filesize($file) > 500000 || hash_file('sha256', $file) !== $manifest[$data['file']]['sha256']) {
        throw new RuntimeException('Invalid revised image: ' . $data['file']);
    }
    $variation = wc_get_product(wc_get_product_id_by_sku($data['sku']));
    if (! $variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $product->get_id()) {
        throw new RuntimeException('Unexpected machine variation.');
    }
    $data['variation'] = $variation;
    $data['old_image'] = $variation->get_image_id();
}
unset($data);

$refill_id = (int) wc_get_product_id_by_sku('TV-BUBBLE-MACHINE-REFILL');
if (! $refill_id || get_post_status($refill_id) !== 'publish') {
    throw new RuntimeException('Published refill product not found.');
}
$new_set = '<h3>Choose your set</h3>'
    . '<table><thead><tr><th>Included</th><th>Single Machine Set</th><th>Two-Machine Set</th></tr></thead><tbody>'
    . '<tr><td>Bubble machines</td><td>1</td><td>2</td></tr>'
    . '<tr><td>Sticker sheets</td><td>1</td><td>2</td></tr></tbody></table>'
    . '<p><strong>Bubble solution is sold separately. AA batteries are not included.</strong> Each machine requires 4 AA batteries: 4 for the single-machine set, or 8 in total for the two-machine set. Please supply your own batteries.</p>'
    . '<p>No bubble solution bottle or concentrate sachets are included with either set. '
    . '<a href="' . esc_url(get_permalink($refill_id)) . '">Shop bubble machine refill concentrate separately</a>.</p>';
$description = preg_replace(
    '~<h3>Choose your set</h3>.*?(?=<h3>Compact and refillable</h3>)~s',
    $new_set, $product->get_description(), 1, $replaced
);
if ($replaced !== 1) {
    throw new RuntimeException('Package description changed; review before updating.');
}
$description = str_replace(' Destination and carrier restrictions may apply to included batteries and liquids.', '', $description);
$short = str_replace('Choose a complete single-machine set or a two-machine set.', 'Choose a single-machine or two-machine set.', $product->get_short_description());
$notice = 'Bubble solution is sold separately. Each machine requires 4 AA batteries (not included).';
if (! str_contains($short, $notice)) {
    $short = rtrim($short) . '<p><strong>' . $notice . '</strong></p>';
}
$benefits = preg_split('/\R/', (string) get_post_meta($product->get_id(), '_trovesia_benefits', true));
$benefits = array_values(array_filter($benefits, static fn ($line) => trim($line) !== 'AA batteries and bubble supplies included'));
foreach (['Bubble solution sold separately', 'AA batteries required (not included)'] as $line) {
    if (! in_array($line, $benefits, true)) {
        $benefits[] = $line;
    }
}
$shipping = str_replace(' Destination and carrier restrictions may apply to the included batteries and liquids.', '', (string) get_post_meta($product->get_id(), '_trovesia_shipping', true));

// Finish media generation before switching any live gallery/variation references.
$images = [];
foreach ($definitions as $key => $data) {
    $import_key = 'trovesia-bubble-no-supplies-' . $key . '-20261102';
    $existing = get_posts([
        'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1,
        'fields' => 'ids', 'meta_key' => '_trovesia_import_key', 'meta_value' => $import_key,
    ]);
    if ($existing) {
        $id = (int) $existing[0];
        $path = get_attached_file($id);
        if (! is_readable($path) || hash_file('sha256', $path) !== $manifest[$data['file']]['sha256']) {
            throw new RuntimeException('Existing revision image changed.');
        }
    } else {
        $upload = wp_upload_bits($data['file'], null, file_get_contents('/revision/ready/' . $data['file']));
        if (! empty($upload['error'])) {
            throw new RuntimeException((string) $upload['error']);
        }
        $id = wp_insert_attachment(['post_title' => $data['title'], 'post_mime_type' => 'image/webp', 'post_status' => 'inherit'], $upload['file'], 0, true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $meta = wp_generate_attachment_metadata($id, $upload['file']);
        if (empty($meta['sizes']['woocommerce_single'])) {
            throw new RuntimeException('Missing responsive SKU image.');
        }
        wp_update_attachment_metadata($id, $meta);
        update_post_meta($id, '_trovesia_import_key', $import_key);
    }
    update_post_meta($id, '_wp_attachment_image_alt', $data['alt']);
    $images[$key] = (int) $id;
}
$image_map = [169 => $images['single'], 170 => $images['double']];
foreach ($definitions as $key => $data) {
    $image_map[$data['old_image']] = $images[$key];
}
$gallery = array_map(static fn ($id) => $image_map[$id] ?? $id, $product->get_gallery_image_ids());
$product->set_gallery_image_ids($gallery);
$product->set_short_description($short);
$product->set_description($description);
$product->save();
update_post_meta($product->get_id(), '_trovesia_benefits', implode("\n", $benefits));
update_post_meta($product->get_id(), '_trovesia_shipping', $shipping);
update_post_meta($product->get_id(), '_trovesia_supplies_revision', '20261102-no-supplies');

$result = [];
foreach ($definitions as $key => $data) {
    $v = $data['variation'];
    $v->set_image_id($images[$key]);
    $v->set_regular_price($data['price']);
    $v->set_sale_price('');
    $v->set_description($data['count'] === 1
        ? '1 bubble machine and 1 sticker sheet. Bubble solution sold separately. Requires 4 AA batteries, not included; please supply your own.'
        : '2 bubble machines and 2 sticker sheets. Bubble solution sold separately. Requires 8 AA batteries total, not included; please supply your own.');
    $v->save();
    clean_post_cache($v->get_id());
    wc_delete_product_transients($v->get_id());
    $fresh = new WC_Product_Variation($v->get_id());
    if (wc_format_decimal($fresh->get_price(), 2) !== $data['price'] || $fresh->get_image_id() !== $images[$key]) {
        throw new RuntimeException('Variation update verification failed.');
    }
    $result[] = ['id' => $v->get_id(), 'sku' => $v->get_sku(), 'price' => $data['price'], 'image_id' => $images[$key], 'description' => $fresh->get_description()];
}
WC_Product_Variable::sync($product->get_id());
wc_delete_product_transients($product->get_id());
clean_post_cache($product->get_id());
$fresh = new WC_Product_Variable($product->get_id());
$after = ['name' => $fresh->get_name(), 'slug' => $fresh->get_slug(), 'status' => $fresh->get_status(), 'hero' => $fresh->get_image_id(), 'default_attributes' => $fresh->get_default_attributes(), 'category_ids' => $fresh->get_category_ids()];
if ($after !== $preserved || $fresh->get_gallery_image_ids() !== $gallery) {
    throw new RuntimeException('Preserved product field or gallery verification failed.');
}
echo wp_json_encode(['product_id' => $product->get_id(), 'preserved' => $after, 'url' => get_permalink($product->get_id()), 'gallery' => $gallery, 'variations' => $result, 'refill_url' => get_permalink($refill_id)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
