<?php
/**
 * Read-only catalog audit for the legacy Manga Product WordPress container.
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'www.mangaproduct.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT'] = '443';

define('SHORTINIT', true);
require '/var/www/html/wp-load.php';

global $wpdb;

$counts = $wpdb->get_results(
    "SELECT post_type, post_status, COUNT(*) AS total FROM {$wpdb->posts} "
    . "WHERE post_type IN ('product', 'product_variation', 'attachment') "
    . 'GROUP BY post_type, post_status ORDER BY post_type, post_status',
    ARRAY_A
);
$types = $wpdb->get_results(
    "SELECT t.slug AS product_type, COUNT(*) AS total FROM {$wpdb->term_relationships} tr "
    . "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
    . "INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id "
    . "INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id "
    . "WHERE tt.taxonomy = 'product_type' AND p.post_type = 'product' GROUP BY t.slug ORDER BY t.slug",
    ARRAY_A
);
$folderCount = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'wpmf-category'"
);

$payload = [
    'counts'            => $counts,
    'product_types'     => $types,
    'wpmf_folder_count' => $folderCount,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
