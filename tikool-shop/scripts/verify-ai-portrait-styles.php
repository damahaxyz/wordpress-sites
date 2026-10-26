<?php

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        WP_CLI::error($message);
    }
};

$expected_styles = ['original', 'minimalist', 'watercolor', 'classic-oil'];
$styles = \Tikool\FrameCustomizer\AI_Styles::styles();
$check(array_keys($styles) === $expected_styles, 'Unexpected Custom Pet Portrait style list.');
$check(($styles['original']['ai_feature'] ?? null) === '', 'Original style must not call AI.');
$check(($styles['minimalist']['ai_feature'] ?? null) === 'pet_portrait_minimalist', 'Minimalist prompt mapping is incorrect.');
$check(($styles['watercolor']['ai_feature'] ?? null) === 'pet_portrait_watercolor', 'Watercolor prompt mapping is incorrect.');
$expected_variants = ['warm_ivory', 'mist_blue', 'soft_sage', 'dusty_blush'];
$check(array_keys((array) ($styles['watercolor']['variants'] ?? [])) === $expected_variants, 'Watercolor background variants are incorrect.');
$check(($styles['classic-oil']['ai_feature'] ?? null) === 'pet_portrait_oil', 'Oil prompt mapping is incorrect.');
$check(\Tikool\FrameCustomizer\Config::SCHEMA_VERSION >= 6, 'Customization schema was not upgraded for product-level portrait styles.');

$ai_styles = \Tikool\FrameCustomizer\AI_Styles::instance();
$check(has_action('wp_ajax_tikool_fc_generate_style', [$ai_styles, 'generate']) !== false, 'Authenticated AI generation hook is missing.');
$check(has_action('wp_ajax_nopriv_tikool_fc_generate_style', [$ai_styles, 'generate']) !== false, 'Guest AI generation hook is missing.');
$check(has_action('wp_ajax_nopriv_tikool_fc_ai_preview', [$ai_styles, 'stream_preview']) !== false, 'Private AI preview hook is missing.');

$product = wc_get_product(31);
$check($product instanceof WC_Product && \Tikool\FrameCustomizer\Config::is_enabled(31), 'Custom Pet Portrait product is unavailable.');
$GLOBALS['product'] = $product;
$customizer = \Tikool\FrameCustomizer\Customizer::instance();
ob_start();
$customizer->render_editor();
$markup = (string) ob_get_clean();
$check(str_contains($markup, 'Create your pet portrait'), 'Updated portrait editor heading was not rendered.');
$check(substr_count($markup, 'data-tikool-fc-style') >= 5, 'Portrait style controls were not rendered.');
foreach ($expected_styles as $style_key) {
    $check(str_contains($markup, 'value="' . $style_key . '"'), 'Missing portrait style: ' . $style_key);
}
$check(str_contains($markup, 'data-tikool-fc-style-status'), 'AI style status region was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-variants-grid'), 'Watercolor background chooser was not rendered.');
$check(str_contains($markup, 'tikool-fc-preview__variants'), 'Background chooser was not placed in the preview panel.');
$check(str_contains($markup, '&quot;warm_ivory&quot;'), 'Watercolor variant data was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-ai-loading'), 'AI generation loading experience was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-view-large'), 'Large-preview button was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-lightbox'), 'Large-preview lightbox was not rendered.');

if (! function_exists('woocommerce_wp_checkbox') && defined('WC_ABSPATH')) {
    require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
}
$GLOBALS['post'] = get_post(31);
ob_start();
\Tikool\FrameCustomizer\Admin::instance()->render_product_settings();
$admin_markup = (string) ob_get_clean();
$check(str_contains($admin_markup, 'data-tikool-fc-style-list'), 'Product-level style configuration list was not rendered.');
$check(str_contains($admin_markup, 'data-tikool-fc-add-style'), 'Add-style control was not rendered.');
$check(str_contains($admin_markup, 'tikool_fc_portrait_styles[0][ai_feature]'), 'AI feature dropdown was not rendered.');
$check(str_contains($admin_markup, 'value="pet_portrait_watercolor"'), 'Tikool AI prompt profiles were not available in the product dropdown.');

$details = $customizer->display_cart_item_data([], [
    '_tikool_frame_customization' => [
        'style_key' => 'watercolor',
        'style_label' => 'Watercolor Pet Portrait',
        'variant_key' => 'soft_sage',
        'variant_label' => 'Soft Sage',
        'recipe' => ['nameOverlay' => ['enabled' => false]],
    ],
]);
$portrait_style_label = __('Portrait style', 'tikool-frame-customizer');
$style_rows = array_values(array_filter($details, static fn (array $row): bool => ($row['key'] ?? '') === $portrait_style_label));
$check(count($style_rows) === 1 && ($style_rows[0]['value'] ?? '') === 'Watercolor Pet Portrait', 'Cart style detail was not preserved.');
$background_label = __('Portrait background', 'tikool-frame-customizer');
$background_rows = array_values(array_filter($details, static fn (array $row): bool => ($row['key'] ?? '') === $background_label));
$check(count($background_rows) === 1 && ($background_rows[0]['value'] ?? '') === 'Soft Sage', 'Cart background detail was not preserved.');

$check(class_exists(\Tikool\AI\Prompts::class), 'Tikool AI plugin is not active.');
$profiles = \Tikool\AI\Prompts::profiles();
foreach (['pet_portrait_minimalist', 'pet_portrait_watercolor', 'pet_portrait_oil'] as $feature) {
    $check(! empty($profiles[$feature]['enabled']), 'AI prompt profile is unavailable: ' . $feature);
    $prompt = \Tikool\AI\Prompts::render($feature, ['product_name' => 'Custom Pet Portrait']);
    $check(is_string($prompt) && ! str_contains($prompt, '{{'), 'Prompt did not render cleanly: ' . $feature);
}
$watercolor_variants = \Tikool\AI\Prompts::variants('pet_portrait_watercolor');
$check(array_keys($watercolor_variants) === $expected_variants, 'Tikool AI watercolor variants are unavailable.');
$watercolor_feature = \Tikool\AI\Prompts::feature_for_variant('pet_portrait_watercolor', 'mist_blue');
$watercolor_prompt = \Tikool\AI\Prompts::render($watercolor_feature, ['product_name' => 'Custom Pet Portrait']);
$check(is_string($watercolor_prompt) && str_contains($watercolor_prompt, 'misty blue-gray'), 'Watercolor background prompt was not injected.');
$check(! str_contains((string) $watercolor_prompt, '{{'), 'Watercolor variant prompt contains unresolved variables.');

WP_CLI::success('Verified Custom Pet Portrait styles, AI mappings, watercolor variants, private preview hooks, cart data, and prompt profiles.');
