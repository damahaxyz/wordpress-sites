<?php

$product = wc_get_product(31);
if (!$product instanceof WC_Product_Variable) {
    WP_CLI::error('Product 31 is not a variable product.');
}

$wallScenes = [
    '01-natural-wood-living-room-wall.png' => [
        'title' => 'Natural wood pet portrait hanging in a living room',
        'alt' => 'Natural wood custom pet portrait frame with a real white mat hanging above a sofa',
    ],
    '02-black-wood-hallway-wall.png' => [
        'title' => 'Black wood pet portrait hanging in a hallway',
        'alt' => 'Black wood-grain custom pet portrait frame with a real white mat hanging above a console',
    ],
];

$attachments = [];
foreach ($wallScenes as $filename => $metadata) {
    $ids = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => [[
            'key' => '_wp_attached_file',
            'value' => '/' . $filename,
            'compare' => 'LIKE',
        ]],
    ]);
    if (!$ids) {
        WP_CLI::error('Missing imported image: ' . $filename);
    }
    $id = (int) $ids[0];
    $attachments[] = $id;
    wp_update_post(['ID' => $id, 'post_title' => $metadata['title']]);
    update_post_meta($id, '_wp_attachment_image_alt', $metadata['alt']);
}

$gallery = array_map('intval', $product->get_gallery_image_ids());
$backupKey = 'tikool_product_31_gallery_before_wall_scenes_20260921';
if (get_option($backupKey, null) === null) {
    add_option($backupKey, [
        'created_at' => gmdate('c'),
        'featured_image_id' => $product->get_image_id(),
        'gallery_image_ids' => $gallery,
    ], '', false);
}

foreach ($attachments as $id) {
    if (!in_array($id, $gallery, true)) {
        $gallery[] = $id;
    }
}
$product->set_gallery_image_ids($gallery);
$product->save();
wc_delete_product_transients(31);
clean_post_cache(31);

WP_CLI::success('Product 31 gallery: ' . implode(',', $gallery));
