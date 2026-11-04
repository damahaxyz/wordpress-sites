<?php
/** Read-only WP-CLI regression checks; modifies cloned product objects only. */
if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WooCommerce')) {
    throw new RuntimeException('Requires WooCommerce and WP-CLI.');
}

$original_global = $GLOBALS['product'] ?? null;
$source = wc_get_product(wc_get_product_id_by_sku('TV-XMAS-SPARK-GIFT-SET'));
if (! $source instanceof WC_Product_Variable) {
    throw new RuntimeException('Expected variable product not found.');
}
$saved_defaults = $source->get_default_attributes();
$checks = [];
try {
    foreach ([
        'No default keeps every color unselected' => [[], ''],
        'An explicit Pink default is retained' => [['color' => 'Pink'], 'Pink'],
        'An obsolete default does not select the first color' => [['color' => 'obsolete-color'], ''],
    ] as $name => [$defaults, $expected]) {
        $GLOBALS['product'] = clone $source;
        $GLOBALS['product']->set_default_attributes($defaults);
        ob_start();
        Trovesia\Plugin\Site_Experience::instance()->render_variant_picker();
        $html = ob_get_clean();
        if (! preg_match('/data-default-value="([^"]*)"/', $html, $match) || $match[1] !== $expected) {
            throw new RuntimeException('FAIL: ' . $name);
        }
        if (substr_count($html, 'class="trovesia-variant-card"') !== 5) {
            throw new RuntimeException('FAIL: All five color choices must remain available.');
        }
        $checks[] = $name;
    }
    $fresh = new WC_Product_Variable($source->get_id());
    if ($fresh->get_default_attributes() !== $saved_defaults) {
        throw new RuntimeException('FAIL: Persisted defaults changed.');
    }
    $checks[] = 'Persisted defaults unchanged; no product saved';
    echo wp_json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    $GLOBALS['product'] = $original_global;
}
