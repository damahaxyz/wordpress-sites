<?php
/**
 * AI portrait-style generation and private temporary previews.
 */

declare(strict_types=1);

namespace Tikool\FrameCustomizer;

if (! defined('ABSPATH')) {
    exit;
}

final class AI_Styles
{
    public const META_STYLES = '_tikool_fc_portrait_styles';
    private const PRIVATE_DIRECTORY = 'tikool-customer-photos';
    private const MAX_UPLOAD_BYTES = 20 * MB_IN_BYTES;
    private const MAX_DOWNLOAD_BYTES = 20 * MB_IN_BYTES;
    private const AI_SOURCE_MAX_EDGE = 1600;
    private const AI_SOURCE_MAX_BYTES = 4 * MB_IN_BYTES;
    private const AI_SOURCE_JPEG_QUALITY = 82;
    private const PREVIEW_TTL = 2 * HOUR_IN_SECONDS;
    private const RATE_LIMIT = 8;
    private const RATE_WINDOW = HOUR_IN_SECONDS;
    private const JOB_STALE_AFTER = 7 * MINUTE_IN_SECONDS;
    private const JOB_POLL_AFTER = 3;
    private const LOCK_TTL = 4 * MINUTE_IN_SECONDS;
    private const LOCK_CACHE_GROUP = 'tikool_fc_ai_locks';

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

    /**
     * @return array<string, array{label: string, short_label: string, description: string, ai_feature: string, preview_image_id?: int}>
     */
    public static function defaults(): array
    {
        return [
            'original' => [
                'label' => __('Original Photo', 'tikool-frame-customizer'),
                'short_label' => __('Original', 'tikool-frame-customizer'),
                'description' => __('Keep your uploaded photo exactly as it is.', 'tikool-frame-customizer'),
                'ai_feature' => '',
            ],
            'minimalist' => [
                'label' => __('Minimalist Pet Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Minimalist', 'tikool-frame-customizer'),
                'description' => __('Clean neutral background with your pet in clear focus.', 'tikool-frame-customizer'),
                'ai_feature' => 'pet_portrait_minimalist',
            ],
            'watercolor' => [
                'label' => __('Watercolor Pet Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Watercolor', 'tikool-frame-customizer'),
                'description' => __('Soft color, subtle brush texture, and restrained ink detail.', 'tikool-frame-customizer'),
                'ai_feature' => 'pet_portrait_watercolor',
            ],
            'classic-oil' => [
                'label' => __('Classic Oil Painting Pet Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Classic Oil', 'tikool-frame-customizer'),
                'description' => __('Traditional layered brushwork with rich, timeless depth.', 'tikool-frame-customizer'),
                'ai_feature' => 'pet_portrait_oil',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, short_label: string, description: string, ai_feature: string, preview_image_id?: int}>
     */
    public static function blanket_defaults(): array
    {
        return [
            'original' => self::defaults()['original'],
            'minimalist' => [
                'label' => __('Warm Minimalist Blanket Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Warm Minimalist', 'tikool-frame-customizer'),
                'description' => __('Clean portrait with a warm cream and beige palette.', 'tikool-frame-customizer'),
                'ai_feature' => 'blanket_portrait_minimalist_cream',
            ],
            'watercolor' => [
                'label' => __('Pastel Watercolor Blanket Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Pastel Watercolor', 'tikool-frame-customizer'),
                'description' => __('Soft watercolor detail with a gentle pastel background.', 'tikool-frame-customizer'),
                'ai_feature' => 'blanket_portrait_watercolor_pastel',
            ],
            'modern-studio' => [
                'label' => __('Modern Studio Blanket Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Modern Studio', 'tikool-frame-customizer'),
                'description' => __('Clean studio lighting with a polished contemporary background.', 'tikool-frame-customizer'),
                'ai_feature' => 'blanket_portrait_modern_studio',
            ],
            'playful-pattern' => [
                'label' => __('Playful Pattern Blanket Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Playful Pattern', 'tikool-frame-customizer'),
                'description' => __('A cheerful repeating portrait pattern with coordinated accents.', 'tikool-frame-customizer'),
                'ai_feature' => 'blanket_portrait_playful_pattern',
            ],
            'memorial-garden' => [
                'label' => __('Memorial Garden Blanket Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Memorial Garden', 'tikool-frame-customizer'),
                'description' => __('A gentle garden portrait created for warm remembrance.', 'tikool-frame-customizer'),
                'ai_feature' => 'blanket_portrait_memorial_garden',
            ],
            'pop-art' => [
                'label' => __('Pop Art Blanket Portrait', 'tikool-frame-customizer'),
                'short_label' => __('Pop Art', 'tikool-frame-customizer'),
                'description' => __('Bold modern color blocks with crisp graphic energy.', 'tikool-frame-customizer'),
                'ai_feature' => 'blanket_portrait_pop_art',
            ],
        ];
    }

    /**
     * Resolve the portrait styles configured for one product.
     *
     * @return array<string, array{label: string, short_label: string, description: string, ai_feature: string, preview_image_id?: int}>
     */
    public static function styles(int $product_id = 0): array
    {
        $owner_id = $product_id > 0 ? wp_get_post_parent_id($product_id) : 0;
        $owner_id = $owner_id > 0 ? $owner_id : $product_id;
        $settings = $owner_id > 0 ? Config::product_settings($owner_id) : [];
        $styles = ($settings['renderer'] ?? 'frame') === 'blanket' ? self::blanket_defaults() : self::defaults();
        if ($owner_id > 0 && metadata_exists('post', $owner_id, self::META_STYLES)) {
            $stored = get_post_meta($owner_id, self::META_STYLES, true);
            $sanitized = self::sanitize_styles(is_array($stored) ? $stored : []);
            if ($sanitized !== []) {
                $styles = $sanitized;
            }
        }
        $styles = self::add_preview_images($styles);

        /**
         * Filter the styles offered by the Custom Pet Portrait editor.
         *
         * @param array<string, array<string, string>> $styles Portrait styles.
         * @param int                                  $owner_id Parent product ID.
         */
        return (array) apply_filters('tikool_frame_customizer_portrait_styles', $styles, $owner_id);
    }

    /**
     * @param array<string, array<string, mixed>> $styles
     * @return array<string, array<string, mixed>>
     */
    private static function add_preview_images(array $styles): array
    {
        if (! class_exists('\\Tikool\\AI\\Prompts')) {
            return $styles;
        }

        $profiles = \Tikool\AI\Prompts::profiles();
        foreach ($styles as $key => $style) {
            $feature = sanitize_key((string) ($style['ai_feature'] ?? ''));
            $styles[$key]['preview_image_id'] = $feature !== ''
                ? absint($profiles[$feature]['preview_image_id'] ?? 0)
                : 0;
            $styles[$key]['variants'] = $feature !== '' && method_exists('\Tikool\AI\Prompts', 'variants')
                ? \Tikool\AI\Prompts::variants($feature)
                : [];
        }

        return $styles;
    }

    /**
     * @param array<int|string, mixed> $submitted
     * @return array<string, array{label: string, short_label: string, description: string, ai_feature: string}>
     */
    public static function sanitize_styles(array $submitted): array
    {
        $clean = [];
        foreach (array_slice($submitted, 0, 20, true) as $raw_key => $style) {
            if (! is_array($style)) {
                continue;
            }
            $key = sanitize_key((string) ($style['key'] ?? $raw_key));
            if ($key === '' || isset($clean[$key])) {
                continue;
            }
            $label = sanitize_text_field((string) ($style['label'] ?? ''));
            $short_label = sanitize_text_field((string) ($style['short_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $ai_feature = $key === 'original' ? '' : sanitize_key((string) ($style['ai_feature'] ?? ''));
            if ($key !== 'original' && $ai_feature === '') {
                continue;
            }
            $clean[$key] = [
                'label' => $label,
                'short_label' => $short_label !== '' ? $short_label : $label,
                'description' => sanitize_text_field((string) ($style['description'] ?? '')),
                'ai_feature' => $ai_feature,
            ];
        }

        $original = $clean['original'] ?? self::defaults()['original'];
        $original['ai_feature'] = '';
        unset($clean['original']);

        return ['original' => $original] + $clean;
    }

    public function boot(): void
    {
        add_action('wp_ajax_tikool_fc_generate_style', [$this, 'generate']);
        add_action('wp_ajax_nopriv_tikool_fc_generate_style', [$this, 'generate']);
        add_action('wp_ajax_tikool_fc_ai_job_status', [$this, 'job_status']);
        add_action('wp_ajax_nopriv_tikool_fc_ai_job_status', [$this, 'job_status']);
        add_action('wp_ajax_tikool_fc_ai_job_preview', [$this, 'stream_job_preview']);
        add_action('wp_ajax_nopriv_tikool_fc_ai_job_preview', [$this, 'stream_job_preview']);
        add_action('wp_ajax_tikool_fc_ai_preview', [$this, 'stream_preview']);
        add_action('wp_ajax_nopriv_tikool_fc_ai_preview', [$this, 'stream_preview']);
        add_action('tikool_fc_delete_ai_preview', [$this, 'delete_preview']);
        add_action('tikool_fc_process_ai_job', [$this, 'process_job'], 10, 1);
        add_action('tikool_fc_mark_ai_job_stale', [$this, 'mark_job_stale'], 10, 1);
        add_action('tikool_fc_delete_ai_job', [$this, 'delete_job'], 10, 1);
    }

    public function generate(): void
    {
        $product_id = absint($_POST['product_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['nonce'] ?? '')));
        if ($product_id < 1 || ! wp_verify_nonce($nonce, 'tikool_frame_customizer_' . $product_id)) {
            wp_send_json_error(['message' => __('Your customization session expired. Refresh the page and try again.', 'tikool-frame-customizer')], 403);
        }
        if (! Config::is_enabled($product_id)) {
            wp_send_json_error(['message' => __('This product does not support portrait generation.', 'tikool-frame-customizer')], 400);
        }

        $style_key = sanitize_key((string) ($_POST['style'] ?? ''));
        $styles = self::styles($product_id);
        $style = $styles[$style_key] ?? null;
        if (! is_array($style) || ($style['ai_feature'] ?? '') === '') {
            wp_send_json_error(['message' => __('Choose a valid AI portrait style.', 'tikool-frame-customizer')], 400);
        }
        if (! function_exists('tikool_ai_generate_image') || ! function_exists('tikool_ai_image_data_url')) {
            wp_send_json_error(['message' => __('AI portrait preview is temporarily unavailable.', 'tikool-frame-customizer')], 503);
        }

        $base_feature = sanitize_key((string) $style['ai_feature']);
        $variants = class_exists('\Tikool\AI\Prompts') && method_exists('\Tikool\AI\Prompts', 'variants')
            ? \Tikool\AI\Prompts::variants($base_feature)
            : [];
        $variant_key = sanitize_key((string) ($_POST['variant'] ?? ''));
        if ($variants !== [] && $variant_key === '') {
            $variant_key = (string) array_key_first($variants);
        }
        if ($variants !== [] && ! isset($variants[$variant_key])) {
            wp_send_json_error(['message' => __('Choose a valid background option.', 'tikool-frame-customizer')], 400);
        }
        if ($variants === []) {
            $variant_key = '';
        }
        $feature = $variant_key !== '' && method_exists('\Tikool\AI\Prompts', 'feature_for_variant')
            ? \Tikool\AI\Prompts::feature_for_variant($base_feature, $variant_key)
            : $base_feature;

        $variation_id = absint($_POST['variation_id'] ?? 0);
        $composition = $this->composition_spec($product_id, $variation_id);
        if (is_wp_error($composition)) {
            wp_send_json_error([
                'code' => $composition->get_error_code(),
                'message' => $composition->get_error_message(),
            ], 400);
        }

        $this->ensure_guest_session();

        $upload = $_FILES['image'] ?? null;
        if (! is_array($upload)) {
            wp_send_json_error(['message' => __('Upload a valid pet photo first.', 'tikool-frame-customizer')], 400);
        }
        $prepared = $this->prepare_source($upload);
        if (is_wp_error($prepared)) {
            wp_send_json_error(['code' => $prepared->get_error_code(), 'message' => $prepared->get_error_message()], 400);
        }
        $source_path = $this->persist_job_source($prepared);
        @unlink($prepared); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (is_wp_error($source_path)) {
            wp_send_json_error(['code' => $source_path->get_error_code(), 'message' => $source_path->get_error_message()], 500);
        }

        $this->cleanup_stale_jobs();
        $rate_key = $this->rate_key();
        $rate_hash = md5($rate_key);
        $owner_hash = hash('sha256', $rate_key);
        $image_hash = (string) hash_file('sha256', $source_path);
        $fingerprint = hash('sha256', implode('|', [
            (string) $product_id,
            $style_key,
            $variant_key,
            $image_hash,
            $this->ai_signature($feature),
            (string) wp_json_encode($composition['variables']),
        ]));
        $dedupe_key = hash('sha256', $owner_hash . '|' . $fingerprint);
        $existing = AI_Jobs::find_by_dedupe($dedupe_key);

        if (is_array($existing) && in_array((string) $existing['status'], ['queued', 'requesting'], true)) {
            @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $this->send_job_response($existing);
        }
        if (is_array($existing) && (string) $existing['status'] === 'success') {
            $result_path = (string) ($existing['result_path'] ?? '');
            if ($result_path !== '' && $this->is_private_path($result_path) && is_file($result_path)) {
                @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $this->record_cache_hit($existing);
                $this->send_job_response($existing, true);
            }
        }

        $active = AI_Jobs::find_active_for_owner($owner_hash);
        if (is_array($active)) {
            @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            wp_send_json_error([
                'code' => 'tikool_fc_generation_in_progress',
                'message' => __('Another portrait is still being prepared. Please wait for it to finish before choosing another style.', 'tikool-frame-customizer'),
                'retryAfter' => self::JOB_POLL_AFTER,
            ], 409);
        }

        $rate_reservation = $this->reserve_rate_limit_hash($rate_hash);
        if (is_wp_error($rate_reservation)) {
            @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $data = $rate_reservation->get_error_data();
            $data = is_array($data) ? $data : [];
            wp_send_json_error([
                'code' => $rate_reservation->get_error_code(),
                'message' => $rate_reservation->get_error_message(),
                'retryAfter' => max(1, (int) ($data['retry_after'] ?? self::RATE_WINDOW)),
            ], 429);
        }

        if (is_array($existing)) {
            $old_result = (string) ($existing['result_path'] ?? '');
            if ($old_result !== '' && $this->is_private_path($old_result) && is_file($old_result)) {
                @unlink($old_result); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
            if (! AI_Jobs::requeue((int) $existing['id'], $source_path, $rate_hash)) {
                $this->refund_rate_limit_hash($rate_hash);
                @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $current = AI_Jobs::find_by_dedupe($dedupe_key);
                if (is_array($current)) {
                    $this->send_job_response($current);
                }
                wp_send_json_error(['message' => __('The portrait job could not be restarted. Please try again.', 'tikool-frame-customizer')], 409);
            }
            $job_id = (int) $existing['id'];
        } else {
            $job_id = AI_Jobs::create([
                'owner_hash' => $owner_hash,
                'fingerprint' => $fingerprint,
                'dedupe_key' => $dedupe_key,
                'public_token' => bin2hex(random_bytes(24)),
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'style_key' => $style_key,
                'feature' => $feature,
                'rate_hash' => $rate_hash,
                'source_path' => $source_path,
            ]);
            if ($job_id < 1) {
                $this->refund_rate_limit_hash($rate_hash);
                @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $current = AI_Jobs::find_by_dedupe($dedupe_key);
                if (is_array($current)) {
                    $this->send_job_response($current);
                }
                wp_send_json_error(['message' => __('The portrait job could not be created. Please try again.', 'tikool-frame-customizer')], 500);
            }
        }

        if (! $this->enqueue_job($job_id)) {
            AI_Jobs::fail($job_id, 'tikool_fc_queue_failed', __('The portrait job could not be queued. Please try again.', 'tikool-frame-customizer'));
            $this->refund_rate_limit_hash($rate_hash);
            @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            wp_send_json_error(['message' => __('The portrait job could not be queued. Please try again.', 'tikool-frame-customizer')], 503);
        }
        wp_schedule_single_event(time() + self::JOB_STALE_AFTER, 'tikool_fc_mark_ai_job_stale', [$job_id]);
        $job = AI_Jobs::find($job_id);
        if (! is_array($job)) {
            wp_send_json_error(['message' => __('The portrait job could not be loaded.', 'tikool-frame-customizer')], 500);
        }
        $this->send_job_response($job);
    }

    /**
     * Retained only as a rollback reference for the previous synchronous flow.
     */
    private function generate_legacy(): void
    {
        $product_id = absint($_POST['product_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['nonce'] ?? '')));
        if ($product_id < 1 || ! wp_verify_nonce($nonce, 'tikool_frame_customizer_' . $product_id)) {
            wp_send_json_error(['message' => __('Your customization session expired. Refresh the page and try again.', 'tikool-frame-customizer')], 403);
        }
        if (! Config::is_enabled($product_id)) {
            wp_send_json_error(['message' => __('This product does not support portrait generation.', 'tikool-frame-customizer')], 400);
        }

        $style_key = sanitize_key((string) ($_POST['style'] ?? ''));
        $styles = self::styles($product_id);
        $style = $styles[$style_key] ?? null;
        if (! is_array($style) || ($style['ai_feature'] ?? '') === '') {
            wp_send_json_error(['message' => __('Choose a valid AI portrait style.', 'tikool-frame-customizer')], 400);
        }
        if (! function_exists('tikool_ai_generate_image') || ! function_exists('tikool_ai_image_data_url')) {
            wp_send_json_error(['message' => __('AI portrait preview is temporarily unavailable.', 'tikool-frame-customizer')], 503);
        }

        $upload = $_FILES['image'] ?? null;
        if (! is_array($upload)) {
            wp_send_json_error(['message' => __('Upload a valid pet photo first.', 'tikool-frame-customizer')], 400);
        }

        $rate_key = $this->rate_key();
        $temporary_upload = (string) ($upload['tmp_name'] ?? '');
        $image_hash = is_file($temporary_upload) ? (string) hash_file('sha256', $temporary_upload) : '';
        $request_fingerprint = hash('sha256', implode('|', [$rate_key, (string) $product_id, $style_key, $image_hash]));
        $result_cache_key = 'tikool_fc_ai_result_' . $request_fingerprint;
        $cached_result = get_transient($result_cache_key);
        if (is_array($cached_result) && ! empty($cached_result['previewUrl'])) {
            $cached_result['cached'] = true;
            wp_send_json_success($cached_result);
        }

        $lock_key = 'session_' . md5($rate_key);
        $lock = $this->acquire_lock($lock_key, $request_fingerprint, $style_key);
        if (is_wp_error($lock)) {
            $data = $lock->get_error_data();
            $data = is_array($data) ? $data : [];
            wp_send_json_error([
                'code' => $lock->get_error_code(),
                'message' => $lock->get_error_message(),
                'retryAfter' => max(1, (int) ($data['retry_after'] ?? self::LOCK_TTL)),
            ], 409);
        }

        $rate_reservation = $this->reserve_rate_limit($rate_key);
        if (is_wp_error($rate_reservation)) {
            $this->release_lock($lock_key, (string) ($lock['token'] ?? ''));
            $data = $rate_reservation->get_error_data();
            $data = is_array($data) ? $data : [];
            wp_send_json_error([
                'code' => $rate_reservation->get_error_code(),
                'message' => $rate_reservation->get_error_message(),
                'retryAfter' => max(1, (int) ($data['retry_after'] ?? self::RATE_WINDOW)),
            ], 429);
        }

        $prepared_path = '';
        $success_data = null;
        $error_message = '';
        $error_status = 500;
        $rate_reserved = true;
        try {
            $prepared = $this->prepare_source($upload);
            if (is_wp_error($prepared)) {
                throw new \RuntimeException($prepared->get_error_message(), 400);
            }
            $prepared_path = $prepared;

            $image = tikool_ai_image_data_url($prepared_path);
            if (is_wp_error($image)) {
                throw new \RuntimeException(__('The uploaded photo could not be prepared for AI preview.', 'tikool-frame-customizer'), 400);
            }

            $product = wc_get_product($product_id);
            $result = tikool_ai_generate_image([
                'feature' => (string) $style['ai_feature'],
                'source' => 'tikool-frame-customizer',
                'image' => $image,
                'watermark' => false,
                'variables' => [
                    'product_name' => $product instanceof \WC_Product ? $product->get_name() : __('Custom Pet Portrait', 'tikool-frame-customizer'),
                ],
                'context' => [
                    'source' => 'tikool-frame-customizer',
                    'product_id' => $product_id,
                    'variation_id' => absint($_POST['variation_id'] ?? 0),
                    'style' => $style_key,
                ],
            ]);
            if (is_wp_error($result)) {
                $message = in_array($result->get_error_code(), ['tikool_ai_not_configured', 'tikool_ai_unknown_feature', 'tikool_ai_empty_prompt'], true)
                    ? __('AI portrait generation is not configured yet. Choose Original or try again later.', 'tikool-frame-customizer')
                    : __('We could not generate this style right now. Please try again or choose Original.', 'tikool-frame-customizer');
                throw new \RuntimeException($message, 502);
            }

            $stored = $this->store_remote_preview((string) ($result['url'] ?? ''));
            if (is_wp_error($stored)) {
                throw new \RuntimeException($stored->get_error_message(), 502);
            }

            $token = bin2hex(random_bytes(24));
            set_transient('tikool_fc_ai_preview_' . $token, $stored, self::PREVIEW_TTL);
            wp_schedule_single_event(time() + self::PREVIEW_TTL, 'tikool_fc_delete_ai_preview', [$stored['path']]);

            $success_data = [
                'style' => $style_key,
                'styleLabel' => (string) $style['label'],
                'previewUrl' => add_query_arg(
                    ['action' => 'tikool_fc_ai_preview', 'token' => $token],
                    admin_url('admin-ajax.php')
                ),
            ];
            set_transient($result_cache_key, $success_data, self::PREVIEW_TTL);
        } catch (\Throwable $error) {
            $error_message = $error->getMessage() !== ''
                ? $error->getMessage()
                : __('We could not generate this style right now. Please try again or choose Original.', 'tikool-frame-customizer');
            $error_status = in_array($error->getCode(), [400, 409, 429, 500, 502, 503], true) ? $error->getCode() : 500;
            if ($rate_reserved) {
                $this->refund_rate_limit($rate_key);
                $rate_reserved = false;
            }
        } finally {
            $this->release_lock($lock_key, (string) ($lock['token'] ?? ''));
            if ($prepared_path !== '' && is_file($prepared_path)) {
                @unlink($prepared_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        if (! is_array($success_data)) {
            wp_send_json_error([
                'code' => 'tikool_fc_generation_failed',
                'message' => $error_message,
            ], $error_status);
        }
        wp_send_json_success($success_data);
    }

    public function job_status(): void
    {
        $product_id = absint($_POST['product_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['nonce'] ?? '')));
        if ($product_id < 1 || ! wp_verify_nonce($nonce, 'tikool_frame_customizer_' . $product_id)) {
            wp_send_json_error(['message' => __('Your customization session expired. Refresh the page and try again.', 'tikool-frame-customizer')], 403);
        }
        $token = sanitize_text_field(wp_unslash((string) ($_POST['job_token'] ?? '')));
        $access_token = sanitize_text_field(wp_unslash((string) ($_POST['job_access_token'] ?? '')));
        $job = preg_match('/^[a-f0-9]{48}$/', $token) === 1 ? AI_Jobs::find_by_token($token) : null;
        if (! is_array($job) || ! $this->can_access_job($job, $access_token) || (int) $job['product_id'] !== $product_id) {
            wp_send_json_error(['message' => __('Portrait job not found.', 'tikool-frame-customizer')], 404);
        }

        $status = (string) ($job['status'] ?? 'failed');
        if ($status === 'success') {
            $path = (string) ($job['result_path'] ?? '');
            if ($path === '' || ! $this->is_private_path($path) || ! is_file($path)) {
                AI_Jobs::delete((int) $job['id']);
                wp_send_json_success([
                    'status' => 'failed',
                    'code' => 'tikool_fc_preview_expired',
                    'message' => __('This generated preview expired. Choose the style to generate it again.', 'tikool-frame-customizer'),
                    'retryable' => true,
                ]);
            }
            wp_send_json_success($this->job_payload($job));
        }
        if ($status === 'failed') {
            wp_send_json_success([
                'status' => 'failed',
                'code' => sanitize_key((string) ($job['error_code'] ?? 'tikool_fc_generation_failed')),
                'message' => (string) ($job['error_message'] ?? __('We could not generate this style right now.', 'tikool-frame-customizer')),
                'retryable' => true,
            ]);
        }
        wp_send_json_success($this->job_payload($job));
    }

    public function process_job(int $job_id): void
    {
        $job = AI_Jobs::find($job_id);
        if (! is_array($job) || ! AI_Jobs::claim($job_id)) {
            return;
        }
        $source_path = (string) ($job['source_path'] ?? '');
        $rate_hash = (string) ($job['rate_hash'] ?? '');
        $stored_path = '';
        try {
            if ($source_path === '' || ! $this->is_private_path($source_path) || ! is_file($source_path)) {
                throw new \RuntimeException(__('The source photo for this portrait is no longer available. Please upload it again.', 'tikool-frame-customizer'), 400);
            }
            $image = tikool_ai_image_data_url($source_path);
            if (is_wp_error($image)) {
                throw new \RuntimeException(__('The uploaded photo could not be prepared for AI preview.', 'tikool-frame-customizer'), 400);
            }
            $product = wc_get_product((int) $job['product_id']);
            $product_settings = Config::product_settings((int) $job['product_id']);
            $composition = $this->composition_spec((int) $job['product_id'], (int) $job['variation_id']);
            if (is_wp_error($composition)) {
                throw new \RuntimeException($composition->get_error_message(), 400);
            }
            $result = tikool_ai_generate_image([
                'feature' => (string) $job['feature'],
                'source' => 'tikool-frame-customizer',
                'image' => $image,
                'watermark' => false,
                'variables' => [
                    'product_name' => $product instanceof \WC_Product ? $product->get_name() : __('Custom Pet Portrait', 'tikool-frame-customizer'),
                    'product_type' => (string) ($product_settings['renderer'] ?? 'frame'),
                ] + $composition['variables'],
                'context' => [
                    'source' => 'tikool-frame-customizer',
                    'product_id' => (int) $job['product_id'],
                    'variation_id' => (int) $job['variation_id'],
                    'style' => (string) $job['style_key'],
                    'renderer' => (string) ($product_settings['renderer'] ?? 'frame'),
                    'sku' => (string) $composition['sku'],
                    'orientation' => (string) $composition['variables']['orientation'],
                    'aspect_ratio' => (string) $composition['variables']['aspect_ratio'],
                    'print_dimensions' => (string) $composition['variables']['print_dimensions'],
                    'frame_job_id' => $job_id,
                    'cache_key' => (string) $job['dedupe_key'],
                    'cache_event' => 'provider_request',
                    'cache_ttl_seconds' => self::PREVIEW_TTL,
                ],
            ]);
            if (is_wp_error($result)) {
                $configuration_error = in_array($result->get_error_code(), ['tikool_ai_not_configured', 'tikool_ai_unknown_feature', 'tikool_ai_unknown_variant', 'tikool_ai_empty_prompt'], true);
                throw new \RuntimeException(
                    $configuration_error
                        ? __('AI portrait generation is not configured yet. Choose Original or try again later.', 'tikool-frame-customizer')
                        : __('We could not generate this style right now. Your photo is safe; use Try again when you are ready.', 'tikool-frame-customizer'),
                    $configuration_error ? 503 : 502
                );
            }
            $stored = $this->store_remote_preview((string) ($result['url'] ?? ''));
            if (is_wp_error($stored)) {
                throw new \RuntimeException($stored->get_error_message(), 502);
            }
            $stored_path = (string) $stored['path'];
            if (! AI_Jobs::succeed($job_id, $stored_path, (string) $stored['mime'])) {
                @unlink($stored_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $stored_path = '';
                return;
            }
            wp_schedule_single_event(time() + self::PREVIEW_TTL, 'tikool_fc_delete_ai_job', [$job_id]);
        } catch (\Throwable $error) {
            $message = $error->getMessage() !== ''
                ? $error->getMessage()
                : __('We could not generate this style right now. Your photo is safe; use Try again when you are ready.', 'tikool-frame-customizer');
            if (AI_Jobs::fail($job_id, 'tikool_fc_generation_failed', $message)) {
                $this->refund_rate_limit_hash($rate_hash);
            }
        } finally {
            wp_clear_scheduled_hook('tikool_fc_mark_ai_job_stale', [$job_id]);
            if ($source_path !== '' && $this->is_private_path($source_path) && is_file($source_path)) {
                @unlink($source_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }

    public function mark_job_stale(int $job_id): void
    {
        $job = AI_Jobs::find($job_id);
        if (! is_array($job) || ! in_array((string) $job['status'], ['queued', 'requesting'], true)) {
            return;
        }
        $path = (string) ($job['source_path'] ?? '');
        if (AI_Jobs::fail($job_id, 'tikool_fc_job_interrupted', __('The portrait request was interrupted. Your photo is safe; use Try again to start a new request.', 'tikool-frame-customizer'))) {
            $this->refund_rate_limit_hash((string) ($job['rate_hash'] ?? ''));
        }
        if ($path !== '' && $this->is_private_path($path) && is_file($path)) {
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }

    public function delete_job(int $job_id): void
    {
        $job = AI_Jobs::find($job_id);
        if (! is_array($job)) {
            return;
        }
        foreach (['source_path', 'result_path'] as $field) {
            $path = (string) ($job[$field] ?? '');
            if ($path !== '' && $this->is_private_path($path) && is_file($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
        AI_Jobs::delete($job_id);
    }

    public function stream_job_preview(): void
    {
        $token = sanitize_text_field(wp_unslash((string) ($_GET['job_token'] ?? '')));
        $access_token = sanitize_text_field(wp_unslash((string) ($_GET['job_access_token'] ?? '')));
        $job = preg_match('/^[a-f0-9]{48}$/', $token) === 1 ? AI_Jobs::find_by_token($token) : null;
        if (! is_array($job) || ! $this->can_access_job($job, $access_token) || (string) $job['status'] !== 'success') {
            wp_die(esc_html__('Preview unavailable.', 'tikool-frame-customizer'), '', ['response' => 404]);
        }
        $this->stream_path((string) ($job['result_path'] ?? ''), (string) ($job['result_mime'] ?? ''));
    }

    public function stream_preview(): void
    {
        $token = sanitize_text_field(wp_unslash((string) ($_GET['token'] ?? '')));
        if (! preg_match('/^[a-f0-9]{48}$/', $token)) {
            wp_die(esc_html__('Preview unavailable.', 'tikool-frame-customizer'), '', ['response' => 404]);
        }

        $preview = get_transient('tikool_fc_ai_preview_' . $token);
        $path = is_array($preview) ? (string) ($preview['path'] ?? '') : '';
        if ($path === '' || ! $this->is_private_path($path) || ! is_file($path)) {
            wp_die(esc_html__('Preview unavailable.', 'tikool-frame-customizer'), '', ['response' => 404]);
        }

        $mime = (string) wp_get_image_mime($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            wp_die(esc_html__('Preview unavailable.', 'tikool-frame-customizer'), '', ['response' => 415]);
        }

        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="ai-portrait-preview.' . $this->extension_for_mime($mime) . '"');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($path);
        exit;
    }

    public function delete_preview(string $path): void
    {
        if ($this->is_private_path($path) && str_contains(basename($path), '-ai-source.')) {
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }

    /** @return string|\WP_Error */
    private function persist_job_source(string $temporary)
    {
        $directory = WP_CONTENT_DIR . '/uploads/' . self::PRIVATE_DIRECTORY;
        if (! wp_mkdir_p($directory)) {
            return new \WP_Error('tikool_fc_ai_storage', __('The uploaded photo could not be stored for processing.', 'tikool-frame-customizer'));
        }
        $path = trailingslashit($directory) . wp_generate_password(32, false, false) . '-ai-job-source.jpg';
        if (! @rename($temporary, $path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if (! copy($temporary, $path)) {
                return new \WP_Error('tikool_fc_ai_storage', __('The uploaded photo could not be stored for processing.', 'tikool-frame-customizer'));
            }
        }
        @chmod($path, 0600); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return $path;
    }

    private function ai_signature(string $feature): string
    {
        $signature = ['feature' => $feature, 'plugin' => defined('TIKOOL_AI_VERSION') ? TIKOOL_AI_VERSION : ''];
        if (class_exists('\Tikool\AI\Settings')) {
            $settings = \Tikool\AI\Settings::all();
            $profiles = is_array($settings['prompt_profiles'] ?? null) ? $settings['prompt_profiles'] : [];
            $base_feature = explode('__variant__', $feature, 2)[0];
            $signature['endpoint'] = (string) ($settings['endpoint'] ?? '');
            $signature['model'] = (string) ($settings['model'] ?? '');
            $signature['size'] = (string) ($settings['size'] ?? '');
            $signature['profile'] = is_array($profiles[$base_feature] ?? null) ? $profiles[$base_feature] : [];
        }
        return hash('sha256', (string) wp_json_encode($signature));
    }

    /**
     * Resolve prompt composition variables from the selected WooCommerce variation.
     *
     * @return array{sku: string, variables: array{orientation: string, aspect_ratio: string, print_dimensions: string}}|\WP_Error
     */
    private function composition_spec(int $product_id, int $variation_id)
    {
        $product = wc_get_product($product_id);
        if (! $product instanceof \WC_Product) {
            return new \WP_Error('tikool_fc_invalid_product', __('The selected product is unavailable.', 'tikool-frame-customizer'));
        }

        $selected_product = $product;
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if (! $variation instanceof \WC_Product_Variation || $variation->get_parent_id() !== $product_id) {
                return new \WP_Error('tikool_fc_invalid_variation', __('Choose a valid product option before creating artwork.', 'tikool-frame-customizer'));
            }
            $selected_product = $variation;
        } elseif ($product->is_type('variable')) {
            return new \WP_Error('tikool_fc_variation_required', __('Choose all product options before creating artwork.', 'tikool-frame-customizer'));
        }

        $snapshot = Config::product_snapshot($selected_product);
        $geometry = is_array($snapshot['geometry'] ?? null) ? $snapshot['geometry'] : [];
        $renderer = sanitize_key((string) ($snapshot['renderer'] ?? 'frame')) ?: 'frame';
        $width_key = $renderer === 'blanket' ? 'print_width_mm' : 'artwork_width_mm';
        $height_key = $renderer === 'blanket' ? 'print_height_mm' : 'artwork_height_mm';
        $width = (float) ($geometry[$width_key] ?? 0);
        $height = (float) ($geometry[$height_key] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return new \WP_Error('tikool_fc_invalid_dimensions', __('The selected product option does not have valid print dimensions.', 'tikool-frame-customizer'));
        }

        $aspect_ratio = self::aspect_ratio($width, $height);
        if (abs($width - $height) < 0.05) {
            $orientation = __('方形（square）', 'tikool-frame-customizer');
        } elseif ($width < $height) {
            $orientation = __('竖版（portrait）', 'tikool-frame-customizer');
        } else {
            $orientation = __('横版（landscape）', 'tikool-frame-customizer');
        }

        return [
            'sku' => (string) $selected_product->get_sku(),
            'variables' => [
                'orientation' => $orientation,
                'aspect_ratio' => $aspect_ratio,
                'print_dimensions' => sprintf(
                    '%s × %s mm',
                    self::format_millimeters($width),
                    self::format_millimeters($height)
                ),
            ],
        ];
    }

    private static function greatest_common_divisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }
        return max(1, abs($left));
    }

    private static function aspect_ratio(float $width, float $height): string
    {
        $actual = $width / $height;
        $common_ratios = [
            [1, 1], [2, 3], [3, 4], [4, 5], [5, 6], [9, 16],
            [3, 2], [4, 3], [5, 4], [6, 5], [16, 9],
        ];
        foreach ($common_ratios as [$ratio_width, $ratio_height]) {
            $candidate = $ratio_width / $ratio_height;
            if (abs($actual - $candidate) / $candidate <= 0.01) {
                return sprintf('%d:%d', $ratio_width, $ratio_height);
            }
        }

        $scaled_width = max(1, (int) round($width * 10));
        $scaled_height = max(1, (int) round($height * 10));
        $divisor = self::greatest_common_divisor($scaled_width, $scaled_height);
        return sprintf('%d:%d', intdiv($scaled_width, $divisor), intdiv($scaled_height, $divisor));
    }

    private static function format_millimeters(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /** @param array<string, mixed> $job */
    private function record_cache_hit(array $job): void
    {
        if (! class_exists('\Tikool\AI\Generation_Log') || ! class_exists('\Tikool\AI\Settings')) {
            return;
        }
        $settings = \Tikool\AI\Settings::all();
        $updated_at = strtotime((string) ($job['updated_at'] ?? '') . ' UTC');
        $expires_at = ($updated_at !== false ? $updated_at : time()) + self::PREVIEW_TTL;
        $cache_value = [
            'job_id' => (int) ($job['id'] ?? 0),
            'status' => (string) ($job['status'] ?? ''),
            'product_id' => (int) ($job['product_id'] ?? 0),
            'variation_id' => (int) ($job['variation_id'] ?? 0),
            'style' => (string) ($job['style_key'] ?? ''),
            'result_mime' => (string) ($job['result_mime'] ?? ''),
            'expires_at_utc' => gmdate('Y-m-d H:i:s', $expires_at),
            'expires_in_seconds' => max(0, $expires_at - time()),
        ];
        \Tikool\AI\Generation_Log::record([
            'status' => 'cached',
            'feature' => (string) ($job['feature'] ?? ''),
            'source' => 'tikool-frame-customizer',
            'model' => (string) ($settings['model'] ?? ''),
            'size' => (string) ($settings['size'] ?? ''),
            'duration_ms' => 0,
            'http_status' => 0,
            'context' => [
                'cache_key' => (string) ($job['dedupe_key'] ?? ''),
                'cache_event' => 'hit',
                'cache_value' => (string) wp_json_encode($cache_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ]);
    }

    private function enqueue_job(int $job_id): bool
    {
        if (function_exists('as_enqueue_async_action')) {
            return (int) as_enqueue_async_action('tikool_fc_process_ai_job', ['job_id' => $job_id], 'tikool-frame-customizer', false, 10) > 0;
        }
        $scheduled = wp_schedule_single_event(time() + 1, 'tikool_fc_process_ai_job', [$job_id]);
        if ($scheduled) {
            spawn_cron();
        }
        return (bool) $scheduled;
    }

    /** @param array<string, mixed> $job */
    private function send_job_response(array $job, bool $cached = false): void
    {
        $payload = $this->job_payload($job);
        if ($cached) {
            $payload['cached'] = true;
        }
        wp_send_json_success($payload, in_array((string) ($job['status'] ?? ''), ['queued', 'requesting'], true) ? 202 : 200);
    }

    /** @param array<string, mixed> $job
     *  @return array<string, mixed>
     */
    private function job_payload(array $job): array
    {
        $status = (string) ($job['status'] ?? 'queued');
        $payload = [
            'status' => $status,
            'jobToken' => (string) ($job['public_token'] ?? ''),
            'jobAccessToken' => $this->job_access_token($job),
            'style' => (string) ($job['style_key'] ?? ''),
            'pollAfter' => self::JOB_POLL_AFTER,
        ];
        if ($status === 'success') {
            $payload['previewUrl'] = add_query_arg([
                'action' => 'tikool_fc_ai_job_preview',
                'job_token' => (string) ($job['public_token'] ?? ''),
                'job_access_token' => $this->job_access_token($job),
            ], admin_url('admin-ajax.php'));
        }
        return $payload;
    }

    /** @param array<string, mixed> $job */
    private function can_access_job(array $job, string $access_token = ''): bool
    {
        if ($this->owns_job($job)) {
            return true;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $access_token) !== 1) {
            return false;
        }
        return hash_equals($this->job_access_token($job), $access_token);
    }

    /** @param array<string, mixed> $job */
    private function job_access_token(array $job): string
    {
        return hash_hmac('sha256', implode('|', [
            (string) ($job['public_token'] ?? ''),
            (string) ($job['product_id'] ?? 0),
            (string) ($job['owner_hash'] ?? ''),
        ]), wp_salt('auth'));
    }

    /** @param array<string, mixed> $job */
    private function owns_job(array $job): bool
    {
        return hash_equals((string) ($job['owner_hash'] ?? ''), hash('sha256', $this->rate_key()));
    }

    private function stream_path(string $path, string $declared_mime = ''): void
    {
        if ($path === '' || ! $this->is_private_path($path) || ! is_file($path)) {
            wp_die(esc_html__('Preview unavailable.', 'tikool-frame-customizer'), '', ['response' => 404]);
        }
        $mime = (string) wp_get_image_mime($path);
        if ($declared_mime !== '' && hash_equals($declared_mime, $mime) === false) {
            wp_die(esc_html__('Preview unavailable.', 'tikool-frame-customizer'), '', ['response' => 415]);
        }
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            wp_die(esc_html__('Preview unavailable.', 'tikool-frame-customizer'), '', ['response' => 415]);
        }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="ai-portrait-preview.' . $this->extension_for_mime($mime) . '"');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($path);
        exit;
    }

    private function cleanup_stale_jobs(): void
    {
        foreach (AI_Jobs::stale(self::JOB_STALE_AFTER) as $job) {
            $this->mark_job_stale((int) ($job['id'] ?? 0));
        }
    }

    /**
     * @param array<string, mixed> $upload
     * @return string|\WP_Error
     */
    private function prepare_source(array $upload)
    {
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new \WP_Error('tikool_fc_ai_upload', __('Upload a valid pet photo first.', 'tikool-frame-customizer'));
        }
        $size = (int) ($upload['size'] ?? 0);
        $temporary = (string) ($upload['tmp_name'] ?? '');
        $name = sanitize_file_name((string) ($upload['name'] ?? 'pet-photo'));
        if ($size < 1 || $size > self::MAX_UPLOAD_BYTES || ! is_uploaded_file($temporary)) {
            return new \WP_Error('tikool_fc_ai_upload', __('Use a JPG, PNG, or WebP image smaller than 20 MB.', 'tikool-frame-customizer'));
        }

        $checked = wp_check_filetype_and_ext($temporary, $name, [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ]);
        $dimensions = @getimagesize($temporary); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (empty($checked['type']) || ! is_array($dimensions)) {
            return new \WP_Error('tikool_fc_ai_upload', __('Use a valid JPG, PNG, or WebP pet photo.', 'tikool-frame-customizer'));
        }

        $editor = wp_get_image_editor($temporary);
        if (is_wp_error($editor)) {
            return new \WP_Error('tikool_fc_ai_editor', __('The uploaded photo could not be prepared for AI preview.', 'tikool-frame-customizer'));
        }
        $editor_size = $editor->get_size();
        if (($editor_size['width'] ?? 0) > self::AI_SOURCE_MAX_EDGE || ($editor_size['height'] ?? 0) > self::AI_SOURCE_MAX_EDGE) {
            $resized = $editor->resize(self::AI_SOURCE_MAX_EDGE, self::AI_SOURCE_MAX_EDGE, false);
            if (is_wp_error($resized)) {
                return new \WP_Error('tikool_fc_ai_editor', __('The uploaded photo could not be resized for AI preview.', 'tikool-frame-customizer'));
            }
        }
        $editor->set_quality(self::AI_SOURCE_JPEG_QUALITY);

        $base = wp_tempnam('tikool-ai-source');
        if (! is_string($base) || $base === '') {
            return new \WP_Error('tikool_fc_ai_temp', __('A temporary preview file could not be created.', 'tikool-frame-customizer'));
        }
        @unlink($base); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $destination = $base . '.jpg';
        $saved = $editor->save($destination, 'image/jpeg');
        if (is_wp_error($saved) || ! is_file($destination) || filesize($destination) > self::AI_SOURCE_MAX_BYTES) {
            @unlink($destination); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new \WP_Error('tikool_fc_ai_editor', __('The uploaded photo could not be prepared for AI preview.', 'tikool-frame-customizer'));
        }

        return $destination;
    }

    /**
     * @return array{path: string, mime: string}|\WP_Error
     */
    private function store_remote_preview(string $url)
    {
        if ($url === '' || ! wp_http_validate_url($url)) {
            return new \WP_Error('tikool_fc_ai_result', __('The AI service did not return a usable portrait.', 'tikool-frame-customizer'));
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => 60,
            'redirection' => 2,
            'limit_response_size' => self::MAX_DOWNLOAD_BYTES + 1,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('tikool_fc_ai_download', __('The generated portrait could not be downloaded. Please try again.', 'tikool-frame-customizer'));
        }
        $body = (string) wp_remote_retrieve_body($response);
        $declared_length = (int) wp_remote_retrieve_header($response, 'content-length');
        if ($body === '' || $declared_length > self::MAX_DOWNLOAD_BYTES || strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            return new \WP_Error('tikool_fc_ai_download', __('The generated portrait file was invalid.', 'tikool-frame-customizer'));
        }

        $image = @getimagesizefromstring($body); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return new \WP_Error('tikool_fc_ai_download', __('The generated portrait format was not supported.', 'tikool-frame-customizer'));
        }

        $directory = WP_CONTENT_DIR . '/uploads/' . self::PRIVATE_DIRECTORY;
        if (! wp_mkdir_p($directory)) {
            return new \WP_Error('tikool_fc_ai_storage', __('The generated portrait could not be stored.', 'tikool-frame-customizer'));
        }
        $path = trailingslashit($directory) . wp_generate_password(32, false, false) . '-ai-source.' . $this->extension_for_mime($mime);
        if (file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new \WP_Error('tikool_fc_ai_storage', __('The generated portrait could not be stored.', 'tikool-frame-customizer'));
        }

        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * @return array{token: string, started_at: int, expires_at: int, fingerprint: string, style: string}|\WP_Error
     */
    private function acquire_lock(string $key, string $fingerprint, string $style)
    {
        $now = time();
        $record = [
            'token' => bin2hex(random_bytes(16)),
            'started_at' => $now,
            'expires_at' => $now + self::LOCK_TTL,
            'fingerprint' => $fingerprint,
            'style' => $style,
        ];

        if ($this->add_lock($key, $record)) {
            return $record;
        }

        $active = $this->get_lock($key);
        if (is_array($active) && (int) ($active['expires_at'] ?? 0) <= $now) {
            $this->delete_lock($key);
            if ($this->add_lock($key, $record)) {
                return $record;
            }
            $active = $this->get_lock($key);
        }

        $expires_at = is_array($active) ? (int) ($active['expires_at'] ?? 0) : 0;
        $retry_after = $expires_at > $now ? $expires_at - $now : 5;

        return new \WP_Error(
            'tikool_fc_generation_in_progress',
            __('Another portrait is still being prepared. Please wait a moment.', 'tikool-frame-customizer'),
            ['retry_after' => $retry_after]
        );
    }

    private function release_lock(string $key, string $token): void
    {
        if ($token === '') {
            return;
        }
        $active = $this->get_lock($key);
        $active_token = is_array($active) ? (string) ($active['token'] ?? '') : '';
        if ($active_token !== '' && hash_equals($active_token, $token)) {
            $this->delete_lock($key);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function add_lock(string $key, array $record): bool
    {
        if (wp_using_ext_object_cache()) {
            return wp_cache_add($key, $record, self::LOCK_CACHE_GROUP, self::LOCK_TTL);
        }

        return add_option('_tikool_fc_ai_lock_' . $key, $record, '', false);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function get_lock(string $key)
    {
        if (wp_using_ext_object_cache()) {
            $record = wp_cache_get($key, self::LOCK_CACHE_GROUP);
        } else {
            $record = get_option('_tikool_fc_ai_lock_' . $key, false);
        }

        return is_array($record) ? $record : false;
    }

    private function delete_lock(string $key): void
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($key, self::LOCK_CACHE_GROUP);
            return;
        }
        delete_option('_tikool_fc_ai_lock_' . $key);
    }

    /**
     * Reserve one quota unit only after this request owns the generation lock.
     *
     * @return array{count: int, reset_at: int}|\WP_Error
     */
    private function reserve_rate_limit(string $key)
    {
        return $this->reserve_rate_limit_hash(md5($key));
    }

    /** @return array{count: int, reset_at: int}|\WP_Error */
    private function reserve_rate_limit_hash(string $hash)
    {
        $transient = 'tikool_fc_ai_rate_' . $hash;
        $record = get_transient($transient);
        $now = time();
        if (! is_array($record) || ! isset($record['reset_at']) || (int) $record['reset_at'] <= $now) {
            $record = ['count' => 0, 'reset_at' => $now + self::RATE_WINDOW];
        }

        $count = max(0, (int) ($record['count'] ?? 0));
        $reset_at = max($now + 1, (int) ($record['reset_at'] ?? ($now + self::RATE_WINDOW)));
        if ($count >= self::RATE_LIMIT) {
            return new \WP_Error(
                'tikool_fc_rate_limited',
                __('You have reached the preview limit. Please wait before generating another style.', 'tikool-frame-customizer'),
                ['retry_after' => $reset_at - $now]
            );
        }

        $record['count'] = $count + 1;
        $record['reset_at'] = $reset_at;
        set_transient($transient, $record, $reset_at - $now);

        return $record;
    }

    private function refund_rate_limit(string $key): void
    {
        $this->refund_rate_limit_hash(md5($key));
    }

    private function refund_rate_limit_hash(string $hash): void
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return;
        }
        $transient = 'tikool_fc_ai_rate_' . $hash;
        $record = get_transient($transient);
        if (! is_array($record) || ! isset($record['reset_at'])) {
            return;
        }

        $count = max(0, (int) ($record['count'] ?? 0) - 1);
        $reset_at = (int) ($record['reset_at'] ?? 0);
        $ttl = $reset_at - time();
        if ($count < 1 || $ttl < 1) {
            delete_transient($transient);
            return;
        }
        $record['count'] = $count;
        set_transient($transient, $record, $ttl);
    }

    private function rate_key(): string
    {
        if (function_exists('WC') && WC()->session && (is_user_logged_in() || WC()->session->has_session())) {
            $customer_id = (string) WC()->session->get_customer_id();
            if ($customer_id !== '') {
                return 'wc:' . $customer_id;
            }
        }
        $ip = $this->client_ip();
        $agent = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        return 'request:' . hash('sha256', $ip . '|' . $agent);
    }

    private function ensure_guest_session(): void
    {
        if (is_user_logged_in() || ! function_exists('WC') || ! WC()->session || WC()->session->has_session()) {
            return;
        }
        WC()->session->set_customer_session_cookie(true);
    }

    private function client_ip(): string
    {
        $candidates = [
            (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '',
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return 'unknown';
    }

    private function is_private_path(string $path): bool
    {
        $directory = realpath(WP_CONTENT_DIR . '/uploads/' . self::PRIVATE_DIRECTORY);
        $file = realpath($path);
        return $directory !== false && $file !== false && str_starts_with($file, trailingslashit($directory));
    }

    private function extension_for_mime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
