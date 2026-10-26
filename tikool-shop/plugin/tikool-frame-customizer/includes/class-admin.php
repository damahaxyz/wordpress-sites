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
        $style = TIKOOL_FC_DIR . 'assets/css/admin.css';
        wp_enqueue_style(
            'tikool-frame-customizer-admin',
            TIKOOL_FC_URL . 'assets/css/admin.css',
            [],
            is_file($style) ? (string) filemtime($style) : TIKOOL_FC_VERSION
        );
    }

    public function render_product_settings(): void
    {
        global $post;
        if (! $post instanceof \WP_Post) {
            return;
        }

        echo '<div class="options_group">';
        echo '<p class="form-field"><strong>' . esc_html__('Tikool 商品定制', 'tikool-frame-customizer') . '</strong><br><span class="description">' . esc_html__('请选择相框、毛毯或收藏盒定制模板。商品默认参数会应用到全部 SKU，除非在具体 SKU 中单独覆盖。', 'tikool-frame-customizer') . '</span></p>';

        woocommerce_wp_checkbox([
            'id' => Config::META_ENABLED,
            'label' => __('启用商品定制', 'tikool-frame-customizer'),
            'description' => __('启用两步式定制流程、实时预览、图片裁剪以及订单图片的私密保存。', 'tikool-frame-customizer'),
            'value' => Config::is_enabled($post->ID) ? 'yes' : 'no',
        ]);

        $template_options = [];
        foreach (Config::templates() as $slug => $template) {
            $template_options[$slug] = (string) ($template['label'] ?? $slug);
        }
        woocommerce_wp_select([
            'id' => Config::META_TEMPLATE,
            'label' => __('定制模板', 'tikool-frame-customizer'),
            'options' => $template_options,
            'description' => __('选择该商品使用的产品预览器。毛毯和收藏盒模板会根据映射的商品属性自动套用生产尺寸。', 'tikool-frame-customizer'),
            'desc_tip' => true,
        ]);

        woocommerce_wp_text_input([
            'id' => Config::META_NAME_LABEL,
            'label' => __('顾客姓名字段标题', 'tikool-frame-customizer'),
            'placeholder' => __('Pet\'s name', 'tikool-frame-customizer'),
        ]);
        woocommerce_wp_text_input([
            'id' => Config::META_PHOTO_LABEL,
            'label' => __('照片字段标题', 'tikool-frame-customizer'),
            'placeholder' => __('Pet photo', 'tikool-frame-customizer'),
        ]);

        echo '<p class="form-field"><strong>' . esc_html__('商品属性映射', 'tikool-frame-customizer') . '</strong><br><span class="description">' . esc_html__('请输入不带 pa_ 前缀的属性别名。通过映射，同一个编辑器可用于属性名称不同的商品。', 'tikool-frame-customizer') . '</span></p>';
        $attribute_fields = [
            Config::META_SIZE_ATTRIBUTE => [__('尺寸属性', 'tikool-frame-customizer'), 'size'],
            Config::META_FRAME_ATTRIBUTE => [__('相框属性', 'tikool-frame-customizer'), 'frame-color', 'frame'],
            Config::META_MAT_ATTRIBUTE => [__('卡纸属性', 'tikool-frame-customizer'), 'mat-style', 'frame'],
            Config::META_ORIENTATION_ATTRIBUTE => [__('方向属性', 'tikool-frame-customizer'), 'orientation'],
            Config::META_WOOD_ATTRIBUTE => [__('木材属性', 'tikool-frame-customizer'), 'wood', 'keepsake'],
        ];
        foreach ($attribute_fields as $id => $field_config) {
            [$label, $placeholder] = $field_config;
            woocommerce_wp_text_input([
                'id' => $id,
                'label' => $label,
                'placeholder' => $placeholder,
                'wrapper_class' => isset($field_config[2]) ? 'tikool-fc-renderer-' . $field_config[2] : '',
            ]);
        }

        echo '</div>';
        $this->render_portrait_style_settings($post->ID);
        echo '<div class="options_group">';
        echo '<p class="form-field"><strong>' . esc_html__('默认生产尺寸参数', 'tikool-frame-customizer') . '</strong><br><span class="description">' . esc_html__('物理尺寸统一使用毫米。毛毯的 50 × 60 和 60 × 80 英寸会按尺寸属性自动换算；填写数值后可覆盖自动结果。', 'tikool-frame-customizer') . '</span></p>';
        foreach (Config::geometry_fields() as $field => $definition) {
            $id = Config::PRODUCT_FIELD_PREFIX . $field;
            $args = [
                'id' => $id,
                'label' => $definition['label'],
                'type' => $definition['type'] === 'url' ? 'url' : ($definition['type'] === 'color' ? 'color' : 'number'),
                'value' => metadata_exists('post', $post->ID, $id) ? (string) get_post_meta($post->ID, $id, true) : '',
                'wrapper_class' => isset($definition['renderer']) ? 'tikool-fc-renderer-' . $definition['renderer'] : '',
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
                echo '<p class="form-field tikool-fc-media-row"><label></label><button type="button" class="button tikool-fc-choose-media" data-target="' . esc_attr($id) . '">' . esc_html__('选择图片', 'tikool-frame-customizer') . '</button></p>';
            }
        }
        echo '</div>';
    }

    public function save_product_settings(int $product_id): void
    {
        update_post_meta($product_id, Config::META_ENABLED, isset($_POST[Config::META_ENABLED]) ? 'yes' : 'no');
        update_post_meta($product_id, Config::META_NAME_REQUIRED, 'yes');

        $templates = Config::templates();
        $previous_template = sanitize_key((string) get_post_meta($product_id, Config::META_TEMPLATE, true)) ?: 'wall-frame';
        $template = sanitize_key(wp_unslash((string) ($_POST[Config::META_TEMPLATE] ?? 'wall-frame')));
        $template = isset($templates[$template]) ? $template : 'wall-frame';
        update_post_meta($product_id, Config::META_TEMPLATE, $template);

        $text_fields = [Config::META_NAME_LABEL, Config::META_PHOTO_LABEL];
        foreach ($text_fields as $field) {
            $value = sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? '')));
            self::save_or_delete($product_id, $field, $value);
        }

        $attribute_fields = [Config::META_SIZE_ATTRIBUTE, Config::META_FRAME_ATTRIBUTE, Config::META_MAT_ATTRIBUTE, Config::META_ORIENTATION_ATTRIBUTE, Config::META_WOOD_ATTRIBUTE];
        foreach ($attribute_fields as $field) {
            $value = sanitize_key(wp_unslash((string) ($_POST[$field] ?? '')));
            self::save_or_delete($product_id, $field, $value);
        }

        $submitted_styles = $_POST['tikool_fc_portrait_styles'] ?? null;
        if (is_array($submitted_styles)) {
            $styles = AI_Styles::sanitize_styles(wp_unslash($submitted_styles));
            $previous_renderer = (string) ($templates[$previous_template]['renderer'] ?? 'frame');
            $next_renderer = (string) ($templates[$template]['renderer'] ?? 'frame');
            $previous_defaults = $previous_renderer === 'blanket' ? AI_Styles::blanket_defaults() : AI_Styles::defaults();
            if ($previous_renderer !== $next_renderer && $styles === AI_Styles::sanitize_styles($previous_defaults)) {
                $styles = $next_renderer === 'blanket' ? AI_Styles::blanket_defaults() : AI_Styles::defaults();
            }
            update_post_meta(
                $product_id,
                AI_Styles::META_STYLES,
                $styles
            );
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
        echo '<div class="form-row form-row-full tikool-fc-variation-settings"><details><summary><strong>' . esc_html__('Tikool 定制生产参数', 'tikool-frame-customizer') . '</strong> — ' . esc_html__('留空字段将继承商品或模板的参数', 'tikool-frame-customizer') . '</summary><div style="padding:12px 0">';

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
            $renderer_class = isset($definition['renderer']) ? ' tikool-fc-renderer-' . $definition['renderer'] : '';
            echo '<p class="form-row form-row-' . (in_array($field, ['frame_texture_url', 'blanket_texture_url', 'wood_texture_url', 'overlay_url'], true) ? 'full' : ($loop % 2 === 0 ? 'first' : 'last')) . $renderer_class . '">';
            echo '<label for="' . esc_attr($input_id) . '">' . esc_html($definition['label']) . '</label>';
            echo '<input id="' . esc_attr($input_id) . '" class="' . esc_attr($class) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $attributes . '>';
            if ($definition['type'] === 'url') {
                echo ' <button type="button" class="button tikool-fc-choose-media" data-target="' . esc_attr($input_id) . '">' . esc_html__('选择图片', 'tikool-frame-customizer') . '</button>';
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

    private function render_portrait_style_settings(int $product_id): void
    {
        $styles = AI_Styles::styles($product_id);
        $feature_options = $this->ai_feature_options();
        $renderer = (string) (Config::product_settings($product_id)['renderer'] ?? 'frame');
        $is_blanket = $renderer === 'blanket';
        $is_keepsake = $renderer === 'keepsake';
        echo '<div class="options_group tikool-fc-style-settings tikool-fc-renderer-ai"' . ($is_keepsake ? ' hidden' : '') . '>';
        echo '<div class="tikool-fc-style-settings__heading">';
        echo '<div><strong>' . esc_html($is_blanket ? __('商品画面风格', 'tikool-frame-customizer') : __('商品肖像风格', 'tikool-frame-customizer')) . '</strong><p class="description">' . esc_html__('这些风格仅用于当前商品。AI 功能来自 Tikool AI 的提示词配置；选择“不调用 AI”时会直接使用顾客原图。Original 固定保留并始终不调用 AI。', 'tikool-frame-customizer') . '</p></div>';
        echo '<button type="button" class="button" data-tikool-fc-add-style>' . esc_html__('添加风格', 'tikool-frame-customizer') . '</button>';
        echo '</div>';
        echo '<div class="tikool-fc-style-settings__list" data-tikool-fc-style-list>';
        $index = 0;
        foreach ($styles as $key => $style) {
            $this->render_portrait_style_row((string) $index++, (string) $key, $style, $feature_options);
        }
        echo '</div>';
        echo '<template data-tikool-fc-style-template>';
        $this->render_portrait_style_row('__INDEX__', '', [
            'label' => '',
            'short_label' => '',
            'description' => '',
            'ai_feature' => '',
        ], $feature_options);
        echo '</template>';
        if (count($feature_options) === 1) {
            echo '<p class="notice notice-warning inline"><span>' . esc_html__('Tikool AI 当前没有可选提示词功能。请先在“设置 → Tikool AI”中添加或启用提示词。', 'tikool-frame-customizer') . '</span></p>';
        }
        echo '</div>';
    }

    /**
     * @param array<string, string> $style
     * @param array<string, string> $feature_options
     */
    private function render_portrait_style_row(string $index, string $key, array $style, array $feature_options): void
    {
        $is_original = $key === 'original';
        $base = 'tikool_fc_portrait_styles[' . $index . ']';
        $selected_feature = sanitize_key((string) ($style['ai_feature'] ?? ''));
        if ($selected_feature !== '' && ! isset($feature_options[$selected_feature])) {
            $feature_options[$selected_feature] = sprintf(__('当前配置：%s（Tikool AI 中不可用）', 'tikool-frame-customizer'), $selected_feature);
        }
        ?>
        <article class="tikool-fc-style-admin-row<?php echo $is_original ? ' is-original' : ''; ?>" data-tikool-fc-style-row data-original="<?php echo $is_original ? '1' : '0'; ?>">
            <div class="tikool-fc-style-admin-row__toolbar">
                <strong data-tikool-fc-style-title><?php echo esc_html($is_original ? __('Original', 'tikool-frame-customizer') : ((string) ($style['short_label'] ?? '') ?: __('新风格', 'tikool-frame-customizer'))); ?></strong>
                <span class="tikool-fc-style-admin-row__actions">
                    <?php if ($is_original) : ?>
                        <span class="tikool-fc-style-admin-row__fixed"><?php esc_html_e('固定', 'tikool-frame-customizer'); ?></span>
                    <?php else : ?>
                        <button type="button" class="button-link" data-tikool-fc-style-up aria-label="<?php esc_attr_e('上移风格', 'tikool-frame-customizer'); ?>">↑</button>
                        <button type="button" class="button-link" data-tikool-fc-style-down aria-label="<?php esc_attr_e('下移风格', 'tikool-frame-customizer'); ?>">↓</button>
                        <button type="button" class="button-link-delete" data-tikool-fc-remove-style><?php esc_html_e('删除', 'tikool-frame-customizer'); ?></button>
                    <?php endif; ?>
                </span>
            </div>
            <div class="tikool-fc-style-admin-row__fields">
                <label>
                    <span><?php esc_html_e('风格标识', 'tikool-frame-customizer'); ?></span>
                    <input type="text" required pattern="[a-z0-9_-]+" name="<?php echo esc_attr($base); ?>[key]" value="<?php echo esc_attr($key); ?>" <?php echo $is_original ? 'readonly="readonly"' : ''; ?> data-tikool-fc-style-key placeholder="watercolor">
                </label>
                <label>
                    <span><?php esc_html_e('完整名称', 'tikool-frame-customizer'); ?></span>
                    <input type="text" required name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr((string) ($style['label'] ?? '')); ?>" data-tikool-fc-style-label placeholder="Watercolor Pet Portrait">
                </label>
                <label>
                    <span><?php esc_html_e('短名称', 'tikool-frame-customizer'); ?></span>
                    <input type="text" name="<?php echo esc_attr($base); ?>[short_label]" value="<?php echo esc_attr((string) ($style['short_label'] ?? '')); ?>" data-tikool-fc-style-short-label placeholder="Watercolor">
                </label>
                <label>
                    <span><?php esc_html_e('AI 功能', 'tikool-frame-customizer'); ?></span>
                    <select name="<?php echo esc_attr($base); ?>[ai_feature]" <?php disabled($is_original); ?> <?php echo $is_original ? '' : 'required="required"'; ?>>
                        <?php foreach ($feature_options as $feature => $label) : ?>
                            <option value="<?php echo esc_attr($feature); ?>" <?php selected($selected_feature, $feature); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($is_original) : ?><input type="hidden" name="<?php echo esc_attr($base); ?>[ai_feature]" value=""><?php endif; ?>
                </label>
                <label class="tikool-fc-style-admin-row__description">
                    <span><?php esc_html_e('前台说明', 'tikool-frame-customizer'); ?></span>
                    <input type="text" name="<?php echo esc_attr($base); ?>[description]" value="<?php echo esc_attr((string) ($style['description'] ?? '')); ?>" placeholder="<?php esc_attr_e('简短描述该风格在前台的视觉效果', 'tikool-frame-customizer'); ?>">
                </label>
            </div>
        </article>
        <?php
    }

    /**
     * @return array<string, string>
     */
    private function ai_feature_options(): array
    {
        $options = ['' => __('不调用 AI（仅 Original）', 'tikool-frame-customizer')];
        if (! class_exists('\Tikool\AI\Prompts')) {
            return $options;
        }
        foreach (\Tikool\AI\Prompts::profiles() as $key => $profile) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            $label = sanitize_text_field((string) ($profile['label'] ?? $key));
            if (empty($profile['enabled'])) {
                $label .= ' ' . __('（已停用）', 'tikool-frame-customizer');
            }
            $options[$key] = $label . ' — ' . $key;
        }

        return $options;
    }
}
