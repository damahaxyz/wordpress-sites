<?php
/**
 * Product, variation, template, and geometry configuration.
 */

declare(strict_types=1);

namespace Tikool\FrameCustomizer;

if (! defined('ABSPATH')) {
    exit;
}

final class Config
{
    public const SCHEMA_VERSION = 1;
    public const META_ENABLED = '_tikool_fc_enabled';
    public const META_TEMPLATE = '_tikool_fc_template';
    public const META_NAME_LABEL = '_tikool_fc_name_label';
    public const META_NAME_REQUIRED = '_tikool_fc_name_required';
    public const META_PHOTO_LABEL = '_tikool_fc_photo_label';
    public const META_SIZE_ATTRIBUTE = '_tikool_fc_size_attribute';
    public const META_FRAME_ATTRIBUTE = '_tikool_fc_frame_attribute';
    public const META_MAT_ATTRIBUTE = '_tikool_fc_mat_attribute';
    public const META_ORIENTATION_ATTRIBUTE = '_tikool_fc_orientation_attribute';
    public const PRODUCT_FIELD_PREFIX = '_tikool_fc_default_';
    public const VARIATION_FIELD_PREFIX = '_tikool_fc_';
    public const LEGACY_ENABLED_META = '_tikool_requires_pet_photo';

    /**
     * @return array<string, array{label: string, description: string, stand: bool, defaults: array<string, float|string>}>
     */
    public static function templates(): array
    {
        $templates = [
            'wall-frame' => [
                'label' => __('Wall frame', 'tikool-frame-customizer'),
                'description' => __('Front-facing wall frame with optional mat board.', 'tikool-frame-customizer'),
                'stand' => false,
                'defaults' => [
                    'outer_width_mm' => 228.6,
                    'outer_height_mm' => 279.4,
                    'inner_width_mm' => 203.2,
                    'inner_height_mm' => 254.0,
                    'artwork_width_mm' => 203.2,
                    'artwork_height_mm' => 254.0,
                    'print_width_mm' => 203.2,
                    'print_height_mm' => 254.0,
                    'frame_face_mm' => 12.7,
                    'mat_top_mm' => 0.0,
                    'mat_right_mm' => 0.0,
                    'mat_bottom_mm' => 0.0,
                    'mat_left_mm' => 0.0,
                    'mat_thickness_mm' => 1.0,
                    'bleed_mm' => 3.0,
                    'min_dpi' => 180.0,
                    'production_dpi' => 300.0,
                    'frame_color' => '#c89552',
                    'mat_color' => '#f7f3eb',
                    'frame_texture_url' => '',
                    'overlay_url' => '',
                ],
            ],
            'desktop-frame' => [
                'label' => __('Desktop frame', 'tikool-frame-customizer'),
                'description' => __('Desktop frame with an optional mat and a stand shown in the preview.', 'tikool-frame-customizer'),
                'stand' => true,
                'defaults' => [
                    'outer_width_mm' => 177.8,
                    'outer_height_mm' => 228.6,
                    'inner_width_mm' => 152.4,
                    'inner_height_mm' => 203.2,
                    'artwork_width_mm' => 152.4,
                    'artwork_height_mm' => 203.2,
                    'print_width_mm' => 152.4,
                    'print_height_mm' => 203.2,
                    'frame_face_mm' => 12.7,
                    'mat_top_mm' => 0.0,
                    'mat_right_mm' => 0.0,
                    'mat_bottom_mm' => 0.0,
                    'mat_left_mm' => 0.0,
                    'mat_thickness_mm' => 1.0,
                    'bleed_mm' => 3.0,
                    'min_dpi' => 180.0,
                    'production_dpi' => 300.0,
                    'frame_color' => '#c89552',
                    'mat_color' => '#f7f3eb',
                    'frame_texture_url' => '',
                    'overlay_url' => '',
                ],
            ],
        ];

        /**
         * Add reusable customizer templates without changing the renderer.
         *
         * @param array<string, array<string, mixed>> $templates Registered templates.
         */
        return (array) apply_filters('tikool_frame_customizer_templates', $templates);
    }

    /**
     * @return array<string, array{label: string, type: string, step?: string, min?: string, description?: string}>
     */
    public static function geometry_fields(): array
    {
        return [
            'outer_width_mm' => ['label' => __('Outer frame width (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'outer_height_mm' => ['label' => __('Outer frame height (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'inner_width_mm' => ['label' => __('Inner opening width (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'inner_height_mm' => ['label' => __('Inner opening height (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'artwork_width_mm' => ['label' => __('Visible artwork width (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'artwork_height_mm' => ['label' => __('Visible artwork height (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'print_width_mm' => ['label' => __('Print width (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'print_height_mm' => ['label' => __('Print height (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'frame_face_mm' => ['label' => __('Frame face width (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_top_mm' => ['label' => __('Mat top border (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_right_mm' => ['label' => __('Mat right border (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_bottom_mm' => ['label' => __('Mat bottom border (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_left_mm' => ['label' => __('Mat left border (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_thickness_mm' => ['label' => __('Mat thickness (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'bleed_mm' => ['label' => __('Print bleed / overlap (mm)', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'min_dpi' => ['label' => __('Minimum recommended DPI', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '1', 'min' => '72'],
            'production_dpi' => ['label' => __('Production DPI', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '1', 'min' => '72'],
            'frame_color' => ['label' => __('Frame preview color', 'tikool-frame-customizer'), 'type' => 'color'],
            'mat_color' => ['label' => __('Mat preview color', 'tikool-frame-customizer'), 'type' => 'color'],
            'frame_texture_url' => ['label' => __('Frame texture image URL', 'tikool-frame-customizer'), 'type' => 'url', 'description' => __('Optional seamless texture or transparent frame asset.', 'tikool-frame-customizer')],
            'overlay_url' => ['label' => __('Preview overlay image URL', 'tikool-frame-customizer'), 'type' => 'url', 'description' => __('Optional transparent product overlay, such as a desktop stand.', 'tikool-frame-customizer')],
        ];
    }

    public static function is_enabled(int $product_id): bool
    {
        $parent_id = wp_get_post_parent_id($product_id);
        $owner_id = $parent_id > 0 ? $parent_id : $product_id;
        return get_post_meta($owner_id, self::META_ENABLED, true) === 'yes'
            || get_post_meta($owner_id, self::LEGACY_ENABLED_META, true) === 'yes';
    }

    /**
     * @return array<string, mixed>
     */
    public static function product_settings(int $product_id): array
    {
        $template = sanitize_key((string) get_post_meta($product_id, self::META_TEMPLATE, true));
        $templates = self::templates();
        if (! isset($templates[$template])) {
            $template = 'wall-frame';
        }

        return [
            'template' => $template,
            'template_label' => (string) ($templates[$template]['label'] ?? $template),
            'stand' => ! empty($templates[$template]['stand']),
            'name_label' => sanitize_text_field((string) get_post_meta($product_id, self::META_NAME_LABEL, true)) ?: __('Pet\'s name', 'tikool-frame-customizer'),
            'name_required' => get_post_meta($product_id, self::META_NAME_REQUIRED, true) !== 'no',
            'photo_label' => sanitize_text_field((string) get_post_meta($product_id, self::META_PHOTO_LABEL, true)) ?: __('Pet photo', 'tikool-frame-customizer'),
            'attribute_map' => [
                'size' => self::attribute_setting($product_id, self::META_SIZE_ATTRIBUTE, 'size'),
                'frame' => self::attribute_setting($product_id, self::META_FRAME_ATTRIBUTE, 'frame-color'),
                'mat' => self::attribute_setting($product_id, self::META_MAT_ATTRIBUTE, 'mat-style'),
                'orientation' => self::attribute_setting($product_id, self::META_ORIENTATION_ATTRIBUTE, 'orientation'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function product_snapshot(\WC_Product $product): array
    {
        $owner_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $settings = self::product_settings($owner_id);
        $geometry = self::effective_geometry($owner_id, $product instanceof \WC_Product_Variation ? $product : null, $settings);

        return self::build_snapshot($owner_id, $product->get_id(), $product->get_attributes(), $settings, $geometry);
    }

    /**
     * @return array<string, mixed>
     */
    public static function variation_snapshot(\WC_Product_Variation $variation): array
    {
        $product_id = $variation->get_parent_id();
        $settings = self::product_settings($product_id);
        $geometry = self::effective_geometry($product_id, $variation, $settings);

        return self::build_snapshot($product_id, $variation->get_id(), $variation->get_attributes(), $settings, $geometry);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $settings
     * @param array<string, float|string> $geometry
     * @return array<string, mixed>
     */
    private static function build_snapshot(int $product_id, int $variation_id, array $attributes, array $settings, array $geometry): array
    {
        $role_values = [];
        foreach ((array) $settings['attribute_map'] as $role => $attribute_name) {
            $role_values[$role] = self::attribute_value($attributes, (string) $attribute_name);
        }
        $display_attributes = self::display_attributes($attributes);

        $has_mat = self::has_mat($role_values['mat'] ?? '', $geometry);
        if (! $has_mat) {
            $geometry['mat_top_mm'] = 0.0;
            $geometry['mat_right_mm'] = 0.0;
            $geometry['mat_bottom_mm'] = 0.0;
            $geometry['mat_left_mm'] = 0.0;
            $geometry['artwork_width_mm'] = (float) $geometry['inner_width_mm'];
            $geometry['artwork_height_mm'] = (float) $geometry['inner_height_mm'];
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'productId' => $product_id,
            'variationId' => $variation_id,
            'template' => $settings['template'],
            'templateLabel' => $settings['template_label'],
            'stand' => (bool) $settings['stand'],
            'nameLabel' => $settings['name_label'],
            'nameRequired' => (bool) $settings['name_required'],
            'photoLabel' => $settings['photo_label'],
            'roles' => $role_values,
            'attributes' => $display_attributes,
            'hasMat' => $has_mat,
            'geometry' => $geometry,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, float|string>
     */
    private static function effective_geometry(int $product_id, ?\WC_Product_Variation $variation, array $settings): array
    {
        $templates = self::templates();
        $template = (string) $settings['template'];
        $geometry = (array) ($templates[$template]['defaults'] ?? $templates['wall-frame']['defaults']);

        foreach (self::geometry_fields() as $field => $definition) {
            $product_key = self::PRODUCT_FIELD_PREFIX . $field;
            if (metadata_exists('post', $product_id, $product_key)) {
                $stored = get_post_meta($product_id, $product_key, true);
                if ($stored !== '') {
                    $geometry[$field] = self::sanitize_field_value($field, $stored, $definition['type']);
                }
            }

            if ($variation) {
                $variation_key = self::VARIATION_FIELD_PREFIX . $field;
                if (metadata_exists('post', $variation->get_id(), $variation_key)) {
                    $stored = get_post_meta($variation->get_id(), $variation_key, true);
                    if ($stored !== '') {
                        $geometry[$field] = self::sanitize_field_value($field, $stored, $definition['type']);
                    }
                }
            }
        }

        if ($variation && ! self::has_explicit_dimensions($product_id, $variation->get_id())) {
            $geometry = array_merge($geometry, self::legacy_geometry($variation, $settings, $geometry));
        }

        return self::normalize_geometry($geometry);
    }

    private static function has_explicit_dimensions(int $product_id, int $variation_id): bool
    {
        return metadata_exists('post', $variation_id, self::VARIATION_FIELD_PREFIX . 'inner_width_mm')
            || metadata_exists('post', $product_id, self::PRODUCT_FIELD_PREFIX . 'inner_width_mm');
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, float|string> $base
     * @return array<string, float|string>
     */
    private static function legacy_geometry(\WC_Product_Variation $variation, array $settings, array $base): array
    {
        $attributes = $variation->get_attributes();
        $size = self::attribute_value($attributes, (string) $settings['attribute_map']['size']);
        $mat = self::attribute_value($attributes, (string) $settings['attribute_map']['mat']);
        $frame = self::attribute_value($attributes, (string) $settings['attribute_map']['frame']);
        $matches = [];
        preg_match('/([0-9]+(?:\.[0-9]+)?)\D+([0-9]+(?:\.[0-9]+)?)/u', $size, $matches);
        if (count($matches) < 3) {
            return $base;
        }

        $bleed = (float) ($base['bleed_mm'] ?? 3.0);
        $size_key = sprintf('%gx%g', (float) $matches[1], (float) $matches[2]);
        $supplier_profiles = [
            // Supplier dimensions are the real photo sizes, not theoretical inch conversions.
            // Mat openings overlap the printed photo by the configured bleed on every edge.
            '8x10' => ['inner' => [203.0, 253.0], 'mat_print' => [127.0, 178.0]],
            '12x16' => ['inner' => [303.0, 405.0], 'mat_print' => [203.0, 303.0]],
            '16x20' => ['inner' => [404.0, 506.0], 'mat_print' => [303.0, 405.0]],
        ];
        $profile = $supplier_profiles[$size_key] ?? null;
        $inner_width = $profile ? $profile['inner'][0] : (float) $matches[1] * 25.4;
        $inner_height = $profile ? $profile['inner'][1] : (float) $matches[2] * 25.4;
        if ($inner_width > $inner_height) {
            [$inner_width, $inner_height] = [$inner_height, $inner_width];
        }

        $has_mat = self::mat_label_is_enabled($mat);
        $fallback_print = [$inner_width * 0.72, $inner_height * 0.72];
        $mat_print = $profile['mat_print'] ?? $fallback_print;
        $print_width = $has_mat ? (float) $mat_print[0] : $inner_width;
        $print_height = $has_mat ? (float) $mat_print[1] : $inner_height;
        $artwork_width = $has_mat ? max(1.0, $print_width - (2 * $bleed)) : $inner_width;
        $artwork_height = $has_mat ? max(1.0, $print_height - (2 * $bleed)) : $inner_height;
        $horizontal_mat = max(0.0, ($inner_width - $artwork_width) / 2);
        $vertical_mat = max(0.0, ($inner_height - $artwork_height) / 2);

        $frame_color = self::frame_color_from_label($frame, (string) ($base['frame_color'] ?? '#c89552'));

        return [
            'outer_width_mm' => $inner_width + 25.4,
            'outer_height_mm' => $inner_height + 25.4,
            'inner_width_mm' => $inner_width,
            'inner_height_mm' => $inner_height,
            'artwork_width_mm' => $artwork_width,
            'artwork_height_mm' => $artwork_height,
            'print_width_mm' => $print_width,
            'print_height_mm' => $print_height,
            'frame_face_mm' => 12.7,
            'mat_top_mm' => $has_mat ? $vertical_mat : 0.0,
            'mat_right_mm' => $has_mat ? $horizontal_mat : 0.0,
            'mat_bottom_mm' => $has_mat ? $vertical_mat : 0.0,
            'mat_left_mm' => $has_mat ? $horizontal_mat : 0.0,
            'mat_thickness_mm' => 1.0,
            'frame_color' => $frame_color,
        ];
    }

    /**
     * @param array<string, float|string> $geometry
     * @return array<string, float|string>
     */
    private static function normalize_geometry(array $geometry): array
    {
        foreach (self::geometry_fields() as $field => $definition) {
            if (! array_key_exists($field, $geometry)) {
                continue;
            }
            $geometry[$field] = self::sanitize_field_value($field, $geometry[$field], $definition['type']);
        }

        $inner_width = max(1.0, (float) ($geometry['inner_width_mm'] ?? 1));
        $inner_height = max(1.0, (float) ($geometry['inner_height_mm'] ?? 1));
        $geometry['inner_width_mm'] = $inner_width;
        $geometry['inner_height_mm'] = $inner_height;
        $geometry['outer_width_mm'] = max($inner_width + 2.0, (float) ($geometry['outer_width_mm'] ?? $inner_width + 2));
        $geometry['outer_height_mm'] = max($inner_height + 2.0, (float) ($geometry['outer_height_mm'] ?? $inner_height + 2));
        $geometry['artwork_width_mm'] = min($inner_width, max(1.0, (float) ($geometry['artwork_width_mm'] ?? $inner_width)));
        $geometry['artwork_height_mm'] = min($inner_height, max(1.0, (float) ($geometry['artwork_height_mm'] ?? $inner_height)));
        $geometry['print_width_mm'] = max((float) $geometry['artwork_width_mm'], (float) ($geometry['print_width_mm'] ?? $geometry['artwork_width_mm']));
        $geometry['print_height_mm'] = max((float) $geometry['artwork_height_mm'], (float) ($geometry['print_height_mm'] ?? $geometry['artwork_height_mm']));
        $geometry['min_dpi'] = max(72.0, (float) ($geometry['min_dpi'] ?? 180));
        $geometry['production_dpi'] = max(72.0, (float) ($geometry['production_dpi'] ?? 300));
        $geometry['mat_thickness_mm'] = max(0.0, (float) ($geometry['mat_thickness_mm'] ?? 1));

        return $geometry;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function attribute_value(array $attributes, string $wanted): string
    {
        $wanted = self::normalize_attribute_name($wanted);
        foreach ($attributes as $key => $value) {
            if ($value instanceof \WC_Product_Attribute) {
                if (self::normalize_attribute_name($value->get_name()) !== $wanted) {
                    continue;
                }
                return implode(', ', self::product_attribute_options($value));
            }
            if (self::normalize_attribute_name((string) $key) !== $wanted) {
                continue;
            }

            $value = (string) $value;
            $taxonomy = str_starts_with((string) $key, 'pa_') ? (string) $key : 'pa_' . self::normalize_attribute_name((string) $key);
            if (taxonomy_exists($taxonomy)) {
                $term = get_term_by('slug', $value, $taxonomy);
                if ($term instanceof \WP_Term) {
                    return $term->name;
                }
            }

            return $value;
        }

        return '';
    }

    /**
     * Convert WooCommerce attribute objects into JSON-safe display values.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, string>
     */
    private static function display_attributes(array $attributes): array
    {
        $display = [];
        foreach ($attributes as $key => $value) {
            if ($value instanceof \WC_Product_Attribute) {
                $display[$value->get_name()] = implode(', ', self::product_attribute_options($value));
            } elseif (is_scalar($value)) {
                $display[(string) $key] = (string) $value;
            }
        }
        return $display;
    }

    /**
     * @return array<int, string>
     */
    private static function product_attribute_options(\WC_Product_Attribute $attribute): array
    {
        $values = [];
        $taxonomy = $attribute->is_taxonomy() ? $attribute->get_name() : '';
        foreach ($attribute->get_options() as $option) {
            if ($taxonomy !== '' && is_numeric($option)) {
                $term = get_term((int) $option, $taxonomy);
                if ($term instanceof \WP_Term) {
                    $values[] = $term->name;
                    continue;
                }
            }
            $values[] = (string) $option;
        }
        return $values;
    }

    private static function normalize_attribute_name(string $name): string
    {
        $name = preg_replace('/^attribute_/', '', strtolower($name)) ?: $name;
        $name = preg_replace('/^pa_/', '', $name) ?: $name;
        return str_replace('_', '-', sanitize_key($name));
    }

    /**
     * @param array<string, float|string> $geometry
     */
    private static function has_mat(string $label, array $geometry): bool
    {
        if ($label !== '') {
            return self::mat_label_is_enabled($label);
        }

        return ((float) ($geometry['mat_top_mm'] ?? 0) + (float) ($geometry['mat_right_mm'] ?? 0)
            + (float) ($geometry['mat_bottom_mm'] ?? 0) + (float) ($geometry['mat_left_mm'] ?? 0)) > 0;
    }

    private static function mat_label_is_enabled(string $label): bool
    {
        $label = strtolower(trim($label));
        if ($label === '') {
            return false;
        }
        foreach (['no mat', 'without mat', 'none', '无卡纸', '無卡紙'] as $negative) {
            if (str_contains($label, $negative)) {
                return false;
            }
        }
        return true;
    }

    private static function frame_color_from_label(string $label, string $fallback): string
    {
        $label = strtolower($label);
        if (str_contains($label, 'black') || str_contains($label, '黑')) {
            return '#292a29';
        }
        if (str_contains($label, 'walnut') || str_contains($label, '胡桃')) {
            return '#62371f';
        }
        if (str_contains($label, 'white') || str_contains($label, '白')) {
            return '#eee9df';
        }
        return sanitize_hex_color($fallback) ?: '#c89552';
    }

    /**
     * @param mixed $value
     * @return float|string
     */
    public static function sanitize_field_value(string $field, $value, string $type)
    {
        if ($type === 'color') {
            return sanitize_hex_color((string) $value) ?: ($field === 'mat_color' ? '#f7f3eb' : '#c89552');
        }
        if ($type === 'url') {
            return esc_url_raw((string) $value);
        }
        return max(0.0, (float) wc_format_decimal((string) $value));
    }

    private static function attribute_setting(int $product_id, string $meta_key, string $fallback): string
    {
        $stored = self::normalize_attribute_name((string) get_post_meta($product_id, $meta_key, true));
        return $stored !== '' ? $stored : $fallback;
    }

    public static function migrate_legacy_products(): void
    {
        if (! function_exists('wc_get_product')) {
            return;
        }

        $product_ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => self::LEGACY_ENABLED_META,
            'meta_value' => 'yes',
        ]);

        foreach ($product_ids as $product_id) {
            update_post_meta($product_id, self::META_ENABLED, 'yes');
            if (! get_post_meta($product_id, self::META_TEMPLATE, true)) {
                update_post_meta($product_id, self::META_TEMPLATE, 'wall-frame');
            }
            if (! metadata_exists('post', $product_id, self::META_NAME_REQUIRED)) {
                update_post_meta($product_id, self::META_NAME_REQUIRED, 'yes');
            }

            $product = wc_get_product($product_id);
            if (! $product instanceof \WC_Product_Variable) {
                continue;
            }

            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (! $variation instanceof \WC_Product_Variation || self::has_explicit_dimensions((int) $product_id, (int) $variation_id)) {
                    continue;
                }
                $snapshot = self::variation_snapshot($variation);
                foreach ((array) $snapshot['geometry'] as $field => $value) {
                    if (isset(self::geometry_fields()[$field])) {
                        update_post_meta($variation_id, self::VARIATION_FIELD_PREFIX . $field, $value);
                    }
                }
            }
        }
    }
}
