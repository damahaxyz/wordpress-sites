<?php
/**
 * Import or update the DIY Beaded Holiday Tree Craft Kit.
 *
 * Run through WP-CLI with product-assets/diy-beaded-holiday-tree
 * mounted at /import.
 */

if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active.');
}

require_once ABSPATH . 'wp-admin/includes/image.php';

function trovesia_beaded_tree_import_image(string $filename, string $title, string $alt): int
{
    $import_key = 'trovesia-diy-beaded-holiday-tree-' . sanitize_key(pathinfo($filename, PATHINFO_FILENAME));
    $existing = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_trovesia_import_key',
        'meta_value'     => $import_key,
    ]);
    if ($existing) {
        $attachment_id = (int) $existing[0];
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);

        return $attachment_id;
    }

    $source = '/import/' . ltrim($filename, '/');
    if (! is_readable($source)) {
        throw new RuntimeException('Missing product image: ' . $source);
    }

    $upload = wp_upload_bits(sanitize_file_name(basename($source)), null, file_get_contents($source));
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

function trovesia_beaded_tree_category(): WP_Term
{
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

    return $category;
}

$category = trovesia_beaded_tree_category();
$images = [
    'hero' => trovesia_beaded_tree_import_image(
        'ready/diy-beaded-tree-kit-hero-en-webp.webp',
        'DIY beaded Christmas tree kit with components',
        'DIY beaded Christmas tree kit showing colourful beads, branches, stand, angel topper and finished tree'
    ),
    'a001' => trovesia_beaded_tree_import_image(
        'source/2.jpg',
        'A001 Classic Christmas beaded holiday tree kit',
        'A001 Classic Christmas DIY beaded tabletop holiday tree craft kit'
    ),
    'a002' => trovesia_beaded_tree_import_image(
        'source/3.jpg',
        'A002 Winter Frost beaded holiday tree kit',
        'A002 Winter Frost DIY blue beaded tabletop tree craft kit'
    ),
    'a003' => trovesia_beaded_tree_import_image(
        'source/4.jpg',
        'A003 Faith and Peace beaded holiday tree kit',
        'A003 Faith and Peace DIY beaded tabletop holiday tree craft kit'
    ),
    'a004' => trovesia_beaded_tree_import_image(
        'source/5.jpg',
        'A004 Autumn Glow beaded holiday tree kit',
        'A004 Autumn Glow DIY orange beaded tabletop tree craft kit'
    ),
    'a005' => trovesia_beaded_tree_import_image(
        'source/6.jpg',
        'A005 Halloween Glow beaded holiday tree kit',
        'A005 Halloween Glow DIY orange and black beaded tabletop tree craft kit'
    ),
    'detail' => trovesia_beaded_tree_import_image(
        'source/7.jpg',
        'Finished beaded tabletop holiday tree',
        'Finished pale green and gold beaded tabletop holiday tree on a stand'
    ),
];

$options = [
    'A001 — Classic Christmas',
    'A002 — Winter Frost',
    'A003 — Faith & Peace',
    'A004 — Autumn Glow',
    'A005 — Halloween Glow',
    'Random Style',
];

$attribute = new WC_Product_Attribute();
$attribute->set_id(0);
$attribute->set_name('Style');
$attribute->set_options($options);
$attribute->set_position(0);
$attribute->set_visible(true);
$attribute->set_variation(true);

$parent_sku = 'TV-DIY-BEADED-HOLIDAY-TREE';
$parent_id = (int) wc_get_product_id_by_sku($parent_sku);
$product = $parent_id > 0 ? wc_get_product($parent_id) : new WC_Product_Variable();
if (! $product instanceof WC_Product_Variable) {
    throw new RuntimeException('The parent SKU is already assigned to a non-variable product.');
}

$product->set_name('DIY Beaded Holiday Tree Craft Kit');
$product->set_slug('diy-beaded-holiday-tree-craft-kit');
$product->set_status('publish');
$product->set_catalog_visibility('visible');
$product->set_sku($parent_sku);
$product->set_category_ids([(int) $category->term_id]);
$product->set_image_id($images['hero']);
$product->set_gallery_image_ids([
    $images['a001'],
    $images['a002'],
    $images['a003'],
    $images['a004'],
    $images['a005'],
    $images['detail'],
]);
$product->set_attributes([$attribute]);
$product->set_default_attributes(['style' => $options[0]]);
$product->set_manage_stock(false);
$product->set_stock_status('instock');
$product->set_reviews_allowed(true);
$product->set_short_description(
    '<p>Create a sparkling tabletop tree with a coordinated DIY bead kit. Choose one of five themed styles, or select Random Style for a surprise design.</p>'
);
$product->set_description(
    '<h2>Make your own tabletop holiday tree</h2>'
    . '<p>Turn a relaxed craft session into a colourful seasonal decoration. Each style is a coordinated DIY kit for assembling one compact beaded tree that can be displayed on a desk, shelf or festive table.</p>'
    . '<h3>Choose your style</h3><ul>'
    . '<li><strong>A001 — Classic Christmas:</strong> traditional green with bright multicolour ornaments.</li>'
    . '<li><strong>A002 — Winter Frost:</strong> icy blue and silver winter tones.</li>'
    . '<li><strong>A003 — Faith &amp; Peace:</strong> pale green and gold with faith-inspired holiday details.</li>'
    . '<li><strong>A004 — Autumn Glow:</strong> warm amber, orange and fall-inspired tones.</li>'
    . '<li><strong>A005 — Halloween Glow:</strong> orange and black seasonal styling.</li>'
    . '<li><strong>Random Style:</strong> we will select one available design from A001–A005. Style requests cannot be guaranteed.</li>'
    . '</ul>'
    . '<h3>Please note</h3>'
    . '<p>Kit colours, bead placement and the finished appearance vary by style and by maker. Lifestyle scenes may use ambient decorative lighting. Small parts are included: this is not suitable for children under 3, and adult supervision is recommended.</p>'
);
$parent_id = $product->save();

wp_set_object_terms($parent_id, 'christmas', 'product_tag', true);
update_post_meta($parent_id, '_trovesia_eyebrow', 'Creative Holiday Craft Kit');
update_post_meta($parent_id, '_trovesia_benefits', "Five named designs plus Random Style\nOne tabletop tree craft kit\nSeasonal handmade decoration\nGiftable creative activity\nEstimated delivery in 5–10 days");
update_post_meta($parent_id, '_trovesia_guarantee', 'Unused, unopened kits may be returned within 30 days, subject to our return policy.');
update_post_meta($parent_id, '_trovesia_shipping', 'Estimated delivery is 5–10 days.');
update_post_meta($parent_id, '_trovesia_delivery_min_days', 5);
update_post_meta($parent_id, '_trovesia_delivery_max_days', 10);
update_post_meta($parent_id, '_trovesia_order_activity', '');
update_post_meta($parent_id, '_trovesia_source_handle', 'diy-beaded-holiday-tree-craft-kit');
update_post_meta($parent_id, '_trovesia_source_url', '');

$variation_data = [
    'A001 — Classic Christmas' => [
        'sku' => 'TV-BEADED-TREE-A001',
        'image' => $images['a001'],
        'description' => 'A001 Classic Christmas style with traditional green and multicolour holiday accents.',
    ],
    'A002 — Winter Frost' => [
        'sku' => 'TV-BEADED-TREE-A002',
        'image' => $images['a002'],
        'description' => 'A002 Winter Frost style with icy blue and silver winter tones.',
    ],
    'A003 — Faith & Peace' => [
        'sku' => 'TV-BEADED-TREE-A003',
        'image' => $images['a003'],
        'description' => 'A003 Faith & Peace style with pale green, gold and faith-inspired holiday details.',
    ],
    'A004 — Autumn Glow' => [
        'sku' => 'TV-BEADED-TREE-A004',
        'image' => $images['a004'],
        'description' => 'A004 Autumn Glow style with warm amber, orange and fall-inspired tones.',
    ],
    'A005 — Halloween Glow' => [
        'sku' => 'TV-BEADED-TREE-A005',
        'image' => $images['a005'],
        'description' => 'A005 Halloween Glow style with orange and black seasonal details.',
    ],
    'Random Style' => [
        'sku' => 'TV-BEADED-TREE-RANDOM',
        'image' => $images['detail'],
        'description' => 'One available style selected at random from A001–A005. Specific style requests cannot be guaranteed.',
    ],
];

$variation_ids = [];
foreach ($variation_data as $style => $data) {
    $variation_id = (int) wc_get_product_id_by_sku($data['sku']);
    $variation = $variation_id > 0 ? wc_get_product($variation_id) : new WC_Product_Variation();
    if (! $variation instanceof WC_Product_Variation) {
        throw new RuntimeException('Variation SKU is already assigned to an unexpected product: ' . $data['sku']);
    }

    $variation->set_parent_id($parent_id);
    $variation->set_status('publish');
    $variation->set_sku($data['sku']);
    $variation->set_attributes(['style' => $style]);
    $variation->set_regular_price('24.90');
    $variation->set_sale_price('19.90');
    $variation->set_image_id((int) $data['image']);
    $variation->set_manage_stock(false);
    $variation->set_stock_status('instock');
    $variation->set_description($data['description']);
    $variation_ids[$data['sku']] = $variation->save();
}

WC_Product_Variable::sync($parent_id);
wc_delete_product_transients($parent_id);
clean_post_cache($parent_id);

echo wp_json_encode([
    'product_id' => $parent_id,
    'sku' => $parent_sku,
    'status' => get_post_status($parent_id),
    'permalink' => get_permalink($parent_id),
    'regular_price' => '24.90',
    'sale_price' => '19.90',
    'variations' => $variation_ids,
    'tagged_christmas' => has_term('christmas', 'product_tag', $parent_id),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
