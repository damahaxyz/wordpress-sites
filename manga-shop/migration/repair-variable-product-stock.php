<?php
/**
 * Audit and repair variable-product parent stock statuses.
 *
 * Dry run:
 *   wp eval-file /migration/repair-variable-product-stock.php
 * Apply:
 *   MANGA_APPLY_STOCK_REPAIR=1 wp eval-file /migration/repair-variable-product-stock.php
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

$apply = getenv('MANGA_APPLY_STOCK_REPAIR') === '1';
$variableIds = get_posts([
    'post_type' => 'product',
    'post_status' => ['publish', 'private', 'draft'],
    'fields' => 'ids',
    'posts_per_page' => -1,
    'orderby' => 'ID',
    'order' => 'ASC',
    'tax_query' => [
        [
            'taxonomy' => 'product_type',
            'field' => 'slug',
            'terms' => ['variable'],
        ],
    ],
]);

$mismatches = [];
foreach ($variableIds as $productId) {
    $product = wc_get_product((int) $productId);
    if (! $product instanceof WC_Product_Variable) {
        continue;
    }

    $inStockChildren = [];
    $outOfStockChildren = [];
    foreach ($product->get_children() as $variationId) {
        $variation = wc_get_product($variationId);
        if (! $variation instanceof WC_Product_Variation) {
            continue;
        }

        if ($variation->is_in_stock()) {
            $inStockChildren[] = (int) $variationId;
        } else {
            $outOfStockChildren[] = (int) $variationId;
        }
    }

    $currentStatus = $product->get_stock_status('edit');
    $hasAvailableChild = $inStockChildren !== [];
    if (($currentStatus === 'outofstock' && $hasAvailableChild)
        || ($currentStatus !== 'outofstock' && ! $hasAvailableChild)) {
        $mismatches[] = [
            'id' => (int) $productId,
            'title' => $product->get_name(),
            'parent_status' => $currentStatus,
            'in_stock_variations' => count($inStockChildren),
            'out_of_stock_variations' => count($outOfStockChildren),
        ];

        if ($apply) {
            WC_Product_Variable::sync_stock_status((int) $productId);
            wc_delete_product_transients((int) $productId);
            clean_post_cache((int) $productId);
        }
    }
}

echo wp_json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'variable_products' => count($variableIds),
    'mismatch_count' => count($mismatches),
    'mismatches' => $mismatches,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
