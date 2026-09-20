<?php
/**
 * Verify a completed Manga Product catalog migration.
 *
 * Run with WP-CLI:
 * wp eval-file /tmp/verify-catalog.php
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

global $wpdb;

$statusRows = $wpdb->get_results(
    "SELECT post_type, post_status, COUNT(*) AS total FROM {$wpdb->posts} "
    . "WHERE post_type IN ('product', 'product_variation', 'attachment') "
    . 'GROUP BY post_type, post_status ORDER BY post_type, post_status',
    ARRAY_A
);

$productIds = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' ORDER BY ID"
));
$variationIds = array_map('intval', $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation' ORDER BY ID"
));
$legacyAttachmentIds = array_map('intval', $wpdb->get_col(
    "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_manga_legacy_attachment_id'"
));

$referencedImageIds = [];
$productsWithoutFeaturedImage = 0;
foreach ($productIds as $productId) {
    $thumbnailId = (int) get_post_meta($productId, '_thumbnail_id', true);
    if ($thumbnailId > 0) {
        $referencedImageIds[] = $thumbnailId;
    } else {
        $productsWithoutFeaturedImage++;
    }

    $gallery = (string) get_post_meta($productId, '_product_image_gallery', true);
    $referencedImageIds = array_merge(
        $referencedImageIds,
        array_filter(array_map('intval', explode(',', $gallery)))
    );
}
foreach ($variationIds as $variationId) {
    $thumbnailId = (int) get_post_meta($variationId, '_thumbnail_id', true);
    if ($thumbnailId > 0) {
        $referencedImageIds[] = $thumbnailId;
    }
}
$referencedImageIds = array_values(array_unique(array_filter($referencedImageIds)));

$missingReferencedRecords = [];
$missingReferencedFiles = [];
foreach ($referencedImageIds as $attachmentId) {
    if (get_post_type($attachmentId) !== 'attachment') {
        $missingReferencedRecords[] = $attachmentId;
        continue;
    }

    $file = get_attached_file($attachmentId);
    if (! is_string($file) || $file === '' || ! file_exists($file)) {
        $missingReferencedFiles[] = $attachmentId;
    }
}

$missingLegacyFiles = [];
foreach ($legacyAttachmentIds as $attachmentId) {
    $file = get_attached_file($attachmentId);
    if (! is_string($file) || $file === '' || ! file_exists($file)) {
        $missingLegacyFiles[] = $attachmentId;
    }
}

$productIdList = $productIds === [] ? '0' : implode(',', $productIds);
$catalogIdList = array_merge($productIds, $variationIds);
$catalogIdSql = $catalogIdList === [] ? '0' : implode(',', $catalogIdList);

$summary = [
    'status_counts'                    => $statusRows,
    'legacy_product_records'           => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_manga_legacy_id' AND post_id IN ({$catalogIdSql})"
    ),
    'legacy_attachment_records'        => count($legacyAttachmentIds),
    'woocommerce_lookup_rows'          => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} lookup "
        . "INNER JOIN {$wpdb->posts} p ON p.ID = lookup.product_id "
        . "WHERE p.post_type IN ('product', 'product_variation')"
    ),
    'product_folder_meta_rows'         => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_manga_media_folder_term_id' AND post_id IN ({$productIdList})"
    ),
    'wpmf_folder_terms'                => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'wpmf-category'"
    ),
    'foldered_attachment_records'      => (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr "
        . "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
        . "WHERE tt.taxonomy = 'wpmf-category'"
    ),
    'referenced_unique_images'         => count($referencedImageIds),
    'missing_referenced_records'       => $missingReferencedRecords,
    'missing_referenced_files'         => $missingReferencedFiles,
    'missing_legacy_attachment_files'  => $missingLegacyFiles,
    'products_without_featured_image'  => $productsWithoutFeaturedImage,
    'orphan_variations'                => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} v LEFT JOIN {$wpdb->posts} p ON p.ID = v.post_parent AND p.post_type = 'product' "
        . "WHERE v.post_type = 'product_variation' AND p.ID IS NULL"
    ),
    'old_origin_catalog_post_rows'     => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$catalogIdSql}) "
        . "AND (post_content LIKE '%www.mangaproduct.com%' OR post_excerpt LIKE '%www.mangaproduct.com%')"
    ),
    'old_origin_catalog_meta_rows'     => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ({$catalogIdSql}) "
        . "AND meta_value LIKE '%www.mangaproduct.com%'"
    ),
];

echo wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

