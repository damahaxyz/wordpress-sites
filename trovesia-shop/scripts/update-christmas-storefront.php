<?php
/** Switch the configurable storefront and primary menu to the Christmas gift set. */

if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active.');
}

require_once ABSPATH . 'wp-admin/includes/image.php';

function trovesia_christmas_storefront_image(string $filename, string $title, string $alt): int
{
    $import_key = 'trovesia-christmas-storefront-' . sanitize_key(str_replace('.', '-', $filename));
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

    $source = '/import/ready/' . $filename;
    if (! is_readable($source)) {
        throw new RuntimeException('Missing storefront image: ' . $source);
    }

    $upload = wp_upload_bits($filename, null, file_get_contents($source));
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

$product_id = wc_get_product_id_by_sku('TV-XMAS-SPARK-GIFT-SET');
$product = $product_id ? wc_get_product($product_id) : null;
if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
    throw new RuntimeException('The published Christmas gift set could not be found.');
}

$front_page_id = (int) get_option('page_on_front');
if ($front_page_id < 1 || get_post_type($front_page_id) !== 'page') {
    throw new RuntimeException('The static front page could not be found.');
}

$desktop_image_id = trovesia_christmas_storefront_image(
    'christmas-storefront-desktop.png',
    'Trovesia Christmas gift set desktop banner',
    'Five Christmas spark atmosphere wand gift sets surrounded by warm holiday lights'
);
$mobile_image_id = trovesia_christmas_storefront_image(
    'christmas-storefront-mobile.png',
    'Trovesia Christmas gift set mobile banner',
    'Five Christmas spark atmosphere wand gift sets in a festive vertical scene'
);

$banner = [
    'enabled'          => '1',
    'desktop_image_id' => $desktop_image_id,
    'mobile_image_id'  => $mobile_image_id,
    'kicker'           => 'Christmas moments, made brighter',
    'heading'          => 'Make the season sparkle.',
    'lead'             => 'A complete outdoor celebration gift set in five festive colours, packed with the wand, material chamber and one small gas canister.',
    'primary_label'    => 'Shop the gift set',
    'primary_url'      => get_permalink($product_id),
    'secondary_label'  => 'See what is included',
    'secondary_url'    => '#gift-set',
    'note'             => 'Complete gift set · Five colours · Delivery in 8–15 days',
    'desktop_position' => 'center center',
    'mobile_position'  => 'center center',
    'text_alignment'   => 'left',
    'overlay_color'    => '#10251f',
    'text_color'       => '#fffefb',
    'overlay_opacity'  => '60',
];
foreach ($banner as $key => $value) {
    update_post_meta($front_page_id, '_trovesia_home_banner_' . $key, $value);
}

update_post_meta($front_page_id, '_trovesia_home_products_enabled', '1');
update_post_meta($front_page_id, '_trovesia_home_products_kicker', 'Christmas Collection');
update_post_meta($front_page_id, '_trovesia_home_products_heading', 'Festive finds for every celebration.');
update_post_meta($front_page_id, '_trovesia_home_products_description', 'Explore seasonal gifts and celebration essentials selected for Christmas.');
update_post_meta($front_page_id, '_trovesia_home_products_tag', 'christmas');
delete_post_meta($front_page_id, '_trovesia_home_products_product_ids');
update_post_meta($front_page_id, '_trovesia_home_products_columns', '3');

$christmas_term = get_term_by('slug', 'christmas', 'product_tag');
if (! $christmas_term instanceof WP_Term) {
    $created = wp_insert_term('Christmas', 'product_tag', ['slug' => 'christmas']);
    if (is_wp_error($created)) {
        throw new RuntimeException($created->get_error_message());
    }
}
$christmas_product_ids = [$product_id];
$refill_product_id = (int) wc_get_product_id_by_sku('TV-SPARK-REFILL-PACKS');
if ($refill_product_id > 0) {
    $christmas_product_ids[] = $refill_product_id;
}
foreach ($christmas_product_ids as $christmas_product_id) {
    $result = wp_set_object_terms($christmas_product_id, 'christmas', 'product_tag', true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
}

$menu = wp_get_nav_menu_object('nav');
$menu_item_id = 0;
if ($menu instanceof WP_Term) {
    foreach ((array) wp_get_nav_menu_items($menu->term_id) as $item) {
        if ($item instanceof WP_Post && (strcasecmp($item->post_title, 'Shop Now') === 0 || (int) $item->object_id === 20)) {
            $menu_item_id = (int) $item->ID;
            $result = wp_update_nav_menu_item((int) $menu->term_id, $menu_item_id, [
                'menu-item-db-id'       => $menu_item_id,
                'menu-item-object-id'   => $product_id,
                'menu-item-object'      => 'product',
                'menu-item-parent-id'   => (int) $item->menu_item_parent,
                'menu-item-position'    => (int) $item->menu_order,
                'menu-item-type'        => 'post_type',
                'menu-item-title'       => 'Shop Now',
                'menu-item-status'      => 'publish',
            ]);
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }
            break;
        }
    }
}
if ($menu_item_id < 1) {
    throw new RuntimeException('The Shop Now navigation item could not be found.');
}

clean_post_cache($front_page_id);

echo wp_json_encode([
    'front_page_id'   => $front_page_id,
    'product_id'      => $product_id,
    'desktop_image'   => $desktop_image_id,
    'mobile_image'    => $mobile_image_id,
    'menu_item_id'    => $menu_item_id,
    'shop_url'        => get_permalink($product_id),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
