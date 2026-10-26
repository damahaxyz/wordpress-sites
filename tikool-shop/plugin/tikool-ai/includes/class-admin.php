<?php
/**
 * WordPress admin configuration.
 */

declare(strict_types=1);

namespace Tikool\AI;

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
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_page(): void
    {
        add_options_page(
            __('Tikool AI', 'tikool-ai'),
            __('Tikool AI', 'tikool-ai'),
            'manage_options',
            'tikool-ai',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(Settings::OPTION_GROUP, Settings::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'settings_page_tikool-ai') {
            return;
        }

        wp_enqueue_media();
        $script = TIKOOL_AI_DIR . 'assets/admin.js';
        $style = TIKOOL_AI_DIR . 'assets/admin.css';
        wp_enqueue_style(
            'tikool-ai-admin',
            TIKOOL_AI_URL . 'assets/admin.css',
            [],
            is_file($style) ? (string) filemtime($style) : TIKOOL_AI_VERSION
        );
        wp_enqueue_script(
            'tikool-ai-admin',
            TIKOOL_AI_URL . 'assets/admin.js',
            [],
            is_file($script) ? (string) filemtime($script) : TIKOOL_AI_VERSION,
            true
        );
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $old = get_option(Settings::OPTION_NAME, Settings::defaults());
        $old = is_array($old) ? $old : Settings::defaults();

        $endpoint = esc_url_raw((string) ($input['endpoint'] ?? ''), ['https']);
        if ($endpoint === '' || ! wp_http_validate_url($endpoint)) {
            add_settings_error(Settings::OPTION_NAME, 'invalid_endpoint', __('API 地址必须是有效的 HTTPS URL。', 'tikool-ai'));
            $endpoint = (string) ($old['endpoint'] ?? Settings::DEFAULT_ENDPOINT);
        }

        $new_api_key = trim(sanitize_text_field((string) ($input['api_key'] ?? '')));
        $api_key = $new_api_key !== '' ? $new_api_key : (string) ($old['api_key'] ?? '');
        if (! empty($input['clear_api_key'])) {
            $api_key = '';
        }

        $profiles = [];
        $raw_profiles = is_array($input['prompt_profiles'] ?? null) ? $input['prompt_profiles'] : [];
        foreach ($raw_profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $key = sanitize_key((string) ($profile['key'] ?? ''));
            $template = sanitize_textarea_field((string) ($profile['template'] ?? ''));
            if ($key === '' || $template === '') {
                continue;
            }
            $profiles[$key] = [
                'label' => sanitize_text_field((string) ($profile['label'] ?? $key)),
                'enabled' => ! empty($profile['enabled']),
                'preview_image_id' => $this->sanitize_preview_image_id($profile['preview_image_id'] ?? 0),
                'template' => $template,
                'variants' => $this->sanitize_variants_text((string) ($profile['variants_text'] ?? '')),
            ];
        }

        return [
            'endpoint' => $endpoint,
            'api_key' => $api_key,
            'model' => sanitize_text_field((string) ($input['model'] ?? Settings::DEFAULT_MODEL)),
            'size' => $this->allowed_value((string) ($input['size'] ?? '2K'), ['1K', '2K', '4K'], '2K'),
            'response_format' => 'url',
            'sequential_image_generation' => $this->allowed_value((string) ($input['sequential_image_generation'] ?? 'disabled'), ['disabled', 'auto'], 'disabled'),
            'watermark' => ! empty($input['watermark']),
            'timeout' => max(15, min(300, absint($input['timeout'] ?? 120))),
            'prompt_profiles' => $profiles,
        ];
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $tab = sanitize_key((string) ($_GET['tab'] ?? 'settings'));
        $tab = in_array($tab, ['settings', 'logs'], true) ? $tab : 'settings';
        ?>
        <div class="wrap tikool-ai-admin">
            <h1><?php esc_html_e('Tikool AI', 'tikool-ai'); ?></h1>
            <?php $this->render_tabs($tab); ?>
            <?php if ($tab === 'logs') : ?>
                <?php $this->render_logs(); ?>
            <?php else : ?>
                <?php $this->render_settings(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_settings(): void
    {
        $settings = Settings::all();
        $profiles = Prompts::profiles();
        ?>
        <p class="description"><?php esc_html_e('集中管理 AI 服务和各定制功能的提示词。API Key 仅供服务器端请求使用，不会发送到浏览器。', 'tikool-ai'); ?></p>

        <?php settings_errors(Settings::OPTION_NAME); ?>

        <form action="options.php" method="post">
            <?php settings_fields(Settings::OPTION_GROUP); ?>

                <section class="tikool-ai-card">
                    <h2><?php esc_html_e('火山方舟连接', 'tikool-ai'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="tikool-ai-endpoint"><?php esc_html_e('API 地址', 'tikool-ai'); ?></label></th>
                            <td><input id="tikool-ai-endpoint" class="regular-text code" type="url" required name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[endpoint]" value="<?php echo esc_attr((string) $settings['endpoint']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tikool-ai-key"><?php esc_html_e('API Key', 'tikool-ai'); ?></label></th>
                            <td>
                                <input id="tikool-ai-key" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[api_key]" value="" placeholder="<?php echo ! empty($settings['api_key']) ? esc_attr__('已保存；留空保持不变', 'tikool-ai') : esc_attr__('请输入 API Key', 'tikool-ai'); ?>">
                                <?php if (! empty($settings['api_key'])) : ?>
                                    <p><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('API Key 已保存。输入新 Key 可直接替换，留空则保持不变。', 'tikool-ai'); ?></p>
                                    <label class="tikool-ai-clear"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[clear_api_key]" value="1"> <?php esc_html_e('清除已保存的 Key', 'tikool-ai'); ?></label>
                                <?php else : ?>
                                    <p class="description"><?php esc_html_e('密钥保存到 WordPress 数据库，不会在页面中回显，也不会发送到浏览器端定制代码。', 'tikool-ai'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tikool-ai-model"><?php esc_html_e('模型', 'tikool-ai'); ?></label></th>
                            <td><input id="tikool-ai-model" class="regular-text code" type="text" required name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[model]" value="<?php echo esc_attr((string) $settings['model']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('默认输出', 'tikool-ai'); ?></th>
                            <td class="tikool-ai-inline-fields">
                                <label><?php esc_html_e('尺寸', 'tikool-ai'); ?>
                                    <select name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[size]">
                                        <?php foreach (['1K', '2K', '4K'] as $size) : ?>
                                            <option value="<?php echo esc_attr($size); ?>" <?php selected((string) $settings['size'], $size); ?>><?php echo esc_html($size); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[watermark]" value="1" <?php checked(! empty($settings['watermark'])); ?>> <?php esc_html_e('添加水印', 'tikool-ai'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tikool-ai-timeout"><?php esc_html_e('请求超时', 'tikool-ai'); ?></label></th>
                            <td><input id="tikool-ai-timeout" class="small-text" type="number" min="15" max="300" step="1" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[timeout]" value="<?php echo esc_attr((string) $settings['timeout']); ?>"> <?php esc_html_e('秒', 'tikool-ai'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tikool-ai-sequential"><?php esc_html_e('组图生成', 'tikool-ai'); ?></label></th>
                            <td>
                                <select id="tikool-ai-sequential" name="<?php echo esc_attr(Settings::OPTION_NAME); ?>[sequential_image_generation]">
                                    <option value="disabled" <?php selected((string) $settings['sequential_image_generation'], 'disabled'); ?>>disabled</option>
                                    <option value="auto" <?php selected((string) $settings['sequential_image_generation'], 'auto'); ?>>auto</option>
                                </select>
                                <p class="description"><?php esc_html_e('Seedream Flash 固定为单图调用，此参数不会发送；其他兼容模型可在此控制组图生成。', 'tikool-ai'); ?></p>
                            </td>
                        </tr>
                    </table>
                </section>

                <section class="tikool-ai-card">
                    <div class="tikool-ai-card__heading">
                        <div>
                            <h2><?php esc_html_e('功能提示词', 'tikool-ai'); ?></h2>
                            <p class="description"><?php esc_html_e('定制插件按功能标识调用。模板支持 {{user_prompt}}、{{style_prompt}}、{{product_name}}、{{background_prompt}}、{{orientation}}、{{aspect_ratio}} 和 {{print_dimensions}} 等变量。构图变量由所选 SKU 的实际印刷尺寸自动生成。配置生成变体后，调用方会为每个变体单独生成一张图片。', 'tikool-ai'); ?></p>
                        </div>
                        <button type="button" class="button" data-tikool-ai-add><?php esc_html_e('添加功能', 'tikool-ai'); ?></button>
                    </div>

                    <div data-tikool-ai-profiles>
                        <?php $index = 0; foreach ($profiles as $key => $profile) : ?>
                            <?php $this->render_profile((string) $index++, $key, $profile); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

            <?php submit_button(__('保存 AI 配置', 'tikool-ai')); ?>
        </form>

        <template data-tikool-ai-template>
            <?php $this->render_profile('__INDEX__', '', ['label' => '', 'enabled' => true, 'preview_image_id' => 0, 'template' => '', 'variants' => []]); ?>
        </template>
        <?php
    }

    private function render_tabs(string $current): void
    {
        $tabs = [
            'settings' => __('AI 配置', 'tikool-ai'),
            'logs' => __('生成记录', 'tikool-ai'),
        ];
        echo '<nav class="nav-tab-wrapper tikool-ai-tabs">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(['page' => 'tikool-ai', 'tab' => $key], admin_url('options-general.php'));
            $class = 'nav-tab' . ($current === $key ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function render_logs(): void
    {
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $status = in_array($status, ['requesting', 'success', 'failed', 'cached'], true) ? $status : '';
        $feature = sanitize_key((string) ($_GET['feature'] ?? ''));
        $page_number = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 20;
        $filters = ['status' => $status, 'feature' => $feature];
        $total = Generation_Log::count($filters);
        $rows = Generation_Log::query($filters + [
            'per_page' => $per_page,
            'offset' => ($page_number - 1) * $per_page,
        ]);
        $total_pages = max(1, (int) ceil($total / $per_page));
        ?>
        <p class="description"><?php esc_html_e('记录每次生成调用及缓存命中的状态、提示词、模型、耗时和结果。缓存记录仅保存哈希 Key 与任务元数据，不保存 API Key、上传图片、base64 或服务器文件路径。', 'tikool-ai'); ?></p>

        <form class="tikool-ai-log-filters" method="get">
            <input type="hidden" name="page" value="tikool-ai">
            <input type="hidden" name="tab" value="logs">
            <label>
                <span class="screen-reader-text"><?php esc_html_e('状态', 'tikool-ai'); ?></span>
                <select name="status">
                    <option value=""><?php esc_html_e('全部状态', 'tikool-ai'); ?></option>
                    <option value="requesting" <?php selected($status, 'requesting'); ?>><?php esc_html_e('请求中', 'tikool-ai'); ?></option>
                    <option value="success" <?php selected($status, 'success'); ?>><?php esc_html_e('成功', 'tikool-ai'); ?></option>
                    <option value="failed" <?php selected($status, 'failed'); ?>><?php esc_html_e('失败', 'tikool-ai'); ?></option>
                    <option value="cached" <?php selected($status, 'cached'); ?>><?php esc_html_e('缓存命中', 'tikool-ai'); ?></option>
                </select>
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('功能标识', 'tikool-ai'); ?></span>
                <input type="search" name="feature" value="<?php echo esc_attr($feature); ?>" placeholder="<?php esc_attr_e('功能标识', 'tikool-ai'); ?>">
            </label>
            <button class="button" type="submit"><?php esc_html_e('筛选', 'tikool-ai'); ?></button>
            <span class="tikool-ai-log-count"><?php echo esc_html(sprintf(__('共 %d 条记录', 'tikool-ai'), $total)); ?></span>
        </form>

        <div class="tikool-ai-log-table-wrap">
            <table class="widefat striped tikool-ai-log-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('时间／状态', 'tikool-ai'); ?></th>
                        <th><?php esc_html_e('功能／来源', 'tikool-ai'); ?></th>
                        <th><?php esc_html_e('模型／耗时', 'tikool-ai'); ?></th>
                        <th><?php esc_html_e('结果', 'tikool-ai'); ?></th>
                        <th><?php esc_html_e('详细记录', 'tikool-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []) : ?>
                        <tr><td colspan="5"><?php esc_html_e('还没有生成记录。用户首次生成图片后会自动显示在这里。', 'tikool-ai'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php $this->render_log_row($row); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1) : ?>
            <div class="tablenav"><div class="tablenav-pages">
                <?php
                $base_url = remove_query_arg('paged');
                echo wp_kses_post((string) paginate_links([
                    'base' => add_query_arg('paged', '%#%', $base_url),
                    'current' => min($page_number, $total_pages),
                    'total' => $total_pages,
                    'prev_text' => __('上一页', 'tikool-ai'),
                    'next_text' => __('下一页', 'tikool-ai'),
                ]));
                ?>
            </div></div>
        <?php endif; ?>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     */
    private function render_log_row(array $row): void
    {
        $status = (string) ($row['status'] ?? 'failed');
        $success = $status === 'success';
        $requesting = $status === 'requesting';
        $cached = $status === 'cached';
        $status_label = $requesting ? __('请求中', 'tikool-ai') : ($success ? __('成功', 'tikool-ai') : ($cached ? __('缓存命中', 'tikool-ai') : __('失败', 'tikool-ai')));
        $created = (string) ($row['created_at'] ?? '');
        $display_date = $created !== '' ? get_date_from_gmt($created, 'Y-m-d H:i:s') : '—';
        $image_url = esc_url((string) ($row['image_url'] ?? ''));
        $prompt = (string) ($row['prompt'] ?? '');
        $context = (string) ($row['context_json'] ?? '');
        $usage = (string) ($row['usage_json'] ?? '');
        ?>
        <tr>
            <td>
                <strong>#<?php echo esc_html((string) ($row['id'] ?? '')); ?></strong><br>
                <span class="tikool-ai-status tikool-ai-status--<?php echo esc_attr($requesting ? 'requesting' : ($success ? 'success' : ($cached ? 'cached' : 'failed'))); ?>"><?php echo esc_html($status_label); ?></span><br>
                <time><?php echo esc_html($display_date); ?></time>
            </td>
            <td>
                <code><?php echo esc_html((string) ($row['feature'] ?? '—')); ?></code><br>
                <span><?php echo esc_html((string) ($row['source'] ?? '—')); ?></span>
            </td>
            <td>
                <code><?php echo esc_html((string) ($row['model'] ?? '—')); ?></code><br>
                <?php echo esc_html((string) ($row['size'] ?? '—')); ?> · <?php echo esc_html((string) ($row['duration_ms'] ?? 0)); ?> ms<br>
                <?php if ($cached) : ?><?php esc_html_e('未发起 HTTP 请求', 'tikool-ai'); ?><?php else : ?>HTTP <?php echo esc_html((string) ($row['http_status'] ?? 0)); ?><?php endif; ?>
            </td>
            <td>
                <?php if ($requesting) : ?>
                    <strong><?php esc_html_e('等待供应商返回结果…', 'tikool-ai'); ?></strong>
                <?php elseif ($cached) : ?>
                    <strong><?php esc_html_e('复用已有生成结果，未调用供应商', 'tikool-ai'); ?></strong>
                <?php elseif ($success) : ?>
                    <?php if ($image_url !== '') : ?><a href="<?php echo esc_url($image_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('查看供应商图片', 'tikool-ai'); ?></a><?php endif; ?>
                    <?php if ((string) ($row['request_id'] ?? '') !== '') : ?><p><code><?php echo esc_html((string) $row['request_id']); ?></code></p><?php endif; ?>
                <?php else : ?>
                    <strong><?php echo esc_html((string) ($row['error_code'] ?? '')); ?></strong>
                    <p><?php echo esc_html((string) ($row['error_message'] ?? '')); ?></p>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($prompt !== '') : ?>
                    <details><summary><?php esc_html_e('提示词', 'tikool-ai'); ?></summary><pre><?php echo esc_html($prompt); ?></pre></details>
                <?php endif; ?>
                <?php if ($context !== '') : ?>
                    <details><summary><?php esc_html_e('调用上下文', 'tikool-ai'); ?></summary><pre><?php echo esc_html($context); ?></pre></details>
                <?php endif; ?>
                <?php if ($usage !== '') : ?>
                    <details><summary><?php esc_html_e('用量', 'tikool-ai'); ?></summary><pre><?php echo esc_html($usage); ?></pre></details>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array{label: string, enabled: bool, preview_image_id?: int, template: string, variants?: array<string, array{label: string, prompt: string}>} $profile
     */
    private function render_profile(string $index, string $key, array $profile): void
    {
        $base = Settings::OPTION_NAME . '[prompt_profiles][' . $index . ']';
        $preview_image_id = absint($profile['preview_image_id'] ?? 0);
        $preview_image_url = $preview_image_id > 0 ? wp_get_attachment_image_url($preview_image_id, 'thumbnail') : false;
        ?>
        <article class="tikool-ai-profile" data-tikool-ai-profile>
            <div class="tikool-ai-profile__meta">
                <label>
                    <span><?php esc_html_e('功能标识', 'tikool-ai'); ?></span>
                    <input class="regular-text code" type="text" required pattern="[a-z0-9_-]+" name="<?php echo esc_attr($base); ?>[key]" value="<?php echo esc_attr($key); ?>" placeholder="pet_portrait_preview">
                </label>
                <label>
                    <span><?php esc_html_e('后台名称', 'tikool-ai'); ?></span>
                    <input class="regular-text" type="text" required name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr((string) $profile['label']); ?>" placeholder="<?php esc_attr_e('宠物肖像预览', 'tikool-ai'); ?>">
                </label>
                <label class="tikool-ai-enabled"><input type="checkbox" name="<?php echo esc_attr($base); ?>[enabled]" value="1" <?php checked(! empty($profile['enabled'])); ?>> <?php esc_html_e('启用', 'tikool-ai'); ?></label>
                <button type="button" class="button-link-delete" data-tikool-ai-remove><?php esc_html_e('删除', 'tikool-ai'); ?></button>
            </div>
            <div class="tikool-ai-profile__preview" data-tikool-ai-preview>
                <div class="tikool-ai-profile__preview-image" data-tikool-ai-preview-image<?php echo $preview_image_url ? '' : ' hidden'; ?>>
                    <?php if ($preview_image_url) : ?>
                        <img src="<?php echo esc_url($preview_image_url); ?>" alt="">
                    <?php endif; ?>
                </div>
                <div>
                    <strong><?php esc_html_e('前台风格示例图', 'tikool-ai'); ?></strong>
                    <p class="description"><?php esc_html_e('建议使用正方形图片。商品选择该 AI 功能后，会自动在风格选择卡片中显示。', 'tikool-ai'); ?></p>
                    <input type="hidden" name="<?php echo esc_attr($base); ?>[preview_image_id]" value="<?php echo esc_attr((string) $preview_image_id); ?>" data-tikool-ai-preview-id>
                    <button type="button" class="button" data-tikool-ai-preview-choose><?php echo esc_html($preview_image_id > 0 ? __('更换图片', 'tikool-ai') : __('选择图片', 'tikool-ai')); ?></button>
                    <button type="button" class="button-link-delete" data-tikool-ai-preview-clear<?php echo $preview_image_id > 0 ? '' : ' hidden'; ?>><?php esc_html_e('清除', 'tikool-ai'); ?></button>
                </div>
            </div>
            <label class="tikool-ai-prompt">
                <span><?php esc_html_e('提示词模板', 'tikool-ai'); ?></span>
                <textarea required rows="5" name="<?php echo esc_attr($base); ?>[template]" placeholder="<?php esc_attr_e('描述主体保真要求、风格、构图和背景，并插入调用方变量。', 'tikool-ai'); ?>"><?php echo esc_textarea((string) $profile['template']); ?></textarea>
            </label>
            <label class="tikool-ai-prompt">
                <span><?php esc_html_e('生成变体', 'tikool-ai'); ?></span>
                <textarea rows="5" name="<?php echo esc_attr($base); ?>[variants_text]" placeholder="warm_ivory|Warm Ivory|Use a low-saturation warm ivory watercolor wash background."><?php echo esc_textarea($this->variants_text((array) ($profile['variants'] ?? []))); ?></textarea>
                <small class="description"><?php esc_html_e('可选。每行一个变体，格式为：标识|前台名称|完整提示词。最多 8 个；模板中建议放置 {{background_prompt}}。', 'tikool-ai'); ?></small>
            </label>
        </article>
        <?php
    }

    /**
     * @param list<string> $allowed
     */
    private function allowed_value(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @param mixed $value
     */
    private function sanitize_preview_image_id($value): int
    {
        $attachment_id = absint($value);
        return $attachment_id > 0 && wp_attachment_is_image($attachment_id) ? $attachment_id : 0;
    }

    /** @return array<string, array{label: string, prompt: string}> */
    private function sanitize_variants_text(string $value): array
    {
        $variants = [];
        foreach (array_slice(preg_split('/\R/u', $value) ?: [], 0, 8) as $line) {
            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) !== 3) {
                continue;
            }
            $key = sanitize_key($parts[0]);
            $label = sanitize_text_field($parts[1]);
            $prompt = sanitize_textarea_field($parts[2]);
            if ($key === '' || $label === '' || $prompt === '' || isset($variants[$key])) {
                continue;
            }
            $variants[$key] = ['label' => $label, 'prompt' => $prompt];
        }
        return $variants;
    }

    /**
     * @param array<string, array{label: string, prompt: string}> $variants
     */
    private function variants_text(array $variants): string
    {
        $lines = [];
        foreach ($variants as $key => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $lines[] = sanitize_key((string) $key) . '|' . sanitize_text_field((string) ($variant['label'] ?? $key)) . '|' . sanitize_textarea_field((string) ($variant['prompt'] ?? ''));
        }
        return implode("\n", $lines);
    }
}
