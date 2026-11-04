<?php
/**
 * Import one standalone variable refill product and attach selected variations
 * to the Christmas spark wand gift set as add-ons.
 *
 * Run through WP-CLI with the ready asset directory mounted at /import.
 */

if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active.');
}

require_once ABSPATH . 'wp-admin/includes/image.php';

function trovesia_refill_import_image(string $filename, string $title, string $alt): int
{
    $import_key = 'trovesia-spark-refill-' . sanitize_key(pathinfo($filename, PATHINFO_FILENAME));
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
        'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
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

function trovesia_refill_category(): WP_Term
{
    $category = get_term_by('slug', 'refills-accessories', 'product_cat');
    if (! $category instanceof WP_Term) {
        $created = wp_insert_term('Refills & Accessories', 'product_cat', ['slug' => 'refills-accessories']);
        if (is_wp_error($created)) {
            throw new RuntimeException($created->get_error_message());
        }
        $category = get_term((int) $created['term_id'], 'product_cat');
    }
    if (! $category instanceof WP_Term) {
        throw new RuntimeException('Unable to create the Refills & Accessories category.');
    }

    return $category;
}

$main_product_id = (int) wc_get_product_id_by_sku('TV-XMAS-SPARK-GIFT-SET');
$main_product = $main_product_id > 0 ? wc_get_product($main_product_id) : false;
if (! $main_product instanceof WC_Product_Variable) {
    throw new RuntimeException('The Christmas spark gift set could not be found.');
}

$category = trovesia_refill_category();
$images = [
    'chamber' => trovesia_refill_import_image(
        'replacement-material-chamber-en.png',
        'Replacement spark material chamber',
        'Replacement material chamber for the Christmas spark atmosphere wand'
    ),
    'butane' => trovesia_refill_import_image(
        'butane-gas-canister-en.png',
        'High-pressure butane gas canister',
        'Dedicated 200 mL high-pressure butane gas canister for the Christmas spark atmosphere wand'
    ),
];

$parent_sku = 'TV-SPARK-REFILL-PACKS';
$refill_product_id = (int) wc_get_product_id_by_sku($parent_sku);
$refill_product = $refill_product_id > 0 ? wc_get_product($refill_product_id) : new WC_Product_Variable();
if (! $refill_product instanceof WC_Product_Variable) {
    throw new RuntimeException('The refill parent SKU is already assigned to a non-variable product.');
}

$options = [
    'Material Chambers — 2 Pack',
    'Material Chambers — 4 Pack',
    'Material Chambers — 6 Pack',
    'Butane Gas Canisters — 2 Pack',
    'Butane Gas Canisters — 4 Pack',
    'Butane Gas Canisters — 6 Pack',
];
$attribute = new WC_Product_Attribute();
$attribute->set_id(0);
$attribute->set_name('Refill Option');
$attribute->set_options($options);
$attribute->set_position(0);
$attribute->set_visible(true);
$attribute->set_variation(true);

$refill_product->set_name('Spark Atmosphere Wand Refill Packs');
$refill_product->set_slug('spark-atmosphere-wand-refill-packs');
$refill_product->set_status('publish');
$refill_product->set_catalog_visibility('visible');
$refill_product->set_sku($parent_sku);
$refill_product->set_category_ids([(int) $category->term_id]);
$refill_product->set_image_id($images['chamber']);
$refill_product->set_gallery_image_ids([$images['butane']]);
$refill_product->set_attributes([$attribute]);
$refill_product->set_default_attributes(['refill-option' => $options[0]]);
$refill_product->set_short_description(
    '<p>Choose sealed replacement material chambers or dedicated 200 mL high-pressure butane canisters for the Christmas Spark Atmosphere Wand. Available in 2-, 4- and 6-packs.</p>'
);
$refill_product->set_description(
    '<h2>Choose your refill pack</h2><p>This standalone refill listing lets you choose one material-chamber or butane-canister pack at a time. Add another option to the cart separately if you need both types.</p>'
    . '<h3>Replacement material chambers</h3><p>Sealed compatible chambers. Supplier-marked net material content is approximately 45 g per chamber (±5 g). Do not open, refill or modify a chamber. Store sealed, cool and dry, away from children, heat, ignition sources and flammable materials.</p>'
    . '<h3>High-pressure butane gas canisters</h3><p>Each dedicated canister contains 200 mL of butane. <strong>Extremely flammable aerosol. Pressurised container.</strong> Keep away from heat, hot surfaces, sparks, open flames and other ignition sources. Do not smoke nearby, puncture or burn the container, even after use. Protect from sunlight and temperatures above 50°C / 122°F. Keep out of reach of children and use outdoors only.</p>'
    . '<h3>Shipping restrictions</h3><p>Butane canisters can only ship where permitted by the destination and carrier. An order may be unavailable or cancelled if compliant transport is not available. Estimated delivery is 8–15 days where eligible.</p>'
);
$refill_product_id = $refill_product->save();

update_post_meta($refill_product_id, '_trovesia_eyebrow', 'Compatible Refill Packs');
update_post_meta($refill_product_id, '_trovesia_benefits', "Choose chambers or butane canisters\n2-, 4- and 6-pack options\nCompatible sealed refill units\nEstimated delivery in 8–15 days");
update_post_meta($refill_product_id, '_trovesia_guarantee', 'Unused, unopened items may be returned within 30 days, subject to the condition and safety restrictions in our return policy.');
update_post_meta($refill_product_id, '_trovesia_shipping', 'Estimated delivery is 8–15 days where eligible. Butane canisters are subject to destination and carrier restrictions.');
update_post_meta($refill_product_id, '_trovesia_source_handle', 'focus-spark-atmosphere-wand-refills');
update_post_meta($refill_product_id, '_trovesia_source_url', '');

$variation_data = [
    'Material Chambers — 2 Pack' => ['sku' => 'TV-SPARK-REFILL-CHAMBER-2', 'price' => '29.90', 'image' => $images['chamber'], 'kind' => 'chamber', 'pack' => 2],
    'Material Chambers — 4 Pack' => ['sku' => 'TV-SPARK-REFILL-CHAMBER-4', 'price' => '39.90', 'image' => $images['chamber'], 'kind' => 'chamber', 'pack' => 4],
    'Material Chambers — 6 Pack' => ['sku' => 'TV-SPARK-REFILL-CHAMBER-6', 'price' => '45.00', 'image' => $images['chamber'], 'kind' => 'chamber', 'pack' => 6],
    'Butane Gas Canisters — 2 Pack' => ['sku' => 'TV-SPARK-REFILL-BUTANE-2', 'price' => '29.90', 'image' => $images['butane'], 'kind' => 'butane', 'pack' => 2],
    'Butane Gas Canisters — 4 Pack' => ['sku' => 'TV-SPARK-REFILL-BUTANE-4', 'price' => '39.90', 'image' => $images['butane'], 'kind' => 'butane', 'pack' => 4],
    'Butane Gas Canisters — 6 Pack' => ['sku' => 'TV-SPARK-REFILL-BUTANE-6', 'price' => '45.00', 'image' => $images['butane'], 'kind' => 'butane', 'pack' => 6],
];

$variation_ids = [];
foreach ($variation_data as $option => $data) {
    $variation_id = (int) wc_get_product_id_by_sku($data['sku']);
    $variation = $variation_id > 0 ? wc_get_product($variation_id) : new WC_Product_Variation();
    if (! $variation instanceof WC_Product_Variation) {
        throw new RuntimeException('Variation SKU is already assigned to an unexpected product: ' . $data['sku']);
    }

    $variation->set_parent_id($refill_product_id);
    $variation->set_status('publish');
    $variation->set_sku($data['sku']);
    $variation->set_attributes(['refill-option' => $option]);
    $variation->set_regular_price($data['price']);
    $variation->set_sale_price('');
    $variation->set_image_id((int) $data['image']);
    $variation->set_manage_stock(false);
    $variation->set_stock_status('instock');
    $variation->set_description($data['kind'] === 'butane'
        ? sprintf('%d dedicated 200 mL high-pressure butane gas canisters. Extremely flammable pressurised containers; shipping restrictions apply.', $data['pack'])
        : sprintf('%d sealed replacement material chambers, approximately 45 g each (±5 g).', $data['pack']));
    $variation_id = $variation->save();
    $variation_ids[$data['kind'] . '_' . $data['pack']] = $variation_id;

    update_post_meta($variation_id, '_trovesia_shipping', $data['kind'] === 'butane'
        ? 'Estimated delivery is 8–15 days where eligible. Pressurised butane is subject to destination and carrier restrictions.'
        : 'Estimated delivery is 8–15 days.');
    if ($data['kind'] === 'butane') {
        update_post_meta($variation_id, '_trovesia_pressurised_gas_included', 'yes');
    } else {
        delete_post_meta($variation_id, '_trovesia_pressurised_gas_included');
    }
}

WC_Product_Variable::sync($refill_product_id);
wc_delete_product_transients($refill_product_id);
clean_post_cache($refill_product_id);

// This is a new store with no legacy orders to preserve, so remove the six
// temporary simple products after their replacements have been created.
$legacy_skus = [
    'TV-SPARK-CHAMBER-2',
    'TV-SPARK-CHAMBER-4',
    'TV-SPARK-CHAMBER-6',
    'TV-BUTANE-CANISTER-2',
    'TV-BUTANE-CANISTER-4',
    'TV-BUTANE-CANISTER-6',
];
$deleted_product_ids = [];
foreach ($legacy_skus as $legacy_sku) {
    $legacy_id = (int) wc_get_product_id_by_sku($legacy_sku);
    $legacy = $legacy_id > 0 ? wc_get_product($legacy_id) : false;
    if (! $legacy instanceof WC_Product_Simple) {
        continue;
    }
    if (wp_delete_post($legacy_id, true)) {
        $deleted_product_ids[] = $legacy_id;
    }
}

$existing_rules = get_post_meta($main_product_id, '_trovesia_addon_rules', true);
$existing_rules = is_array($existing_rules) ? $existing_rules : [];
$existing_rules = array_values(array_filter($existing_rules, static function ($rule): bool {
    $rule_id = is_array($rule) ? (string) ($rule['id'] ?? '') : '';

    return ! str_starts_with($rule_id, 'spark-chamber-') && ! str_starts_with($rule_id, 'spark-butane-');
}));

$addon_rules = [];
foreach ([2, 4, 6] as $pack_size) {
    $addon_rules[] = [
        'id'                => 'spark-chamber-' . $pack_size,
        'product_id'        => $variation_ids['chamber_' . $pack_size],
        'title'             => sprintf('%d pack', $pack_size),
        'selection_group'   => 'spark-material-chambers',
        'group_title'       => 'Replacement Spark Material Chambers',
        'group_description' => 'Choose one optional pack size. Each sealed chamber is supplier-marked approximately 45 g (±5 g).',
        'pricing_mode'      => 'follow',
        'fixed_price'       => 0,
        'compare_at_price'  => 0,
        'discount_percent'  => 0,
        'quantity'          => 1,
        'default_selected'  => false,
        'allowed_values'    => [],
    ];
    $addon_rules[] = [
        'id'                => 'spark-butane-' . $pack_size,
        'product_id'        => $variation_ids['butane_' . $pack_size],
        'title'             => sprintf('%d pack', $pack_size),
        'selection_group'   => 'butane-gas-canisters',
        'group_title'       => 'High-Pressure Butane Gas Canisters',
        'group_description' => 'Choose one optional pack size. Extremely flammable pressurised containers; shipping restrictions apply.',
        'pricing_mode'      => 'follow',
        'fixed_price'       => 0,
        'compare_at_price'  => 0,
        'discount_percent'  => 0,
        'quantity'          => 1,
        'default_selected'  => false,
        'allowed_values'    => [],
    ];
}

update_post_meta($main_product_id, '_trovesia_addon_rules', array_merge($existing_rules, $addon_rules));
wc_delete_product_transients($main_product_id);
clean_post_cache($main_product_id);

echo wp_json_encode([
    'main_product_id'     => $main_product_id,
    'refill_product_id'   => $refill_product_id,
    'refill_product_sku'  => $parent_sku,
    'category_id'         => (int) $category->term_id,
    'images'              => $images,
    'variation_ids'       => $variation_ids,
    'deleted_product_ids' => $deleted_product_ids,
    'addon_rule_ids'      => array_column($addon_rules, 'id'),
    'permalink'           => get_permalink($refill_product_id),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
