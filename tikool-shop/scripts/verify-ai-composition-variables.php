<?php

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        WP_CLI::error($message);
    }
};

$profiles = \Tikool\AI\Prompts::profiles();
$features = [
    'pet_portrait_minimalist',
    'pet_portrait_watercolor',
    'pet_portrait_oil',
    'blanket_portrait_minimalist_cream',
    'blanket_portrait_watercolor_pastel',
    'blanket_portrait_modern_studio',
    'blanket_portrait_playful_pattern',
    'blanket_portrait_memorial_garden',
    'blanket_portrait_pop_art',
];
foreach ($features as $feature) {
    $template = (string) ($profiles[$feature]['template'] ?? '');
    foreach (['orientation', 'aspect_ratio', 'print_dimensions'] as $variable) {
        $check(str_contains($template, '{{' . $variable . '}}'), sprintf('Missing %s variable: %s', $variable, $feature));
    }
}

$product = get_page_by_path('custom-pet-blanket', OBJECT, 'product');
$check($product instanceof WP_Post, 'Custom Pet Blanket product is unavailable.');
$method = new ReflectionMethod(\Tikool\FrameCustomizer\AI_Styles::class, 'composition_spec');
$styles = \Tikool\FrameCustomizer\AI_Styles::instance();
$expected = [
    'TIK-BLK-5060-1S350' => ['5:6', '1270 × 1520 mm'],
    'TIK-BLK-5060-2S520' => ['5:6', '1270 × 1520 mm'],
    'TIK-BLK-6080-1S350' => ['3:4', '1500 × 2000 mm'],
    'TIK-BLK-6080-2S520' => ['3:4', '1500 × 2000 mm'],
];
foreach ($expected as $sku => [$ratio, $dimensions]) {
    $variation_id = wc_get_product_id_by_sku($sku);
    $check($variation_id > 0, 'Missing blanket SKU: ' . $sku);
    $spec = $method->invoke($styles, (int) $product->ID, $variation_id);
    $check(! is_wp_error($spec), 'Could not resolve composition for SKU: ' . $sku);
    $variables = is_array($spec) ? (array) ($spec['variables'] ?? []) : [];
    $check(($variables['orientation'] ?? '') === '竖版（portrait）', 'Unexpected orientation for SKU: ' . $sku);
    $check(($variables['aspect_ratio'] ?? '') === $ratio, 'Unexpected aspect ratio for SKU: ' . $sku);
    $check(($variables['print_dimensions'] ?? '') === $dimensions, 'Unexpected print dimensions for SKU: ' . $sku);
}

$feature = \Tikool\AI\Prompts::feature_for_variant('blanket_portrait_watercolor_pastel', 'warm_ivory');
$prompt = \Tikool\AI\Prompts::render($feature, [
    'product_name' => 'Custom Pet Blanket',
    'orientation' => '竖版（portrait）',
    'aspect_ratio' => '3:4',
    'print_dimensions' => '1500 × 2000 mm',
]);
$check(is_string($prompt), 'Composition prompt did not render.');
$check(str_contains((string) $prompt, '竖版（portrait） 3:4'), 'Rendered composition is missing orientation or ratio.');
$check(str_contains((string) $prompt, '1500 × 2000 mm'), 'Rendered composition is missing print dimensions.');
$check(! str_contains((string) $prompt, '{{'), 'Rendered composition contains unresolved variables.');

WP_CLI::success('Verified composition variables for 9 profiles and all 4 blanket SKUs.');
