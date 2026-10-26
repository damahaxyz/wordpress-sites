<?php
/**
 * Client for the host-side dual-repository publisher.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class Git_Publisher
{
    /**
     * @return array{html_commit:string,bucket_commit:string,remote_path:string,bucket_path:string,media:array<string,mixed>,manifest:array<string,mixed>}|WP_Error
     */
    public function publish_task(int $task_id, int $page_id, string $slug, string $bucket_git_url, string $bucket, string $token)
    {
        if ($task_id < 1
            || $page_id < 1
            || ! Memory_Pages::is_valid_slug($slug)
            || ! Settings::is_valid_bucket_git_url($bucket_git_url)
            || Settings::bucket_from_git_url($bucket_git_url) !== $bucket
            || ! preg_match('/^[a-f0-9]{32}$/', $token)
            || ! is_dir(Storage::bundle_directory($token))) {
            return new WP_Error('tikool_memory_invalid_task', __('The Memory Page publishing task is invalid.', 'tikool-memory-page'));
        }

        $socket = @stream_socket_client('unix://' . self::socket_path(), $error_number, $error_message, 5, STREAM_CLIENT_CONNECT);
        if (! is_resource($socket)) {
            return new WP_Error(
                'tikool_memory_publisher_unavailable',
                sprintf(__('The Git publisher is unavailable (%s). The task bundle remains saved for retry.', 'tikool-memory-page'), sanitize_text_field((string) $error_message))
            );
        }

        stream_set_timeout($socket, 1800);
        $request = wp_json_encode([
            'task_id'        => $task_id,
            'page_id'        => $page_id,
            'slug'           => $slug,
            'token'          => $token,
            'bucket'         => $bucket,
            'bucket_git_url' => $bucket_git_url,
            'public_path'    => trim(Settings::get('base_path'), '/'),
            'branch'         => Settings::get('branch'),
        ]);
        if (! is_string($request) || fwrite($socket, $request . "\n") === false) {
            fclose($socket);
            return new WP_Error('tikool_memory_publisher_write', __('The publishing-task request could not be sent.', 'tikool-memory-page'));
        }

        $response_line = fgets($socket, 65536);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);
        if (! empty($metadata['timed_out'])) {
            return new WP_Error('tikool_memory_publisher_timeout', __('The publishing task timed out. Its bundle remains available for retry.', 'tikool-memory-page'));
        }

        $response = is_string($response_line) ? json_decode($response_line, true) : null;
        if (! is_array($response) || empty($response['ok'])) {
            $message = is_array($response) ? sanitize_text_field((string) ($response['error'] ?? '')) : '';
            return new WP_Error('tikool_memory_publisher_failed', $message !== '' ? $message : __('The Git publisher returned an invalid response.', 'tikool-memory-page'));
        }

        $html_commit = sanitize_text_field((string) ($response['html_commit'] ?? ''));
        $bucket_commit = sanitize_text_field((string) ($response['bucket_commit'] ?? ''));
        if (! preg_match('/^[a-f0-9]{40}$/', $html_commit) || ! preg_match('/^[a-f0-9]{40}$/', $bucket_commit)) {
            return new WP_Error('tikool_memory_publisher_commit', __('The Git publisher did not return valid commits.', 'tikool-memory-page'));
        }

        $media = $this->sanitize_media($response['media'] ?? null);
        if ($media instanceof WP_Error) {
            return $media;
        }
        $manifest = is_array($response['manifest'] ?? null)
            ? Storage::normalize_manifest($response['manifest'], $page_id)
            : [];
        if ($manifest === []) {
            return new WP_Error('tikool_memory_publisher_manifest', __('The Git publisher did not return a valid manifest.', 'tikool-memory-page'));
        }

        return [
            'html_commit'   => $html_commit,
            'bucket_commit' => $bucket_commit,
            'remote_path'   => sanitize_text_field((string) ($response['remote_path'] ?? '')),
            'bucket_path'   => sanitize_text_field((string) ($response['bucket_path'] ?? '')),
            'media'         => $media,
            'manifest'      => $manifest,
        ];
    }

    /** @return array<string, array<int, array{src:string,name:string}>>|WP_Error */
    private function sanitize_media($value)
    {
        if (! is_array($value)) {
            return new WP_Error('tikool_memory_publisher_media', __('The Git publisher did not return valid media data.', 'tikool-memory-page'));
        }
        $media = ['photos' => [], 'videos' => [], 'audio' => []];
        foreach (array_keys($media) as $kind) {
            if (! isset($value[$kind]) || ! is_array($value[$kind])) {
                return new WP_Error('tikool_memory_publisher_media', __('The Git publisher did not return valid media data.', 'tikool-memory-page'));
            }
            foreach ($value[$kind] as $item) {
                $src = is_array($item) ? (string) ($item['src'] ?? '') : '';
                if (! preg_match('#^' . preg_quote($kind, '#') . '/[A-Za-z0-9._-]+$#', $src)) {
                    return new WP_Error('tikool_memory_publisher_media', __('The Git publisher returned an invalid media path.', 'tikool-memory-page'));
                }
                $media[$kind][] = [
                    'src'  => $src,
                    'name' => sanitize_file_name((string) ($item['name'] ?? basename($src))),
                ];
            }
        }
        return $media;
    }

    public static function socket_path(): string
    {
        if (defined('TIKOOL_MEMORY_PUBLISH_SOCKET') && (string) TIKOOL_MEMORY_PUBLISH_SOCKET !== '') {
            return (string) TIKOOL_MEMORY_PUBLISH_SOCKET;
        }
        return '/var/run/tikool-memory-publisher/publisher.sock';
    }
}
