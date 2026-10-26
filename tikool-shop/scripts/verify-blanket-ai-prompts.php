<?php

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        WP_CLI::error($message);
    }
};

$expected = [
    'blanket_portrait_minimalist_cream' => ['warm_ivory', 'oatmeal_beige', 'pale_sage', 'dusty_blue'],
    'blanket_portrait_watercolor_pastel' => ['warm_ivory', 'mist_blue', 'soft_sage', 'dusty_blush'],
    'blanket_portrait_modern_studio' => ['soft_white', 'warm_sand', 'mist_blue_gray', 'muted_sage'],
    'blanket_portrait_playful_pattern' => ['cream_confetti', 'sky_blue_paws', 'sage_geometry', 'blush_hearts'],
    'blanket_portrait_memorial_garden' => ['ivory_daisy_garden', 'sage_meadow', 'mist_blue_forget_me_not', 'blush_wildflower'],
    'blanket_portrait_pop_art' => ['coral_cobalt', 'teal_sunshine', 'berry_aqua', 'orange_violet'],
];

$profiles = \Tikool\AI\Prompts::profiles();
foreach ($expected as $feature => $variant_keys) {
    $profile = $profiles[$feature] ?? [];
    $check(! empty($profile['enabled']), 'Blanket prompt profile is unavailable: ' . $feature);
    $check(str_contains((string) ($profile['template'] ?? ''), '{{background_prompt}}'), 'Background placeholder is missing: ' . $feature);

    $variants = \Tikool\AI\Prompts::variants($feature);
    $check(array_keys($variants) === $variant_keys, 'Unexpected blanket variants: ' . $feature);
    $check(count($variants) === 4, 'Each blanket style must define exactly four variants: ' . $feature);

    foreach ($variant_keys as $variant_key) {
        $variant_feature = \Tikool\AI\Prompts::feature_for_variant($feature, $variant_key);
        $prompt = \Tikool\AI\Prompts::render($variant_feature, [
            'product_name' => 'Custom Pet Blanket',
            'orientation' => '竖版（portrait）',
            'aspect_ratio' => '2:3',
            'print_dimensions' => '1000 × 1500 mm',
        ]);
        $check(is_string($prompt) && $prompt !== '', 'Blanket prompt did not render: ' . $variant_feature);
        $check(! str_contains((string) $prompt, '{{'), 'Blanket prompt contains unresolved variables: ' . $variant_feature);
        $check(str_contains((string) $prompt, '2:3'), 'Blanket prompt is missing its rendered aspect ratio: ' . $variant_feature);
        $check(str_contains((string) $prompt, '1000 × 1500 mm'), 'Blanket prompt is missing its rendered dimensions: ' . $variant_feature);
    }
}

$styles = \Tikool\FrameCustomizer\AI_Styles::blanket_defaults();
$style_features = array_values(array_filter(array_column($styles, 'ai_feature')));
$check($style_features === array_keys($expected), 'Blanket style defaults do not match the six prompt profiles.');

WP_CLI::success('Verified six blanket prompt profiles and 24 background variants.');
