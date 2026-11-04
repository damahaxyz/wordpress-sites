<?php
/**
 * Run as UID 33 via WP-CLI after a private backup and baseline check.
 * Mount this script and optimized/ read-only together at /optimization, then:
 * wp eval-file /optimization/apply-christmas-image-optimization.php
 * Preserves attachment IDs, import keys, alt text and the original PNG files.
 */
if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WooCommerce')) {
    throw new RuntimeException('Requires WooCommerce and WP-CLI.');
}
require_once ABSPATH . 'wp-admin/includes/image.php';

$rows = json_decode(file_get_contents('/optimization/optimized/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (count($rows) !== 13 || ! wp_image_editor_supports(['mime_type' => 'image/webp'])) {
    throw new RuntimeException('Unexpected manifest or missing WebP support.');
}
$product = wc_get_product(wc_get_product_id_by_sku('TV-XMAS-SPARK-GIFT-SET'));
if (! $product instanceof WC_Product_Variable) {
    throw new RuntimeException('Expected Christmas gift set not found.');
}
$front_page = (int) get_option('page_on_front');
$allowed = array_merge([$product->get_image_id()], $product->get_gallery_image_ids());
$affected_products = [$product->get_id()];
foreach ($product->get_children() as $id) {
    $allowed[] = wc_get_product($id)->get_image_id();
    $affected_products[] = $id;
}
foreach (['desktop', 'mobile'] as $device) {
    $allowed[] = (int) get_post_meta($front_page, '_trovesia_home_banner_' . $device . '_image_id', true);
}
foreach ((array) get_post_meta($product->get_id(), '_trovesia_addon_rules', true) as $rule) {
    $addon = wc_get_product((int) ($rule['product_id'] ?? 0));
    if ($addon) {
        $allowed[] = $addon->get_image_id();
        $affected_products[] = $addon->get_id();
        if ($addon->get_parent_id()) {
            $affected_products[] = $addon->get_parent_id();
        }
    }
}
$allowed = array_values(array_unique(array_map('intval', array_filter($allowed))));
$uploads = wp_upload_dir();
if (! empty($uploads['error'])) {
    throw new RuntimeException($uploads['error']);
}

// Validate every attachment and both checksums before preparing any files.
$plan = [];
foreach ($rows as $row) {
    if (basename($row['source']) !== $row['source'] || basename($row['optimized']) !== $row['optimized']) {
        throw new RuntimeException('Unsafe filename.');
    }
    $old_relative = '2026/10/' . $row['source'];
    $new_relative = '2026/10/' . $row['optimized'];
    $matches = array_values(array_filter($allowed, static function ($id) use ($old_relative, $new_relative) {
        return in_array(get_post_meta($id, '_wp_attached_file', true), [$old_relative, $new_relative], true);
    }));
    if (count($matches) !== 1) {
        throw new RuntimeException('Attachment outside requested scope or ambiguous: ' . $row['source']);
    }
    $id = $matches[0];
    $source = '/optimization/optimized/' . $row['optimized'];
    $old = $uploads['basedir'] . '/' . $old_relative;
    $new = $uploads['basedir'] . '/' . $new_relative;
    if (! is_readable($old) || hash_file('sha256', $old) !== $row['source_sha256']
        || ! is_readable($source) || hash_file('sha256', $source) !== $row['sha256']) {
        throw new RuntimeException('Source changed or checksum mismatch: ' . $row['source']);
    }
    $info = wp_getimagesize($source);
    if (! $info || $info[0] !== $row['width'] || $info[1] !== $row['height'] || $info['mime'] !== 'image/webp') {
        throw new RuntimeException('Unexpected encoded dimensions or format.');
    }
    if (file_exists($new) && hash_file('sha256', $new) !== $row['sha256']) {
        throw new RuntimeException('Refusing to overwrite different WebP: ' . $new);
    }
    $plan[] = compact('id', 'row', 'source', 'new', 'new_relative');
}

// Generate all responsive files before changing media-library references.
foreach ($plan as &$entry) {
    if (! file_exists($entry['new']) && ! copy($entry['source'], $entry['new'])) {
        throw new RuntimeException('Unable to copy optimized asset.');
    }
    chmod($entry['new'], 0644);
    $editor = wp_get_image_editor($entry['new']);
    if (is_wp_error($editor)) {
        throw new RuntimeException($editor->get_error_message());
    }
    $sizes = $editor->multi_resize(wp_get_registered_image_subsizes());
    if (is_wp_error($sizes)) {
        throw new RuntimeException($sizes->get_error_message());
    }
    $old_meta = wp_get_attachment_metadata($entry['id']);
    foreach (array_keys($old_meta['sizes'] ?? []) as $size) {
        if (! isset($sizes[$size])) {
            throw new RuntimeException('Missing responsive size: ' . $size);
        }
    }
    foreach ($sizes as $size) {
        $file = dirname($entry['new']) . '/' . $size['file'];
        if (! is_readable($file) || ($size['mime-type'] ?? '') !== 'image/webp') {
            throw new RuntimeException('Invalid generated thumbnail.');
        }
        chmod($file, 0644);
    }
    $entry['before'] = [
        'file' => get_post_meta($entry['id'], '_wp_attached_file', true),
        'metadata' => $old_meta,
        'mime' => get_post_mime_type($entry['id']),
    ];
    $entry['metadata'] = [
        'width' => $entry['row']['width'], 'height' => $entry['row']['height'],
        'file' => $entry['new_relative'], 'filesize' => filesize($entry['new']),
        'sizes' => $sizes, 'image_meta' => $old_meta['image_meta'] ?? [],
    ];
}
unset($entry);

$touched = [];
try {
    foreach ($plan as $entry) {
        $id = $entry['id'];
        $touched[] = $entry;
        update_attached_file($id, $entry['new']);
        $result = wp_update_post(['ID' => $id, 'post_mime_type' => 'image/webp'], true);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        wp_update_attachment_metadata($id, $entry['metadata']);
        clean_post_cache($id);
        if (get_post_meta($id, '_wp_attached_file', true) !== $entry['new_relative']
            || get_post_mime_type($id) !== 'image/webp'
            || wp_get_attachment_metadata($id)['file'] !== $entry['new_relative']) {
            throw new RuntimeException('Attachment update did not persist: ' . $id);
        }
    }
} catch (Throwable $error) {
    foreach ($touched as $entry) {
        update_post_meta($entry['id'], '_wp_attached_file', $entry['before']['file']);
        wp_update_attachment_metadata($entry['id'], $entry['before']['metadata']);
        wp_update_post(['ID' => $entry['id'], 'post_mime_type' => $entry['before']['mime']]);
        clean_post_cache($entry['id']);
    }
    throw $error;
}
foreach (array_unique($affected_products) as $id) {
    wc_delete_product_transients($id);
    clean_post_cache($id);
}
clean_post_cache($front_page);
echo wp_json_encode(array_map(static function ($entry) {
    return [
        'attachment_id' => $entry['id'], 'url' => wp_get_attachment_url($entry['id']),
        'before_bytes' => $entry['row']['before_bytes'], 'after_bytes' => filesize($entry['new']),
        'responsive_sizes' => count($entry['metadata']['sizes']),
    ];
}, $plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
