<?php
/**
 * Import a catalog produced by export-catalog.php.
 *
 * Run with WP-CLI in the new site:
 * wp eval-file /tmp/import-catalog.php /tmp/manga-catalog.json --user=damaha
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce must be active before importing.\n");
    exit(1);
}

$inputPath = $args[0] ?? '';
if (! is_string($inputPath) || $inputPath === '' || ! is_readable($inputPath)) {
    fwrite(STDERR, "Usage: wp eval-file import-catalog.php /path/to/manga-catalog.json\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
if (($payload['format'] ?? '') !== 'manga-shop-catalog-v1') {
    throw new RuntimeException('Unsupported catalog export format.');
}

global $wpdb;

const MANGA_OLD_ORIGIN = 'https://www.mangaproduct.com';
const MANGA_NEW_ORIGIN = 'https://mangaproduct.com';

/** @var array<int, int> $attachmentMap */
$attachmentMap = [];
/** @var array<int, int> $postMap */
$postMap = [];
/** @var array<int, int> $termMap */
$termMap = [];
/** @var array<int, array<string, mixed>> $metaByPost */
$metaByPost = [];

foreach ($payload['catalog_meta'] as $meta) {
    $metaByPost[(int) $meta['post_id']][] = $meta;
}

function manga_import_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('H:i:s') . "] {$message}\n");
}

function manga_rewrite_origin(mixed $value): mixed
{
    if (is_string($value)) {
        return str_replace(
            [MANGA_OLD_ORIGIN, 'http://www.mangaproduct.com'],
            [MANGA_NEW_ORIGIN, MANGA_NEW_ORIGIN],
            $value
        );
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = manga_rewrite_origin($item);
        }
    }

    return $value;
}

/**
 * Remap attachment IDs only in metadata fields known to contain image references.
 *
 * @param array<int, int> $attachmentMap
 */
function manga_transform_meta(string $key, string $rawValue, array $attachmentMap): mixed
{
    if ($key === '_thumbnail_id') {
        $oldId = (int) $rawValue;
        return $attachmentMap[$oldId] ?? 0;
    }

    if ($key === '_product_image_gallery') {
        $newIds = [];
        foreach (array_filter(array_map('intval', explode(',', $rawValue))) as $oldId) {
            if (isset($attachmentMap[$oldId])) {
                $newIds[] = $attachmentMap[$oldId];
            }
        }
        return implode(',', $newIds);
    }

    $value = maybe_unserialize($rawValue);
    return manga_rewrite_origin($value);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param array<int, int>                  $attachmentMap
 */
function manga_replace_post_meta(int $postId, array $rows, array $attachmentMap): void
{
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(string) $row['meta_key']][] = (string) $row['meta_value'];
    }

    foreach ($grouped as $key => $values) {
        if (in_array($key, ['_edit_lock', '_edit_last', '_manga_legacy_id', '_manga_legacy_attachment_id'], true)) {
            continue;
        }

        delete_post_meta($postId, $key);
        foreach ($values as $rawValue) {
            add_post_meta($postId, $key, manga_transform_meta($key, $rawValue, $attachmentMap));
        }
    }
}

/**
 * @param array<string, mixed> $source
 */
function manga_post_data(array $source, string $postType, int $parent = 0): array
{
    $fields = [
        'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
        'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name',
        'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
        'post_content_filtered', 'menu_order', 'post_mime_type', 'comment_count',
    ];
    $data = [];

    foreach ($fields as $field) {
        if (array_key_exists($field, $source)) {
            $data[$field] = manga_rewrite_origin($source[$field]);
        }
    }

    $data['post_type'] = $postType;
    $data['post_parent'] = $parent;
    $data['post_author'] = get_current_user_id() ?: 1;

    return wp_slash($data);
}

/**
 * @return int|null
 */
function manga_existing_post(string $metaKey, int $legacyId): ?int
{
    global $wpdb;

    $id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id LIMIT 1",
            $metaKey,
            (string) $legacyId
        )
    );

    return $id ? (int) $id : null;
}

manga_import_log('Creating global product attributes.');
foreach ($payload['product_attributes'] as $attribute) {
    $slug = wc_sanitize_taxonomy_name((string) $attribute['attribute_name']);
    $exists = false;
    foreach (wc_get_attribute_taxonomies() as $current) {
        if (wc_sanitize_taxonomy_name((string) $current->attribute_name) === $slug) {
            $exists = true;
            break;
        }
    }

    if (! $exists) {
        $result = wc_create_attribute([
            'name'         => (string) $attribute['attribute_label'],
            'slug'         => $slug,
            'type'         => (string) $attribute['attribute_type'],
            'order_by'     => (string) $attribute['attribute_orderby'],
            'has_archives' => (bool) $attribute['attribute_public'],
        ]);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
    }
}
delete_transient('wc_attribute_taxonomies');

foreach ($payload['taxonomy_terms'] as $row) {
    $taxonomy = (string) $row['taxonomy'];
    if (! taxonomy_exists($taxonomy)) {
        register_taxonomy($taxonomy, ['product'], [
            'hierarchical' => true,
            'public'       => false,
            'rewrite'      => false,
        ]);
    }
}

manga_import_log('Creating product categories, tags, types, and attribute terms.');
$pendingTerms = $payload['taxonomy_terms'];
$termPasses = 0;
while ($pendingTerms !== [] && $termPasses < 20) {
    $termPasses++;
    $next = [];

    foreach ($pendingTerms as $row) {
        $oldTermId = (int) $row['term_id'];
        $oldParent = (int) $row['parent'];
        if ($oldParent > 0 && ! isset($termMap[$oldParent])) {
            $next[] = $row;
            continue;
        }

        $taxonomy = (string) $row['taxonomy'];
        $existing = get_term_by('slug', (string) $row['slug'], $taxonomy);
        if ($existing instanceof WP_Term) {
            $newTermId = $existing->term_id;
        } else {
            $created = wp_insert_term((string) $row['name'], $taxonomy, [
                'slug'        => (string) $row['slug'],
                'description' => manga_rewrite_origin((string) $row['description']),
                'parent'      => $oldParent > 0 ? $termMap[$oldParent] : 0,
            ]);
            if (is_wp_error($created)) {
                throw new RuntimeException("Could not create {$taxonomy}/{$row['slug']}: " . $created->get_error_message());
            }
            $newTermId = (int) $created['term_id'];
        }

        $termMap[$oldTermId] = $newTermId;
    }

    if (count($next) === count($pendingTerms)) {
        break;
    }
    $pendingTerms = $next;
}

if ($pendingTerms !== []) {
    throw new RuntimeException('Could not resolve all term parent relationships.');
}

manga_import_log('Importing media attachment records.');
$attachmentMetaByPost = [];
foreach ($payload['attachment_meta'] as $meta) {
    $attachmentMetaByPost[(int) $meta['post_id']][] = $meta;
}

$uploads = wp_get_upload_dir();
foreach ($payload['attachments'] as $index => $source) {
    $oldId = (int) $source['ID'];
    $existingId = manga_existing_post('_manga_legacy_attachment_id', $oldId);

    $attachedFile = '';
    foreach ($attachmentMetaByPost[$oldId] ?? [] as $meta) {
        if ($meta['meta_key'] === '_wp_attached_file') {
            $attachedFile = (string) $meta['meta_value'];
            break;
        }
    }

    if (! $existingId && $attachedFile !== '') {
        $existingId = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                $attachedFile
            )
        ) ?: null;
    }

    if ($existingId) {
        $newId = $existingId;
        wp_update_post(array_merge(['ID' => $newId], manga_post_data($source, 'attachment')));
    } else {
        $newId = wp_insert_attachment(manga_post_data($source, 'attachment'), '', 0, true);
        if (is_wp_error($newId)) {
            throw new RuntimeException("Could not import attachment {$oldId}: " . $newId->get_error_message());
        }
    }

    $attachmentMap[$oldId] = (int) $newId;
    update_post_meta((int) $newId, '_manga_legacy_attachment_id', $oldId);
    manga_replace_post_meta((int) $newId, $attachmentMetaByPost[$oldId] ?? [], $attachmentMap);

    if ($attachedFile !== '') {
        $wpdb->update(
            $wpdb->posts,
            ['guid' => trailingslashit((string) $uploads['baseurl']) . ltrim($attachedFile, '/')],
            ['ID' => (int) $newId],
            ['%s'],
            ['%d']
        );
    }

    if (($index + 1) % 250 === 0) {
        manga_import_log('Imported ' . ($index + 1) . ' attachment records.');
    }
}

manga_import_log('Restoring product-category metadata.');
$termMetaByTerm = [];
foreach ($payload['term_meta'] as $meta) {
    $termMetaByTerm[(int) $meta['term_id']][] = $meta;
}
foreach ($termMetaByTerm as $oldTermId => $rows) {
    if (! isset($termMap[$oldTermId])) {
        continue;
    }
    $newTermId = $termMap[$oldTermId];
    foreach ($rows as $row) {
        $key = (string) $row['meta_key'];
        $value = (string) $row['meta_value'];
        if ($key === 'thumbnail_id') {
            $value = (string) ($attachmentMap[(int) $value] ?? 0);
        } else {
            $value = manga_rewrite_origin(maybe_unserialize($value));
        }
        update_term_meta($newTermId, $key, $value);
    }
}

manga_import_log('Importing products.');
$catalogPosts = $payload['catalog_posts'];
usort($catalogPosts, static function (array $left, array $right): int {
    $leftVariation = $left['post_type'] === 'product_variation' ? 1 : 0;
    $rightVariation = $right['post_type'] === 'product_variation' ? 1 : 0;
    return [$leftVariation, (int) $left['ID']] <=> [$rightVariation, (int) $right['ID']];
});

foreach ($catalogPosts as $source) {
    $oldId = (int) $source['ID'];
    $isVariation = $source['post_type'] === 'product_variation';
    $parent = $isVariation ? ($postMap[(int) $source['post_parent']] ?? 0) : 0;
    if ($isVariation && $parent === 0) {
        throw new RuntimeException("Variation {$oldId} has no imported parent.");
    }

    $newId = manga_existing_post('_manga_legacy_id', $oldId);
    $postData = manga_post_data($source, (string) $source['post_type'], $parent);
    if ($newId) {
        $postData['ID'] = $newId;
        $result = wp_update_post($postData, true);
    } else {
        $result = wp_insert_post($postData, true);
    }
    if (is_wp_error($result)) {
        throw new RuntimeException("Could not import product post {$oldId}: " . $result->get_error_message());
    }

    $newId = (int) $result;
    $postMap[$oldId] = $newId;
    manga_replace_post_meta($newId, $metaByPost[$oldId] ?? [], $attachmentMap);
    update_post_meta($newId, '_manga_legacy_id', $oldId);
}

manga_import_log('Restoring product taxonomy relationships.');
$termByTaxonomyId = [];
foreach ($payload['taxonomy_terms'] as $row) {
    $termByTaxonomyId[(int) $row['term_taxonomy_id']] = [
        'taxonomy'   => (string) $row['taxonomy'],
        'new_term_id' => $termMap[(int) $row['term_id']],
    ];
}
$relationshipsByPost = [];
foreach ($payload['relationships'] as $row) {
    $relationshipsByPost[(int) $row['object_id']][] = $row;
}
foreach ($relationshipsByPost as $oldPostId => $rows) {
    if (! isset($postMap[$oldPostId])) {
        continue;
    }
    $byTaxonomy = [];
    foreach ($rows as $row) {
        $term = $termByTaxonomyId[(int) $row['term_taxonomy_id']] ?? null;
        if ($term) {
            $byTaxonomy[$term['taxonomy']][] = (int) $term['new_term_id'];
        }
    }
    foreach ($byTaxonomy as $taxonomy => $newTermIds) {
        $result = wp_set_object_terms($postMap[$oldPostId], $newTermIds, $taxonomy, false);
        if (is_wp_error($result)) {
            throw new RuntimeException("Could not assign {$taxonomy}: " . $result->get_error_message());
        }
    }
}

manga_import_log('Creating one media folder per product and assigning product images.');
if (! taxonomy_exists('wpmf-category')) {
    throw new RuntimeException('WP Media Folder is not active or did not register wpmf-category.');
}

$productRowsById = [];
$childrenByParent = [];
foreach ($catalogPosts as $source) {
    if ($source['post_type'] === 'product') {
        $productRowsById[(int) $source['ID']] = $source;
    } elseif ($source['post_type'] === 'product_variation') {
        $childrenByParent[(int) $source['post_parent']][] = (int) $source['ID'];
    }
}

$oldTaxonomyById = [];
foreach ($payload['taxonomy_terms'] as $row) {
    $oldTaxonomyById[(int) $row['term_taxonomy_id']] = $row;
}
$categoryByProduct = [];
foreach ($relationshipsByPost as $oldPostId => $rows) {
    foreach ($rows as $row) {
        $taxRow = $oldTaxonomyById[(int) $row['term_taxonomy_id']] ?? null;
        if ($taxRow && $taxRow['taxonomy'] === 'product_cat') {
            $categoryByProduct[$oldPostId][] = $taxRow;
        }
    }
}

$folderCount = 0;
$folderedImages = [];
foreach ($productRowsById as $oldProductId => $source) {
    $categories = $categoryByProduct[$oldProductId] ?? [];
    usort($categories, static fn(array $a, array $b): int => ((int) $b['parent'] > 0) <=> ((int) $a['parent'] > 0));
    $category = $categories[0] ?? ['name' => 'Uncategorized', 'slug' => 'uncategorized'];

    $categoryFolderSlug = 'manga-product-category-' . sanitize_title((string) $category['slug']);
    $categoryFolder = get_term_by('slug', $categoryFolderSlug, 'wpmf-category');
    if (! $categoryFolder instanceof WP_Term) {
        $created = wp_insert_term((string) $category['name'], 'wpmf-category', ['slug' => $categoryFolderSlug]);
        if (is_wp_error($created)) {
            throw new RuntimeException($created->get_error_message());
        }
        $categoryFolderId = (int) $created['term_id'];
    } else {
        $categoryFolderId = $categoryFolder->term_id;
    }

    $folderSlug = 'manga-product-' . $oldProductId . '-' . sanitize_title((string) $source['post_name']);
    $folder = get_term_by('slug', $folderSlug, 'wpmf-category');
    if (! $folder instanceof WP_Term) {
        $created = wp_insert_term((string) $source['post_title'], 'wpmf-category', [
            'slug'   => $folderSlug,
            'parent' => $categoryFolderId,
        ]);
        if (is_wp_error($created)) {
            throw new RuntimeException($created->get_error_message());
        }
        $folderId = (int) $created['term_id'];
    } else {
        $folderId = $folder->term_id;
    }

    update_post_meta($postMap[$oldProductId], '_manga_media_folder_term_id', $folderId);
    $folderCount++;

    $imageOldIds = [];
    $relatedOldPostIds = array_merge([$oldProductId], $childrenByParent[$oldProductId] ?? []);
    foreach ($relatedOldPostIds as $relatedOldPostId) {
        foreach ($metaByPost[$relatedOldPostId] ?? [] as $meta) {
            if ($meta['meta_key'] === '_thumbnail_id') {
                $imageOldIds[] = (int) $meta['meta_value'];
            } elseif ($meta['meta_key'] === '_product_image_gallery') {
                $imageOldIds = array_merge($imageOldIds, array_map('intval', explode(',', (string) $meta['meta_value'])));
            }
        }
    }
    foreach ($payload['attachments'] as $attachment) {
        if (in_array((int) $attachment['post_parent'], $relatedOldPostIds, true)) {
            $imageOldIds[] = (int) $attachment['ID'];
        }
    }

    foreach (array_unique(array_filter($imageOldIds)) as $oldImageId) {
        if (! isset($attachmentMap[$oldImageId])) {
            continue;
        }
        $result = wp_set_object_terms($attachmentMap[$oldImageId], [$folderId], 'wpmf-category', true);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        $folderedImages[$attachmentMap[$oldImageId]] = true;
    }
}

manga_import_log('Reconnecting attachment parents and synchronizing WooCommerce lookup data.');
foreach ($payload['attachments'] as $source) {
    $oldParent = (int) $source['post_parent'];
    if ($oldParent > 0 && isset($postMap[$oldParent], $attachmentMap[(int) $source['ID']])) {
        wp_update_post([
            'ID'          => $attachmentMap[(int) $source['ID']],
            'post_parent' => $postMap[$oldParent],
        ]);
    }
}

// Product types can be cached as "simple" while metadata is imported before the
// product_type relationships exist. Invalidate that cache before instantiating
// WooCommerce product objects so variable products keep their source type.
foreach ($postMap as $newId) {
    WC_Cache_Helper::invalidate_cache_group('product_' . $newId);
    clean_post_cache($newId);
}

foreach ($postMap as $oldId => $newId) {
    $product = wc_get_product($newId);
    if ($product instanceof WC_Product) {
        $product->save();
    }
}
foreach ($productRowsById as $oldProductId => $_source) {
    if (isset($postMap[$oldProductId]) && function_exists('wc_get_product')) {
        $product = wc_get_product($postMap[$oldProductId]);
        if ($product instanceof WC_Product_Variable) {
            WC_Product_Variable::sync($postMap[$oldProductId]);
        }
        wc_delete_product_transients($postMap[$oldProductId]);
    }
}

flush_rewrite_rules(false);

$publishedProducts = (int) wp_count_posts('product')->publish;
$publishedVariations = (int) wp_count_posts('product_variation')->publish;
$summary = [
    'products_imported'       => count($productRowsById),
    'variations_imported'     => count($catalogPosts) - count($productRowsById),
    'attachments_imported'    => count($attachmentMap),
    'product_folders_created' => $folderCount,
    'images_foldered'         => count($folderedImages),
    'published_products_now'  => $publishedProducts,
    'published_variations_now'=> $publishedVariations,
];

manga_import_log('IMPORT_COMPLETE ' . wp_json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
