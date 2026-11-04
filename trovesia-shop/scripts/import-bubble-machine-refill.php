<?php
/** UID 33 WP-CLI import. Mount assets at /import; default draft, REFILL_STATUS=publish to publish. */
if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce is required.');
}
require_once ABSPATH . 'wp-admin/includes/image.php';
$status = getenv('REFILL_STATUS') ?: 'draft';
if (! in_array($status, ['draft', 'publish'], true)) {
    throw new RuntimeException('Invalid status.');
}
$manifest = json_decode(file_get_contents('/import/ready/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$definitions = [
    'hero' => ['bubble-machine-refill-hero-en.webp', 'Bubble machine refill concentrate sachets', 'Pink bubble solution concentrate sachets for refilling a bubble machine; choose ten, thirty or fifty sachets'],
    '10' => ['bubble-machine-refill-10-en.webp', '10 sachets of bubble machine refill concentrate', 'Ten-sachet bubble machine refill pack; pink sachets illustrated, machine not included'],
    '30' => ['bubble-machine-refill-30-en.webp', '30 sachets of bubble machine refill concentrate', 'Thirty-sachet bubble machine refill pack; pink sachets illustrated, machine not included'],
    '50' => ['bubble-machine-refill-50-en.webp', '50 sachets of bubble machine refill concentrate', 'Fifty-sachet bubble machine refill pack; pink sachets illustrated, machine not included'],
];
$parent_sku = 'TV-BUBBLE-MACHINE-REFILL';
$parent_id = (int) wc_get_product_id_by_sku($parent_sku);
$product = $parent_id ? wc_get_product($parent_id) : new WC_Product_Variable();
if (! $product instanceof WC_Product_Variable
    || ($parent_id && get_post_meta($parent_id, '_trovesia_source_handle', true) !== 'bubble-machine-refill')) {
    throw new RuntimeException('Parent SKU collision.');
}
foreach ($definitions as [$filename]) {
    $source = '/import/ready/' . $filename;
    $info = is_readable($source) ? wp_getimagesize($source) : false;
    if (! isset($manifest[$filename]) || ! $info || ($info['mime'] ?? '') !== 'image/webp'
        || filesize($source) > 500000 || hash_file('sha256', $source) !== $manifest[$filename]['sha256']) {
        throw new RuntimeException('Invalid optimized asset: ' . $filename);
    }
}
$images = [];
foreach ($definitions as $key => [$filename, $title, $alt]) {
    $import_key = 'trovesia-bubble-refill-' . sanitize_key(pathinfo($filename, PATHINFO_FILENAME));
    $existing = get_posts([
        'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1,
        'fields' => 'ids', 'meta_key' => '_trovesia_import_key', 'meta_value' => $import_key,
    ]);
    if ($existing) {
        $id = (int) $existing[0];
        $file = get_attached_file($id);
        if (! is_readable($file) || hash_file('sha256', $file) !== $manifest[$filename]['sha256']) {
            throw new RuntimeException('Existing asset changed.');
        }
    } else {
        $upload = wp_upload_bits($filename, null, file_get_contents('/import/ready/' . $filename));
        if (! empty($upload['error'])) {
            throw new RuntimeException((string) $upload['error']);
        }
        $id = wp_insert_attachment(['post_mime_type' => 'image/webp', 'post_title' => $title, 'post_status' => 'inherit'], $upload['file'], 0, true);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $metadata = wp_generate_attachment_metadata($id, $upload['file']);
        if (empty($metadata['sizes']['woocommerce_single'])) {
            throw new RuntimeException('Missing responsive product image.');
        }
        wp_update_attachment_metadata($id, $metadata);
        update_post_meta($id, '_trovesia_import_key', $import_key);
    }
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    $images[$key] = (int) $id;
}
$category = get_term_by('slug', 'party-celebration', 'product_cat');
if (! $category instanceof WP_Term) {
    throw new RuntimeException('Expected Party & Celebration category is missing.');
}
$attribute = new WC_Product_Attribute();
$attribute->set_id(0);
$attribute->set_name('Pack');
$attribute->set_options(['10 Sachets', '30 Sachets', '50 Sachets']);
$attribute->set_visible(true);
$attribute->set_variation(true);
$attribute->set_position(0);
$product->set_name('Bubble Machine Refill Concentrate Sachets');
$product->set_slug('bubble-machine-refill-concentrate-sachets');
$product->set_sku($parent_sku);
$product->set_status('draft');
$product->set_catalog_visibility('visible');
$product->set_category_ids([(int) $category->term_id]);
$product->set_attributes([$attribute]);
$product->set_default_attributes([]);
$product->set_image_id($images['hero']);
$product->set_gallery_image_ids([$images['10'], $images['30'], $images['50']]);
$product->set_manage_stock(false);
$product->set_stock_status('instock');
$product->set_reviews_allowed(true);
$product->set_short_description('<p>Bubble solution concentrate for refilling your bubble machine. Choose a pack of 10, 30 or 50 individual sachets. <strong>Refill liquid only — bubble machine not included.</strong> Dilute according to the sachet instructions before use.</p>');
$machine_id = (int) wc_get_product_id_by_sku('TV-2026-FIREWORK-BUBBLE');
$machine_link = $machine_id && get_post_status($machine_id) === 'publish'
    ? '<p>Need a machine? Explore the <a href="' . esc_url(get_permalink($machine_id)) . '">2026 Firework Bubble Machine with Ambient Lights</a>, sold separately.</p>' : '';
$product->set_description(
    '<h2>More refills for your bubble machine</h2>'
    . '<p>Keep bubble play going with individually packed bubble solution concentrate. This listing is specifically for <strong>bubble machine refill liquid</strong>, supplied in pink sachets. It does not include a bubble machine, solution bottle, batteries or other machine accessories.</p>'
    . '<h3>Choose your refill pack</h3><ul>'
    . '<li><strong>10 Sachets:</strong> 10 individual concentrate sachets — $12.90.</li>'
    . '<li><strong>30 Sachets:</strong> 30 individual concentrate sachets — $19.90.</li>'
    . '<li><strong>50 Sachets:</strong> 50 individual concentrate sachets — $29.90.</li></ul>'
    . '<h3>Before refilling</h3><p>These are concentrate sachets, not ready-to-use bottled solution. Follow the dilution instructions printed on the sachet and the filling instructions supplied with your bubble machine. Use only with equipment designed for the prepared bubble solution. Do not overfill the reservoir.</p>'
    . '<p>Images illustrate the sachet packaging; the pack size you select determines how many sachets you receive. Original packaging may include Chinese print.</p>'
    . '<h3>Care and storage</h3><p>Bubble solution is not for drinking. Avoid contact with eyes and mouths. Adult supervision is recommended when preparing refills or using a bubble machine. Store sachets closed and wipe up spills to avoid slippery surfaces.</p>'
    . '<h3>Delivery</h3><p>Estimated delivery: 5–15 days. Destination and carrier restrictions may apply to liquids.</p>'
    . $machine_link
);
$parent_id = $product->save();
update_post_meta($parent_id, '_trovesia_source_handle', 'bubble-machine-refill');
update_post_meta($parent_id, '_trovesia_source_url', '');
update_post_meta($parent_id, '_trovesia_eyebrow', 'Bubble Machine Refill · Concentrate Sachets');
update_post_meta($parent_id, '_trovesia_benefits', "Refill concentrate for bubble machines\nChoose 10, 30 or 50 sachets\nIndividually packed\nBubble machine not included\nDilute as directed on the sachet\nEstimated delivery in 5–15 days");
update_post_meta($parent_id, '_trovesia_shipping', 'Estimated delivery is 5–15 days. Destination and carrier restrictions may apply to liquids.');
update_post_meta($parent_id, '_trovesia_delivery_min_days', 5);
update_post_meta($parent_id, '_trovesia_delivery_max_days', 15);
update_post_meta($parent_id, '_trovesia_guarantee', 'Returns are handled according to our store return policy. Please contact customer care for assistance.');
update_post_meta($parent_id, '_trovesia_order_activity', '');
$variation_ids = [];
$expected = ['10' => '12.90', '30' => '19.90', '50' => '29.90'];
foreach ($expected as $count => $price) {
    $sku = 'TV-BUBBLE-REFILL-' . $count;
    $id = (int) wc_get_product_id_by_sku($sku);
    $v = $id ? wc_get_product($id) : new WC_Product_Variation();
    if (! $v instanceof WC_Product_Variation || ($id && $v->get_parent_id() !== $parent_id)) {
        throw new RuntimeException('Variation SKU collision.');
    }
    $v->set_parent_id($parent_id);
    $v->set_sku($sku);
    $v->set_status('publish');
    $v->set_attributes(['pack' => $count . ' Sachets']);
    $v->set_regular_price($price);
    $v->set_sale_price('');
    $v->set_image_id($images[$count]);
    $v->set_manage_stock(false);
    $v->set_stock_status('instock');
    $v->set_description($count . ' individual bubble solution concentrate sachets. Refill liquid only; bubble machine not included. Dilute as directed on the packet.');
    $variation_ids[$sku] = $v->save();
}
WC_Product_Variable::sync($parent_id);
wc_delete_product_transients($parent_id);
$product = new WC_Product_Variable($parent_id);
$prices = [];
foreach ($product->get_children() as $id) {
    $v = wc_get_product($id);
    $prices[$v->get_sku()] = wc_format_decimal($v->get_regular_price(), 2);
}
if (count($prices) !== 3 || $product->get_default_attributes() !== []) {
    throw new RuntimeException('Unexpected variation count or defaults; left as draft.');
}
foreach ($expected as $count => $price) {
    if (($prices['TV-BUBBLE-REFILL-' . $count] ?? '') !== $price) {
        throw new RuntimeException('Price verification failed; left as draft.');
    }
}
$product->set_status($status);
$product->save();
wc_delete_product_transients($parent_id);
clean_post_cache($parent_id);
echo wp_json_encode([
    'product_id' => $parent_id, 'status' => $product->get_status(), 'url' => get_permalink($parent_id),
    'images' => $images, 'variations' => $variation_ids, 'prices' => $prices,
    'default_attributes' => $product->get_default_attributes(), 'delivery_days' => [5, 15],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
