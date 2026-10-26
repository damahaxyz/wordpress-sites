<?php

use Tikool\AI\Settings;

$settings = get_option(Settings::OPTION_NAME, []);
if (! is_array($settings)) {
    WP_CLI::error('Tikool AI settings are unavailable.');
}

$profiles = isset($settings['prompt_profiles']) && is_array($settings['prompt_profiles'])
    ? $settings['prompt_profiles']
    : [];
$defaults = Settings::defaults();
$default_profiles = is_array($defaults['prompt_profiles'] ?? null) ? $defaults['prompt_profiles'] : [];
$frame_features = [
    'pet_portrait_minimalist',
    'pet_portrait_watercolor',
    'pet_portrait_oil',
];
$composition_zh = '画面采用 {{orientation}} {{aspect_ratio}} 比例（宽:高），对应 {{print_dimensions}} 的实际印刷区域，并严格按照该比例填满完整画布。';
$composition_en = 'Use a strict {{orientation}} {{aspect_ratio}} composition (width:height) matching the {{print_dimensions}} print area, and fill the complete canvas at that ratio.';
$backup = [];
$updated = [];

foreach ($profiles as $feature => $profile) {
    if (! is_array($profile)) {
        continue;
    }
    $is_frame = in_array($feature, $frame_features, true);
    $is_blanket = str_starts_with((string) $feature, 'blanket_portrait_');
    if (! $is_frame && ! $is_blanket) {
        continue;
    }

    $template = (string) ($profile['template'] ?? '');
    $backup[$feature] = $template;
    if ($is_blanket && isset($default_profiles[$feature]['template'])) {
        $template = (string) $default_profiles[$feature]['template'];
    } else {
        $template = (string) preg_replace('/画面采用竖版\s*4:5\s*比例[，。]?/u', $composition_zh, $template, 1);
        $template = str_replace('in a strict vertical 4:5 composition', $composition_en, $template);
        $template = str_replace('Use a strict vertical 4:5 composition', $composition_en, $template);
        if (
            ! str_contains($template, '{{orientation}}')
            || ! str_contains($template, '{{aspect_ratio}}')
            || ! str_contains($template, '{{print_dimensions}}')
        ) {
            $template = $composition_zh . "\n\n" . ltrim($template);
        }
    }

    $profiles[$feature]['template'] = $template;
    $updated[$feature] = [
        'orientation' => str_contains($template, '{{orientation}}'),
        'aspect_ratio' => str_contains($template, '{{aspect_ratio}}'),
        'print_dimensions' => str_contains($template, '{{print_dimensions}}'),
    ];
}

if (count($updated) !== 9) {
    WP_CLI::error(sprintf('Expected 9 prompt profiles, found %d.', count($updated)));
}
foreach ($updated as $feature => $checks) {
    if (in_array(false, $checks, true)) {
        WP_CLI::error('Composition variables are incomplete: ' . $feature);
    }
}

add_option('tikool_ai_composition_prompts_backup_20260930', $backup, '', false);
$settings['prompt_profiles'] = $profiles;
update_option(Settings::OPTION_NAME, $settings, false);

WP_CLI::success('Updated 9 prompt profiles with orientation, aspect_ratio, and print_dimensions variables.');
