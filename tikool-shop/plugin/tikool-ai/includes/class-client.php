<?php
/**
 * Volcengine Ark image-generation client.
 */

declare(strict_types=1);

namespace Tikool\AI;

if (! defined('ABSPATH')) {
    exit;
}

final class Client
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function generate(array $payload)
    {
        $settings = Settings::all();
        $endpoint = esc_url_raw((string) ($settings['endpoint'] ?? ''), ['https']);
        $api_key = trim((string) ($settings['api_key'] ?? ''));

        if ($api_key === '') {
            return new \WP_Error('tikool_ai_not_configured', __('Tikool AI 尚未配置 API Key。', 'tikool-ai'));
        }
        if ($endpoint === '' || ! wp_http_validate_url($endpoint)) {
            return new \WP_Error('tikool_ai_invalid_endpoint', __('Tikool AI 的 API 地址无效。', 'tikool-ai'));
        }

        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($body)) {
            return new \WP_Error('tikool_ai_encode_failed', __('无法编码 AI 请求。', 'tikool-ai'));
        }

        $started_at = microtime(true);
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $body,
            'timeout' => max(15, min(300, (int) ($settings['timeout'] ?? 120))),
            'redirection' => 0,
            'data_format' => 'body',
        ]);
        $duration_ms = (int) round((microtime(true) - $started_at) * 1000);

        if (is_wp_error($response)) {
            do_action('tikool_ai_request_completed', false, 0, $duration_ms, 'transport_error');
            return new \WP_Error(
                'tikool_ai_transport_error',
                __('AI 服务暂时无法连接，请稍后再试。', 'tikool-ai'),
                [
                    'cause' => $response->get_error_message(),
                    'http_status' => 0,
                    'duration_ms' => $duration_ms,
                    'request_id' => '',
                ]
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($response_body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $request_id = (string) wp_remote_retrieve_header($response, 'x-request-id');

        if ($status < 200 || $status >= 300) {
            $provider_message = self::provider_error_message($decoded);
            do_action('tikool_ai_request_completed', false, $status, $duration_ms, $request_id);
            return new \WP_Error(
                'tikool_ai_provider_error',
                $provider_message !== '' ? $provider_message : __('AI 服务返回了错误，请稍后再试。', 'tikool-ai'),
                [
                    'status' => $status,
                    'http_status' => $status,
                    'duration_ms' => $duration_ms,
                    'request_id' => $request_id,
                ]
            );
        }

        $items = $decoded['data'] ?? null;
        $images = [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $item_url = esc_url_raw((string) ($item['url'] ?? ''), ['https']);
                if ($item_url === '') {
                    continue;
                }
                $images[] = [
                    'url' => $item_url,
                    'size' => sanitize_text_field((string) ($item['size'] ?? '')),
                ];
            }
        }

        if ($images === []) {
            do_action('tikool_ai_request_completed', false, $status, $duration_ms, $request_id);
            return new \WP_Error(
                'tikool_ai_invalid_response',
                __('AI 服务未返回可用的图片地址。', 'tikool-ai'),
                [
                    'status' => $status,
                    'http_status' => $status,
                    'duration_ms' => $duration_ms,
                    'request_id' => $request_id,
                ]
            );
        }

        do_action('tikool_ai_request_completed', true, $status, $duration_ms, $request_id);

        return [
            'url' => $images[0]['url'],
            'size' => $images[0]['size'],
            'images' => $images,
            'request_id' => sanitize_text_field($request_id),
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
            'http_status' => $status,
            'duration_ms' => $duration_ms,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function provider_error_message(array $decoded): string
    {
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            return sanitize_text_field((string) ($error['message'] ?? ''));
        }

        return sanitize_text_field((string) ($decoded['message'] ?? ''));
    }
}
