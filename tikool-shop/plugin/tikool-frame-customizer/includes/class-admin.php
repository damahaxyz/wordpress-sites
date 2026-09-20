<?php
/**
 * WooCommerce product and variation configuration UI.
 */

declare(strict_types=1);

namespace Tikool\FrameCustomizer;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_settings']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_settings']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_settings'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_settings'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_media();
        $script = TIKOOL_FC_DIR . 'assets/js/admin.js';
        wp_enqueue_script(
            'tikool-frame-customizer-admin',
            TIKOOL_FC_URL . 'assets/js/admin.js',
            ['jquery'],
            is_file($script) ? (string) filemtime($script) : TIKOOL_FC_VERSION,
            true
        );
    }

    public function render_product_settings(): void
    {
        global $post;
        if (! $post instanceof \WP_Post) {
            return;
        }

        echo '<div class="options_group">';
        echo '<p class="form-field"><strong>' . esc_html__('Tikool Frame Customizer', 'tikool-frame-customizer') . '</strong><br><span class="description">' . esc_html__('Choose a reusable customizer template. Product defaults apply to every SKU unless a variation overrides them.', 'tikool-frame-customizer') . '</span></p>';

        woocommerce_wp_checkbox([
            'id' => Config::META_ENABLED,
            'label' => __('Enable frame customizer', 'tikool-frame-customizer'),
            'description' => __('Adds the two-step upload, live preview, crop editor, and private order artwork.', 'tikool-frame-customizer'),
            'value' => Config::is_enabled($post->ID) ? 'yes' : 'no',
        ]);

        $template_options = [];
        foreach (Config::templates() as $slug => $template) {
            $template_options[$slug] = (string) ($template['label'] ?? $slug);
        }
        woocommerce_wp_select([
            'id' => Config::META_TEMPLATE,
            'label' => __('Customizer template', 'tikool-frame-customizer'),
            'options' => $template_options,
            'description' => __('Select the product-specific editor layout. The WooCommerce product page remains the canonical detail page.', 'tikool-frame-customizer'),
            'desc_tip' => true,
        ]);

        woocommerce_wp_text_input([
            'id' => Config::META_NAME_LABEL,
            'label' => __('Customer name-field label', 'tikool-frame-customizer'),
            'placeholder' => __('Pet\'s name', 'tikool-frame-customizer'),
        ]);
        woocommerce_wp_checkbox([
            'id' => Config::META_NAME_REQUIRED,
            'label' => __('Require the name field', 'tikool-frame-customizer'),
            'value' => metadata_exists('post', $post->ID, Config::META_NAME_REQUIRED)
                ? (string) get_post_meta($post->ID, Config::META_NAME_REQUIRED, true)
                : 'yes',
        ]);
        woocommerce_wp_text_input([
            'id' => Config::META_PHOTO_LABEL,
            'label' => __('Photo-field label', 'tikool-frame-customizer'),
            'placeholder' => __('Pet photo', 'tikool-frame-customizer'),
        ]);

        echo '<p class="form-field"><strong>' . esc_html__('Attribute mapping', 'tikool-frame-customizer') . '</strong><br><span class="description">' . esc_html__('Enter attribute slugs without the pa_ prefix. These mappings let the same editor work with different product attribute names.', 'tikool-frame-customizer') . '</span></p>';
        $attribute_fields = [
            Config::META_SIZE_ATTRIBUTE => [__('Size attribute', 'tikool-frame-customizer'), 'size'],
            Config::META_FRAME_ATTRIBUTE => [__('Frame attribute', 'tikool-frame-customizer'), 'frame-color'],
            Config::META_MAT_ATTRIBUTE => [__('Mat attribute', 'tikool-frame-customizer'), 'mat-style'],
            Config::META_ORIENTATION_ATTRIBUTE => [__('Orientation attribute', 'tikool-frame-customizer'), 'orientation'],
        ];
        foreach ($attribute_fields as $id => [$label, $placeholder]) {
            woocommerce_wp_text_input([
                'id' => $id,
                'label' => $label,
                'placeholder' => $placeholder,
            ]);
        }

        echo '</div><div class="options_group">';
        echo '<p class="form-field"><strong>' . esc_html__('Default production geometry', 'tikool-frame-customizer') . '</strong><br><span class="description">' . esc_html__('Physical values are millimetres. Leave a field blank to use the selected template default; each SKU can override it.', 'tikool-frame-customizer') . '</span></p>';
        foreach (Config::geometry_fields() as $field => $definition) {
            $id = Config::PRODUCT_FIELD_PREFIX . $field;
            $args = [
                'id' => $id,
                'label' => $definition['label'],
                'type' => $definition['type'] === 'url' ? 'url' : ($definition['type'] === 'color' ? 'color' : 'number'),
                'value' => metadata_exists('post', $post->ID, $id) ? (string) get_post_meta($post->ID, $id, true) : '',
            ];
            foreach (['step', 'min', 'description'] as $key) {
                if (isset($definition[$key])) {
                    $args[$key] = $definition[$key];
                }
            }
            if ($definition['type'] === 'url') {
                $args['class'] = 'short tikool-fc-media-url';
            }
            woocommerce_wp_text_input($args);
            if ($definition['type'] === 'url') {
                echo '<p class="form-field tikool-fc-media-row"><label></label><button type="button" class="button tikool-fc-choose-media" data-target="' . esc_attr($id) . '">' . esc_html__('Choose image', 'tikool-frame-customizer') . '</button></p>';
            }
        }
        echo '</div>';
    }

    public function save_product_settings(int $product_id): void
    {
        update_post_meta($product_id, Config::META_ENABLED, isset($_POST[Config::META_ENABLED]) ? 'yes' : 'no');
        update_post_meta($product_id, Config::META_NAME_REQUIRED, isset($_POST[Config::META_NAME_REQUIRED]) ? 'yes' : 'no');

        $templates = Config::templates();
        $template = sanitize_key(wp_unslash((string) ($_POST[Config::META_TEMPLATE] ?? 'wall-frame')));
        update_post_meta($product_id, Config::META_TEMPLATE, isset($templates[$template]) ? $template : 'wall-frame');

        $text_fields = [Config::META_NAME_LABEL, Config::META_PHOTO_LABEL];
        foreach ($text_fields as $field) {
            $value = sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? '')));
            self::save_or_delete($product_id, $field, $value);
        }

        $attribute_fields = [Config::META_SIZE_ATTRIBUTE, Config::META_FRAME_ATTRIBUTE, Config::META_MAT_ATTRIBUTE, Config::META_ORIENTATION_ATTRIBUTE];
        foreach ($attribute_fields as $field) {
            $value = sanitize_key(wp_unslash((string) ($_POST[$field] ?? '')));
            self::save_or_delete($product_id, $field, $value);
        }

        foreach (Config::geometry_fields() as $field => $definition) {
            $key = Config::PRODUCT_FIELD_PREFIX . $field;
            $raw = wp_unslash((string) ($_POST[$key] ?? ''));
            if ($raw === '') {
                delete_post_meta($product_id, $key);
                continue;
            }
            update_post_meta($product_id, $key, Config::sanitize_field_value($field, $raw, $definition['type']));
        }
    }

    /**
     * @param array<string, mixed> $variation_data
     */
    public function render_variation_settings(int $loop, array $variation_data, \WP_Post $variation): void
    {
        unset($variation_data);
        $variation_id = (int) $variation->ID;
        echo '<div class="form-row form-row-full tikool-fc-variation-settings"><details><summary><strong>' . esc_html__('Tikool frame production geometry', 'tikool-frame-customizer') . '</strong> — ' . esc_html__('blank fields inherit the product/template value', 'tikool-frame-customizer') . '</summary><div style="padding:12px 0">';

        foreach (Config::geometry_fields() as $field => $definition) {
            $key = Config::VARIATION_FIELD_PREFIX . $field;
            $input_id = $key . '_' . $variation_id;
            $name = 'tikool_fc_variation[' . $variation_id . '][' . $field . ']';
            $value = metadata_exists('post', $variation_id, $key) ? (string) get_post_meta($variation_id, $key, true) : '';
            $type = $definition['type'] === 'url' ? 'url' : ($definition['type'] === 'color' ? 'color' : 'number');
            $attributes = '';
            foreach (['step', 'min'] as $attribute) {
                if (isset($definition[$attribute])) {
                    $attributes .= ' ' . $attribute . '="' . esc_attr($definition[$attribute]) . '"';
                }
            }
            $class = $definition['type'] === 'url' ? 'tikool-fc-media-url' : '';
            echo '<p class="form-row form-row-' . (in_array($field, ['frame_texture_url', 'overlay_url'], true) ? 'full' : ($loop % 2 === 0 ? 'first' : 'last')) . '">';
            echo '<label for="' . esc_attr($input_id) . '">' . esc_html($definition['label']) . '</label>';
            echo '<input id="' . esc_attr($input_id) . '" class="' . esc_attr($class) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $attributes . '>';
            if ($definition['type'] === 'url') {
                echo ' <button type="button" class="button tikool-fc-choose-media" data-target="' . esc_attr($input_id) . '">' . esc_html__('Choose image', 'tikool-frame-customizer') . '</button>';
            }
            if (isset($definition['description'])) {
                echo '<span class="description">' . esc_html($definition['description']) . '</span>';
            }
            echo '</p>';
        }

        echo '<div class="clear"></div></div></details></div>';
    }

    public function save_variation_settings(int $variation_id, int $index): void
    {
        unset($index);
        $submitted = $_POST['tikool_fc_variation'][$variation_id] ?? null;
        if (! is_array($submitted)) {
            return;
        }

        foreach (Config::geometry_fields() as $field => $definition) {
            $key = Config::VARIATION_FIELD_PREFIX . $field;
            $raw = wp_unslash((string) ($submitted[$field] ?? ''));
            if ($raw === '') {
                delete_post_meta($variation_id, $key);
                continue;
            }
            update_post_meta($variation_id, $key, Config::sanitize_field_value($field, $raw, $definition['type']));
        }
    }

    private static function save_or_delete(int $post_id, string $key, string $value): void
    {
        if ($value === '') {
            delete_post_meta($post_id, $key);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }
}
