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
    public const SCHEMA_VERSION = 9;
    public const META_ENABLED = '_tikool_fc_enabled';
    public const META_TEMPLATE = '_tikool_fc_template';
    public const META_NAME_LABEL = '_tikool_fc_name_label';
    public const META_NAME_REQUIRED = '_tikool_fc_name_required';
    public const META_PHOTO_LABEL = '_tikool_fc_photo_label';
    public const META_SIZE_ATTRIBUTE = '_tikool_fc_size_attribute';
    public const META_FRAME_ATTRIBUTE = '_tikool_fc_frame_attribute';
    public const META_MAT_ATTRIBUTE = '_tikool_fc_mat_attribute';
    public const META_ORIENTATION_ATTRIBUTE = '_tikool_fc_orientation_attribute';
    public const META_WOOD_ATTRIBUTE = '_tikool_fc_wood_attribute';
    public const PRODUCT_FIELD_PREFIX = '_tikool_fc_default_';
    public const VARIATION_FIELD_PREFIX = '_tikool_fc_';
    public const LEGACY_ENABLED_META = '_tikool_requires_pet_photo';

    /**
     * @return array<string, array{label: string, description: string, renderer: string, stand: bool, defaults: array<string, float|string>}>
     */
    public static function templates(): array
    {
        $templates = [
            'wall-frame' => [
                'label' => __('墙挂相框', 'tikool-frame-customizer'),
                'description' => __('正面展示的墙挂相框，可选择是否使用卡纸。', 'tikool-frame-customizer'),
                'renderer' => 'frame',
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
                    'mat_top_mm' => 0.0,
                    'mat_right_mm' => 0.0,
                    'mat_bottom_mm' => 0.0,
                    'mat_left_mm' => 0.0,
                    'mat_thickness_mm' => 1.0,
                    'min_dpi' => 180.0,
                    'frame_color' => '#c89552',
                    'mat_color' => '#f7f3eb',
                    'frame_texture_url' => '',
                    'overlay_url' => '',
                    'blanket_color' => '#eee7df',
                    'blanket_texture_url' => '',
                ],
            ],
            'desktop-frame' => [
                'label' => __('桌面相框', 'tikool-frame-customizer'),
                'description' => __('带桌面支架的相框，可选择是否使用卡纸，预览中会显示支架效果。', 'tikool-frame-customizer'),
                'renderer' => 'frame',
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
                    'mat_top_mm' => 0.0,
                    'mat_right_mm' => 0.0,
                    'mat_bottom_mm' => 0.0,
                    'mat_left_mm' => 0.0,
                    'mat_thickness_mm' => 1.0,
                    'min_dpi' => 180.0,
                    'frame_color' => '#c89552',
                    'mat_color' => '#f7f3eb',
                    'frame_texture_url' => '',
                    'overlay_url' => '',
                    'blanket_color' => '#eee7df',
                    'blanket_texture_url' => '',
                ],
            ],
            'blanket' => [
                'label' => __('定制毛毯', 'tikool-frame-customizer'),
                'description' => __('正面毛毯效果预览，支持 50 × 60 英寸和 60 × 80 英寸。', 'tikool-frame-customizer'),
                'renderer' => 'blanket',
                'stand' => false,
                'defaults' => [
                    'outer_width_mm' => 1270.0,
                    'outer_height_mm' => 1524.0,
                    'inner_width_mm' => 1270.0,
                    'inner_height_mm' => 1524.0,
                    'artwork_width_mm' => 1270.0,
                    'artwork_height_mm' => 1524.0,
                    'print_width_mm' => 1270.0,
                    'print_height_mm' => 1524.0,
                    'mat_top_mm' => 0.0,
                    'mat_right_mm' => 0.0,
                    'mat_bottom_mm' => 0.0,
                    'mat_left_mm' => 0.0,
                    'mat_thickness_mm' => 0.0,
                    'min_dpi' => 100.0,
                    'frame_color' => '#eee7df',
                    'mat_color' => '#eee7df',
                    'frame_texture_url' => '',
                    'overlay_url' => '',
                    'blanket_color' => '#eee7df',
                    'blanket_texture_url' => '',
                ],
            ],
            'keepsake-box' => [
                'label' => __('宠物纪念收藏盒', 'tikool-frame-customizer'),
                'description' => __('盒盖内侧使用正方形照片，盒盖正面固定雕刻宠物脚印与宠物名称。', 'tikool-frame-customizer'),
                'renderer' => 'keepsake',
                'stand' => false,
                'supports_ai' => false,
                'supports_name_overlay' => false,
                'requires_engraving' => true,
                'name_max_length' => 15,
                'defaults' => [
                    'outer_width_mm' => 50.0,
                    'outer_height_mm' => 50.0,
                    'inner_width_mm' => 40.0,
                    'inner_height_mm' => 40.0,
                    'artwork_width_mm' => 40.0,
                    'artwork_height_mm' => 40.0,
                    'print_width_mm' => 40.0,
                    'print_height_mm' => 40.0,
                    'box_height_mm' => 35.0,
                    'vial_count' => 2.0,
                    'engraving_width_mm' => 36.0,
                    'engraving_height_mm' => 36.0,
                    'mat_top_mm' => 0.0,
                    'mat_right_mm' => 0.0,
                    'mat_bottom_mm' => 0.0,
                    'mat_left_mm' => 0.0,
                    'mat_thickness_mm' => 0.0,
                    'min_dpi' => 300.0,
                    'frame_color' => '#6b3f25',
                    'mat_color' => '#ead8bd',
                    'frame_texture_url' => '',
                    'overlay_url' => '',
                    'blanket_color' => '#eee7df',
                    'blanket_texture_url' => '',
                    'wood_color' => '#6b3f25',
                    'wood_texture_url' => '',
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
            'outer_width_mm' => ['label' => __('产品预览宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'outer_height_mm' => ['label' => __('产品预览高度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'inner_width_mm' => ['label' => __('内容区域宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'inner_height_mm' => ['label' => __('内容区域高度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'artwork_width_mm' => ['label' => __('画面可视宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'artwork_height_mm' => ['label' => __('画面可视高度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'print_width_mm' => ['label' => __('打印照片宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'print_height_mm' => ['label' => __('打印照片高度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1'],
            'mat_top_mm' => ['label' => __('卡纸上边宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_right_mm' => ['label' => __('卡纸右边宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_bottom_mm' => ['label' => __('卡纸下边宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_left_mm' => ['label' => __('卡纸左边宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'mat_thickness_mm' => ['label' => __('卡纸厚度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '0'],
            'min_dpi' => ['label' => __('最低建议 DPI', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '1', 'min' => '72'],
            'frame_color' => ['label' => __('相框预览颜色', 'tikool-frame-customizer'), 'type' => 'color', 'renderer' => 'frame'],
            'mat_color' => ['label' => __('卡纸预览颜色', 'tikool-frame-customizer'), 'type' => 'color', 'renderer' => 'frame'],
            'frame_texture_url' => ['label' => __('相框纹理图片 URL', 'tikool-frame-customizer'), 'type' => 'url', 'renderer' => 'frame', 'description' => __('可选：无缝纹理图或透明相框素材。', 'tikool-frame-customizer')],
            'overlay_url' => ['label' => __('预览叠加图片 URL', 'tikool-frame-customizer'), 'type' => 'url', 'description' => __('可选：透明产品叠加图，例如桌面支架。', 'tikool-frame-customizer')],
            'blanket_color' => ['label' => __('毛毯底色', 'tikool-frame-customizer'), 'type' => 'color', 'renderer' => 'blanket'],
            'blanket_texture_url' => ['label' => __('毛毯纹理图片 URL', 'tikool-frame-customizer'), 'type' => 'url', 'renderer' => 'blanket', 'description' => __('可选：用于覆盖在画面上的透明布料纹理。', 'tikool-frame-customizer')],
            'box_height_mm' => ['label' => __('盒子高度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1', 'renderer' => 'keepsake'],
            'vial_count' => ['label' => __('玻璃瓶数量', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '1', 'min' => '1', 'renderer' => 'keepsake'],
            'engraving_width_mm' => ['label' => __('雕刻安全区宽度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1', 'renderer' => 'keepsake'],
            'engraving_height_mm' => ['label' => __('雕刻安全区高度（mm）', 'tikool-frame-customizer'), 'type' => 'number', 'step' => '0.1', 'min' => '1', 'renderer' => 'keepsake'],
            'wood_color' => ['label' => __('木材预览颜色', 'tikool-frame-customizer'), 'type' => 'color', 'renderer' => 'keepsake'],
            'wood_texture_url' => ['label' => __('木材纹理图片 URL', 'tikool-frame-customizer'), 'type' => 'url', 'renderer' => 'keepsake', 'description' => __('可选：用于收藏盒预览的无缝木纹图片。', 'tikool-frame-customizer')],
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

        $template_config = (array) ($templates[$template] ?? []);

        return [
            'template' => $template,
            'template_label' => (string) ($templates[$template]['label'] ?? $template),
            'renderer' => sanitize_key((string) ($templates[$template]['renderer'] ?? 'frame')) ?: 'frame',
            'stand' => ! empty($templates[$template]['stand']),
            'supports_ai' => ! array_key_exists('supports_ai', $template_config) || ! empty($template_config['supports_ai']),
            'supports_name_overlay' => ! array_key_exists('supports_name_overlay', $template_config) || ! empty($template_config['supports_name_overlay']),
            'requires_engraving' => ! empty($template_config['requires_engraving']),
            'name_max_length' => max(1, (int) ($template_config['name_max_length'] ?? 100)),
            'name_label' => sanitize_text_field((string) get_post_meta($product_id, self::META_NAME_LABEL, true)) ?: __('Pet\'s name', 'tikool-frame-customizer'),
            'name_required' => true,
            'photo_label' => sanitize_text_field((string) get_post_meta($product_id, self::META_PHOTO_LABEL, true)) ?: __('Pet photo', 'tikool-frame-customizer'),
            'attribute_map' => [
                'size' => self::attribute_setting($product_id, self::META_SIZE_ATTRIBUTE, 'size'),
                'frame' => self::attribute_setting($product_id, self::META_FRAME_ATTRIBUTE, 'frame-color'),
                'mat' => self::attribute_setting($product_id, self::META_MAT_ATTRIBUTE, 'mat-style'),
                'orientation' => self::attribute_setting($product_id, self::META_ORIENTATION_ATTRIBUTE, 'orientation'),
                'wood' => self::attribute_setting($product_id, self::META_WOOD_ATTRIBUTE, 'wood'),
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

        $renderer = sanitize_key((string) ($settings['renderer'] ?? 'frame')) ?: 'frame';
        if ($renderer === 'blanket' && ! self::has_explicit_dimensions($product_id, $variation_id)) {
            $geometry = self::blanket_geometry(
                (string) ($role_values['size'] ?? ''),
                (string) ($role_values['orientation'] ?? ''),
                $geometry
            );
        }
        if ($renderer === 'keepsake' && ! self::has_explicit_dimensions($product_id, $variation_id)) {
            $geometry = self::keepsake_geometry(
                (string) ($role_values['size'] ?? ''),
                (string) ($role_values['wood'] ?? ''),
                $geometry
            );
        }

        $has_mat = $renderer === 'frame' && self::has_mat($role_values['mat'] ?? '', $geometry);
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
            'renderer' => $renderer,
            'stand' => (bool) $settings['stand'],
            'supportsAI' => (bool) ($settings['supports_ai'] ?? true),
            'supportsNameOverlay' => (bool) ($settings['supports_name_overlay'] ?? true),
            'requiresEngraving' => (bool) ($settings['requires_engraving'] ?? false),
            'nameMaxLength' => (int) ($settings['name_max_length'] ?? 100),
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
     * Resolve the two supported blanket sizes from the mapped WooCommerce size attribute.
     * Explicit product or variation dimensions always take precedence.
     *
     * @param array<string, float|string> $base
     * @return array<string, float|string>
     */
    private static function blanket_geometry(string $size, string $orientation, array $base): array
    {
        $matches = [];
        preg_match('/(50|60)\s*(?:x|×|by)\s*(60|80)/iu', $size, $matches);
        $width_inches = isset($matches[1]) ? (float) $matches[1] : 50.0;
        $height_inches = isset($matches[2]) ? (float) $matches[2] : 60.0;
        $pair = sprintf('%gx%g', $width_inches, $height_inches);
        if (! in_array($pair, ['50x60', '60x80'], true)) {
            $width_inches = 50.0;
            $height_inches = 60.0;
        }
        $orientation = strtolower($orientation);
        if (str_contains($orientation, 'landscape') || str_contains($orientation, 'horizontal') || str_contains($orientation, '横')) {
            [$width_inches, $height_inches] = [$height_inches, $width_inches];
        }
        $width = $width_inches * 25.4;
        $height = $height_inches * 25.4;
        return array_merge($base, [
            'outer_width_mm' => $width,
            'outer_height_mm' => $height,
            'inner_width_mm' => $width,
            'inner_height_mm' => $height,
            'artwork_width_mm' => $width,
            'artwork_height_mm' => $height,
            'print_width_mm' => $width,
            'print_height_mm' => $height,
            'mat_top_mm' => 0.0,
            'mat_right_mm' => 0.0,
            'mat_bottom_mm' => 0.0,
            'mat_left_mm' => 0.0,
            'mat_thickness_mm' => 0.0,
            'min_dpi' => max(100.0, (float) ($base['min_dpi'] ?? 100.0)),
        ]);
    }

    /**
     * Resolve the supplier's two keepsake-box profiles and preview wood tone.
     * Explicit product or variation dimensions always take precedence.
     *
     * @param array<string, float|string> $base
     * @return array<string, float|string>
     */
    private static function keepsake_geometry(string $size, string $wood, array $base): array
    {
        $size_key = strtolower($size);
        $large = str_contains($size_key, 'large')
            || str_contains($size_key, '大')
            || preg_match('/(^|\D)6(?:\.0)?\s*(?:cm|厘米|公分)?/iu', $size_key) === 1;
        $box_size = $large ? 60.0 : 50.0;
        $photo_size = $large ? 50.0 : 40.0;
        $box_height = $large ? 40.0 : 35.0;
        $vial_count = $large ? 3.0 : 2.0;
        $wood_key = strtolower($wood);
        $is_beech = str_contains($wood_key, 'beech') || str_contains($wood_key, '榉') || str_contains($wood_key, '櫸');

        return array_merge($base, [
            'outer_width_mm' => $box_size,
            'outer_height_mm' => $box_size,
            'inner_width_mm' => $photo_size,
            'inner_height_mm' => $photo_size,
            'artwork_width_mm' => $photo_size,
            'artwork_height_mm' => $photo_size,
            'print_width_mm' => $photo_size,
            'print_height_mm' => $photo_size,
            'box_height_mm' => $box_height,
            'vial_count' => $vial_count,
            'engraving_width_mm' => $large ? 44.0 : 36.0,
            'engraving_height_mm' => $large ? 44.0 : 36.0,
            'wood_color' => $is_beech ? '#c9975b' : '#6b3f25',
            'frame_color' => $is_beech ? '#c9975b' : '#6b3f25',
            'mat_color' => $is_beech ? '#ead8bd' : '#b98758',
            'min_dpi' => max(300.0, (float) ($base['min_dpi'] ?? 300.0)),
        ]);
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

        if ($variation && ($settings['renderer'] ?? 'frame') === 'frame' && ! self::has_explicit_dimensions($product_id, $variation->get_id())) {
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

        $mat_overlap = 5.0;
        $size_key = sprintf('%gx%g', (float) $matches[1], (float) $matches[2]);
        $supplier_profiles = [
            // Supplier dimensions are the real photo sizes, not theoretical inch conversions.
            // This product's real mat opening overlaps the printed photo by 5 mm on every edge.
            '8x10' => [
                'outer' => [223.0, 273.0],
                'inner' => [198.0, 248.0],
                'no_mat_print' => [203.0, 253.0],
                'mat_print' => [152.0, 203.0],
            ],
            '12x16' => [
                'outer' => [323.0, 425.0],
                'inner' => [298.0, 400.0],
                'no_mat_print' => [303.0, 405.0],
                'mat_print' => [240.0, 340.0],
            ],
            '16x20' => [
                'outer' => [424.0, 526.0],
                'inner' => [399.0, 501.0],
                'no_mat_print' => [404.0, 506.0],
                'mat_print' => [330.0, 430.0],
            ],
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
        $no_mat_print = $profile['no_mat_print'] ?? [$inner_width, $inner_height];
        $print_width = $has_mat ? (float) $mat_print[0] : (float) $no_mat_print[0];
        $print_height = $has_mat ? (float) $mat_print[1] : (float) $no_mat_print[1];
        $artwork_width = $has_mat ? max(1.0, $print_width - (2 * $mat_overlap)) : $inner_width;
        $artwork_height = $has_mat ? max(1.0, $print_height - (2 * $mat_overlap)) : $inner_height;
        $horizontal_mat = max(0.0, ($inner_width - $artwork_width) / 2);
        $vertical_mat = max(0.0, ($inner_height - $artwork_height) / 2);
        $outer_width = $profile ? (float) $profile['outer'][0] : $inner_width + 25.4;
        $outer_height = $profile ? (float) $profile['outer'][1] : $inner_height + 25.4;
        $frame_color = self::frame_color_from_label($frame, (string) ($base['frame_color'] ?? '#c89552'));

        return [
            'outer_width_mm' => $outer_width,
            'outer_height_mm' => $outer_height,
            'inner_width_mm' => $inner_width,
            'inner_height_mm' => $inner_height,
            'artwork_width_mm' => $artwork_width,
            'artwork_height_mm' => $artwork_height,
            'print_width_mm' => $print_width,
            'print_height_mm' => $print_height,
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
        $geometry['mat_thickness_mm'] = max(0.0, (float) ($geometry['mat_thickness_mm'] ?? 1));
        $geometry['box_height_mm'] = max(1.0, (float) ($geometry['box_height_mm'] ?? 35));
        $geometry['vial_count'] = max(1.0, round((float) ($geometry['vial_count'] ?? 2)));
        $geometry['engraving_width_mm'] = min((float) $geometry['outer_width_mm'], max(1.0, (float) ($geometry['engraving_width_mm'] ?? 36)));
        $geometry['engraving_height_mm'] = min((float) $geometry['outer_height_mm'], max(1.0, (float) ($geometry['engraving_height_mm'] ?? 36)));

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
            $fallback = $field === 'mat_color' ? '#f7f3eb' : ($field === 'blanket_color' ? '#eee7df' : ($field === 'wood_color' ? '#6b3f25' : '#c89552'));
            return sanitize_hex_color((string) $value) ?: $fallback;
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
