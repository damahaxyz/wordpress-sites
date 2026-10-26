<?php
/**
 * Feature prompt templates.
 */

declare(strict_types=1);

namespace Tikool\AI;

if (! defined('ABSPATH')) {
    exit;
}

final class Prompts
{
    private const VARIANT_SEPARATOR = '__variant__';

    /**
     * @return array<string, array{label: string, enabled: bool, preview_image_id: int, template: string, variants: array<string, array{label: string, prompt: string}>}>
     */
    public static function profiles(): array
    {
        $settings = Settings::all();
        $stored = $settings['prompt_profiles'] ?? [];
        $profiles = [];

        if (is_array($stored)) {
            foreach ($stored as $key => $profile) {
                $key = sanitize_key((string) $key);
                if ($key === '' || ! is_array($profile)) {
                    continue;
                }
                $profiles[$key] = [
                    'label' => sanitize_text_field((string) ($profile['label'] ?? $key)),
                    'enabled' => ! empty($profile['enabled']),
                    'preview_image_id' => absint($profile['preview_image_id'] ?? 0),
                    'template' => sanitize_textarea_field((string) ($profile['template'] ?? '')),
                    'variants' => self::sanitize_variants($profile['variants'] ?? []),
                ];
            }
        }

        /**
         * Register or alter reusable feature prompts.
         *
         * @param array<string, array{label: string, enabled: bool, preview_image_id: int, template: string, variants: array<string, array{label: string, prompt: string}>}> $profiles Prompt profiles.
         */
        return (array) apply_filters('tikool_ai_prompt_profiles', $profiles);
    }

    /**
     * Render one configured feature prompt.
     *
     * @param array<string, scalar|null> $variables Template variables without braces.
     * @return string|\WP_Error
     */
    public static function render(string $feature, array $variables = [])
    {
        [$feature, $variant_key] = self::split_feature($feature);
        $profiles = self::profiles();
        $profile = $profiles[$feature] ?? null;

        if (! is_array($profile) || empty($profile['enabled'])) {
            return new \WP_Error(
                'tikool_ai_unknown_feature',
                __('AI 功能未配置或已停用。', 'tikool-ai'),
                ['feature' => $feature]
            );
        }

        $template = trim((string) ($profile['template'] ?? ''));
        if ($template === '') {
            return new \WP_Error(
                'tikool_ai_empty_prompt',
                __('此 AI 功能的提示词为空。', 'tikool-ai'),
                ['feature' => $feature]
            );
        }

        $variant_prompt = '';
        if ($variant_key !== '') {
            $variant = $profile['variants'][$variant_key] ?? null;
            if (! is_array($variant)) {
                return new \WP_Error(
                    'tikool_ai_unknown_variant',
                    __('AI 生成变体未配置或已停用。', 'tikool-ai'),
                    ['feature' => $feature, 'variant' => $variant_key]
                );
            }
            $variant_prompt = trim((string) ($variant['prompt'] ?? ''));
            $variables['background_prompt'] = $variant_prompt;
        }

        $replacements = [];
        foreach ($variables as $name => $value) {
            $name = sanitize_key((string) $name);
            if ($name === '' || (! is_scalar($value) && $value !== null)) {
                continue;
            }
            $replacements['{{' . $name . '}}'] = sanitize_textarea_field((string) $value);
        }

        $has_background_placeholder = str_contains($template, '{{background_prompt}}');
        $prompt = strtr($template, $replacements);
        $prompt = (string) preg_replace('/\{\{[a-z0-9_-]+\}\}/i', '', $prompt);
        if ($variant_prompt !== '' && ! $has_background_placeholder) {
            $prompt .= ' ' . $variant_prompt;
        }
        $prompt = trim((string) preg_replace('/[ \t]+/', ' ', $prompt));

        /**
         * Filter a fully rendered prompt immediately before it is sent.
         *
         * @param string               $prompt    Rendered prompt.
         * @param string               $feature   Feature key.
         * @param array<string, mixed> $variables Original variables.
         */
        return trim((string) apply_filters('tikool_ai_rendered_prompt', $prompt, $feature, $variables));
    }

    /** @return array<string, array{label: string, prompt: string}> */
    public static function variants(string $feature): array
    {
        [$feature] = self::split_feature($feature);
        $profile = self::profiles()[$feature] ?? null;
        return is_array($profile) ? (array) ($profile['variants'] ?? []) : [];
    }

    public static function feature_for_variant(string $feature, string $variant_key): string
    {
        $feature = sanitize_key($feature);
        $variant_key = sanitize_key($variant_key);
        return $variant_key === '' ? $feature : $feature . self::VARIANT_SEPARATOR . $variant_key;
    }

    /** @return array{0: string, 1: string} */
    private static function split_feature(string $feature): array
    {
        $feature = sanitize_key($feature);
        if (! str_contains($feature, self::VARIANT_SEPARATOR)) {
            return [$feature, ''];
        }
        [$base, $variant] = array_pad(explode(self::VARIANT_SEPARATOR, $feature, 2), 2, '');
        return [sanitize_key($base), sanitize_key($variant)];
    }

    /**
     * @param mixed $raw
     * @return array<string, array{label: string, prompt: string}>
     */
    private static function sanitize_variants($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $variants = [];
        foreach (array_slice($raw, 0, 8, true) as $raw_key => $raw_variant) {
            if (! is_array($raw_variant)) {
                continue;
            }
            $key = sanitize_key((string) ($raw_variant['key'] ?? $raw_key));
            $prompt = sanitize_textarea_field((string) ($raw_variant['prompt'] ?? ''));
            if ($key === '' || $prompt === '' || isset($variants[$key])) {
                continue;
            }
            $label = sanitize_text_field((string) ($raw_variant['label'] ?? $key));
            $variants[$key] = [
                'label' => $label !== '' ? $label : $key,
                'prompt' => $prompt,
            ];
        }
        return $variants;
    }
}
