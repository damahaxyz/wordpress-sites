<?php
/**
 * Public server-side AI service.
 */

declare(strict_types=1);

namespace Tikool\AI;

if (! defined('ABSPATH')) {
    exit;
}

final class Service
{
    private static ?self $instance = null;

    private Client $client;

    private function __construct()
    {
        $this->client = new Client();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Generate an image from a configured feature prompt and source image.
     *
     * Expected arguments:
     * - feature: configured prompt-profile key.
     * - image: one HTTPS URL/data URI, or an array of up to four.
     * - variables: scalar template replacements, without braces.
     * - size/watermark: optional per-request output overrides.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public function generate_image(array $args)
    {
        $started_at = microtime(true);
        $feature = sanitize_key((string) ($args['feature'] ?? ''));
        $settings = Settings::all();
        $context = self::sanitize_context(is_array($args['context'] ?? null) ? $args['context'] : []);
        $source = sanitize_key((string) ($args['source'] ?? $context['source'] ?? ''));
        $log = [
            'feature' => $feature,
            'source' => $source,
            'model' => sanitize_text_field((string) ($settings['model'] ?? '')),
            'size' => self::sanitize_size((string) ($args['size'] ?? $settings['size'] ?? '2K')),
            'prompt' => '',
            'context' => $context,
        ];

        if ($feature === '') {
            return $this->complete(
                new \WP_Error('tikool_ai_feature_required', __('缺少 AI 功能标识。', 'tikool-ai')),
                $log,
                $started_at
            );
        }

        $image = Image_Input::normalize($args['image'] ?? null);
        if (is_wp_error($image)) {
            return $this->complete($image, $log, $started_at);
        }
        $log['context']['input_image_count'] = is_array($image) ? count($image) : 1;

        $variables = is_array($args['variables'] ?? null) ? $args['variables'] : [];
        if (isset($args['user_prompt']) && is_scalar($args['user_prompt'])) {
            $variables['user_prompt'] = (string) $args['user_prompt'];
        }

        $prompt = Prompts::render($feature, $variables);
        if (is_wp_error($prompt)) {
            return $this->complete($prompt, $log, $started_at);
        }
        $log['prompt'] = $prompt;

        $model = sanitize_text_field((string) ($settings['model'] ?? ''));
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'image' => $image,
            'response_format' => sanitize_key((string) ($settings['response_format'] ?? 'url')),
            'size' => self::sanitize_size((string) ($args['size'] ?? $settings['size'] ?? '2K')),
            'stream' => false,
            'watermark' => array_key_exists('watermark', $args)
                ? (bool) $args['watermark']
                : ! empty($settings['watermark']),
        ];
        if (! str_contains($model, 'seedream-5-0-flash')) {
            $payload['sequential_image_generation'] = sanitize_key((string) ($settings['sequential_image_generation'] ?? 'disabled'));
        }

        if ($payload['model'] === '') {
            return $this->complete(
                new \WP_Error('tikool_ai_model_required', __('Tikool AI 尚未配置模型。', 'tikool-ai')),
                $log,
                $started_at
            );
        }

        /**
         * Filter the Ark request payload. Never add secrets to this array.
         *
         * @param array<string, mixed> $payload Request body.
         * @param string               $feature Feature key.
         * @param array<string, mixed> $args    Original service arguments.
         */
        $payload = (array) apply_filters('tikool_ai_image_payload', $payload, $feature, $args);
        $log['model'] = sanitize_text_field((string) ($payload['model'] ?? $log['model']));
        $log['size'] = sanitize_text_field((string) ($payload['size'] ?? $log['size']));
        $log_id = Generation_Log::record($log + [
            'status' => 'requesting',
            'duration_ms' => 0,
            'http_status' => 0,
        ]);
        if ($log_id > 0) {
            $stale_after = max(180, (int) ($settings['timeout'] ?? 120) + 120);
            wp_schedule_single_event(time() + $stale_after, 'tikool_ai_mark_generation_stale', [$log_id]);
        }
        $result = $this->client->generate($payload);

        if (! is_wp_error($result)) {
            $result['feature'] = $feature;
            $result['model'] = (string) $payload['model'];
        }

        return $this->complete($result, $log, $started_at, $log_id);
    }

    /**
     * @param array<string, mixed>|\WP_Error $result
     * @param array<string, mixed>           $log
     * @return array<string, mixed>|\WP_Error
     */
    private function complete($result, array $log, float $started_at, int $log_id = 0)
    {
        $entry = $log;
        $entry['duration_ms'] = max(0, (int) round((microtime(true) - $started_at) * 1000));

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $data = is_array($data) ? $data : [];
            $entry['status'] = 'failed';
            $entry['http_status'] = (int) ($data['http_status'] ?? $data['status'] ?? 0);
            $entry['request_id'] = sanitize_text_field((string) ($data['request_id'] ?? ''));
            $entry['error_code'] = $result->get_error_code();
            $entry['error_message'] = $result->get_error_message();
            $transport_cause = sanitize_text_field((string) ($data['cause'] ?? ''));
            if ($transport_cause !== '') {
                $entry['error_message'] .= ' · ' . $transport_cause;
            }
        } else {
            $entry['status'] = 'success';
            $entry['http_status'] = (int) ($result['http_status'] ?? 200);
            $entry['request_id'] = sanitize_text_field((string) ($result['request_id'] ?? ''));
            $entry['image_url'] = (string) ($result['url'] ?? '');
            $entry['usage'] = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        }

        if ($log_id < 1 || ! Generation_Log::update($log_id, $entry)) {
            Generation_Log::record($entry);
        }
        if ($log_id > 0) {
            wp_clear_scheduled_hook('tikool_ai_mark_generation_stale', [$log_id]);
        }

        return $result;
    }

    /**
     * Keep caller metadata useful for audits without accepting images or large blobs.
     *
     * @param array<string, mixed> $context
     * @return array<string, scalar|null>
     */
    private static function sanitize_context(array $context): array
    {
        $clean = [];
        foreach (array_slice($context, 0, 20, true) as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || (! is_scalar($value) && $value !== null)) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;
                continue;
            }
            $text = sanitize_text_field((string) $value);
            $clean[$key] = function_exists('mb_substr') ? mb_substr($text, 0, 500) : substr($text, 0, 500);
        }

        return $clean;
    }

    private static function sanitize_size(string $size): string
    {
        $size = strtoupper(trim($size));
        if (in_array($size, ['1K', '2K', '4K'], true)) {
            return $size;
        }
        if (preg_match('/^\d{2,5}X\d{2,5}$/', $size) === 1) {
            return strtolower($size);
        }

        return '2K';
    }
}
