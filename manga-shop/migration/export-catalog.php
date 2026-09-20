<?php
/**
 * Export the legacy WooCommerce catalog without loading plugins.
 *
 * Run inside the legacy WordPress container:
 * php -d memory_limit=1G /tmp/export-catalog.php > /tmp/manga-catalog.json
 */

declare(strict_types=1);

define('SHORTINIT', true);
require '/var/www/html/wp-load.php';

global $wpdb;

/**
 * @return array<int, array<string, mixed>>
 */
function manga_export_rows(string $sql): array
{
    global $wpdb;

    $rows = $wpdb->get_results($sql, ARRAY_A);

    if (! is_array($rows)) {
        throw new RuntimeException($wpdb->last_error ?: 'Database query failed.');
    }

    return $rows;
}

$postsTable = $wpdb->posts;
$postmetaTable = $wpdb->postmeta;
$termsTable = $wpdb->terms;
$termTaxonomyTable = $wpdb->term_taxonomy;
$termRelationshipsTable = $wpdb->term_relationships;
$termmetaTable = $wpdb->termmeta;
$attributeTable = $wpdb->prefix . 'woocommerce_attribute_taxonomies';

$catalogPosts = manga_export_rows(
    "SELECT * FROM {$postsTable} WHERE post_type IN ('product', 'product_variation') ORDER BY ID"
);
$catalogIds = array_map('intval', array_column($catalogPosts, 'ID'));

if ($catalogIds === []) {
    throw new RuntimeException('No products were found in the legacy database.');
}

$catalogIdList = implode(',', $catalogIds);
$catalogMeta = manga_export_rows(
    "SELECT meta_id, post_id, meta_key, meta_value FROM {$postmetaTable} "
    . "WHERE post_id IN ({$catalogIdList}) ORDER BY post_id, meta_id"
);

$attachments = manga_export_rows(
    "SELECT * FROM {$postsTable} WHERE post_type = 'attachment' ORDER BY ID"
);
$attachmentIds = array_map('intval', array_column($attachments, 'ID'));
$attachmentMeta = [];

if ($attachmentIds !== []) {
    $attachmentIdList = implode(',', $attachmentIds);
    $attachmentMeta = manga_export_rows(
        "SELECT meta_id, post_id, meta_key, meta_value FROM {$postmetaTable} "
        . "WHERE post_id IN ({$attachmentIdList}) ORDER BY post_id, meta_id"
    );
}

$taxonomyRows = manga_export_rows(
    "SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, tt.parent, tt.count, "
    . "t.name, t.slug, t.term_group "
    . "FROM {$termTaxonomyTable} tt INNER JOIN {$termsTable} t ON t.term_id = tt.term_id "
    . "WHERE tt.taxonomy LIKE 'product%' OR tt.taxonomy LIKE 'pa\\_%' "
    . "ORDER BY tt.taxonomy, tt.parent, tt.term_id"
);
$taxonomyTermIds = array_values(array_unique(array_map('intval', array_column($taxonomyRows, 'term_id'))));
$termMeta = [];

if ($taxonomyTermIds !== []) {
    $taxonomyTermIdList = implode(',', $taxonomyTermIds);
    $termMeta = manga_export_rows(
        "SELECT meta_id, term_id, meta_key, meta_value FROM {$termmetaTable} "
        . "WHERE term_id IN ({$taxonomyTermIdList}) ORDER BY term_id, meta_id"
    );
}

$relationships = manga_export_rows(
    "SELECT object_id, term_taxonomy_id, term_order FROM {$termRelationshipsTable} "
    . "WHERE object_id IN ({$catalogIdList}) ORDER BY object_id, term_order, term_taxonomy_id"
);

$attributeTableExists = (string) $wpdb->get_var(
    $wpdb->prepare('SHOW TABLES LIKE %s', $attributeTable)
);
$attributes = $attributeTableExists === $attributeTable
    ? manga_export_rows("SELECT * FROM {$attributeTable} ORDER BY attribute_id")
    : [];

$payload = [
    'format'             => 'manga-shop-catalog-v1',
    'exported_at'        => gmdate('c'),
    'source_url'         => 'https://www.mangaproduct.com',
    'table_prefix'       => $wpdb->prefix,
    'catalog_posts'      => $catalogPosts,
    'catalog_meta'       => $catalogMeta,
    'attachments'        => $attachments,
    'attachment_meta'    => $attachmentMeta,
    'taxonomy_terms'     => $taxonomyRows,
    'term_meta'          => $termMeta,
    'relationships'      => $relationships,
    'product_attributes' => $attributes,
];

$json = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);

if (! is_string($json)) {
    throw new RuntimeException('Could not encode catalog export: ' . json_last_error_msg());
}

fwrite(STDOUT, $json . PHP_EOL);

