<?php
/**
 * Validate content and stage immutable bundles for asynchronous publishing tasks.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class Storage
{
    public const PHOTO_MAX_COUNT = 30;
    public const VIDEO_MAX_COUNT = 5;
    public const AUDIO_MAX_COUNT = 3;

    private const KINDS = [
        'photos' => [
            'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'],
            'max'   => 2 * MB_IN_BYTES,
        ],
        'videos' => [
            'mimes' => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'],
            'max'   => 90 * MB_IN_BYTES,
        ],
        'audio' => [
            'mimes' => ['audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg'],
            'max'   => 20 * MB_IN_BYTES,
        ],
    ];

    /** Persistent checkout of the HTML repository. */
    public static function repository_root(): string
    {
        if (defined('TIKOOL_MEMORY_PRIVATE_DIR') && (string) TIKOOL_MEMORY_PRIVATE_DIR !== '') {
            return untrailingslashit((string) TIKOOL_MEMORY_PRIVATE_DIR);
        }

        return '/var/lib/tikool-memory-pages';
    }

    /** Private task bundles shared with the host publisher. */
    public static function task_root(): string
    {
        return '/var/lib/tikool-memory-tasks';
    }

    public static function public_root(): string
    {
        return self::repository_root() . '/' . trim(Settings::get('base_path'), '/');
    }

    public static function ensure_private_root(): bool
    {
        $root = self::task_root();
        return (is_dir($root) || wp_mkdir_p($root)) && is_writable($root);
    }

    public static function page_html_path(string $slug): string
    {
        return Memory_Pages::is_valid_slug($slug) ? self::public_root() . '/' . $slug . '.html' : '';
    }

    public static function bundle_directory(string $token): string
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) ? self::task_root() . '/' . $token : '';
    }

    public static function media_public_url(int $page_id, string $relative): string
    {
        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $git_url = (string) get_post_meta($page_id, '_tikool_memory_bucket_git_url', true);
        $base = Settings::bucket_public_url($git_url);
        if (! Memory_Pages::is_valid_slug($slug) || $base === '' || ! self::is_valid_media_path($relative)) {
            return '';
        }

        return $base . '/' . rawurlencode($slug) . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    /** Return the canonical editor data saved from, or recovered from, manifest.json. */
    public static function manifest_for_page(int $page_id): array
    {
        $saved = get_post_meta($page_id, '_tikool_memory_manifest', true);
        if (is_array($saved)) {
            $normalized = self::normalize_manifest($saved, $page_id);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $git_url = (string) get_post_meta($page_id, '_tikool_memory_bucket_git_url', true);
        $base = Settings::bucket_public_url($git_url);
        if (! Memory_Pages::is_valid_slug($slug) || $base === '') {
            return [];
        }
        $url = $base . '/' . rawurlencode($slug) . '/manifest.json';
        $response = wp_safe_remote_get($url, [
            'timeout'             => 4,
            'redirection'         => 0,
            'limit_response_size' => MB_IN_BYTES,
            'headers'             => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $normalized = is_array($decoded) ? self::normalize_manifest($decoded, $page_id) : [];
        if ($normalized !== []) {
            update_post_meta($page_id, '_tikool_memory_manifest', $normalized);
        }
        return $normalized;
    }

    /** Normalize public manifest URLs back to editable relative media paths. */
    public static function normalize_manifest(array $manifest, int $page_id): array
    {
        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $git_url = (string) get_post_meta($page_id, '_tikool_memory_bucket_git_url', true);
        $bucket = Settings::bucket_from_git_url($git_url);
        $base = Settings::bucket_public_url($git_url);
        if (! Memory_Pages::is_valid_slug($slug)
            || $bucket === ''
            || (isset($manifest['slug']) && (string) $manifest['slug'] !== $slug)
            || (isset($manifest['bucket']) && (string) $manifest['bucket'] !== $bucket)) {
            return [];
        }

        $media = ['photos' => [], 'videos' => [], 'audio' => []];
        $prefix = $base . '/' . rawurlencode($slug) . '/';
        $source_media = isset($manifest['media']) && is_array($manifest['media']) ? $manifest['media'] : [];
        foreach (array_keys($media) as $kind) {
            $items = isset($source_media[$kind]) && is_array($source_media[$kind]) ? $source_media[$kind] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $src = (string) ($item['src'] ?? '');
                if ($prefix !== '' && str_starts_with($src, $prefix)) {
                    $src = rawurldecode(substr($src, strlen($prefix)));
                }
                $src = self::normalize_media_path($src);
                if (! str_starts_with($src, $kind . '/') || ! self::is_valid_media_path($src)) {
                    continue;
                }
                $media[$kind][] = [
                    'src'  => $src,
                    'name' => sanitize_file_name((string) ($item['name'] ?? basename($src))),
                ];
            }
        }

        $scene = sanitize_key((string) ($manifest['scene'] ?? 'cozy-home'));
        if (! isset(self::scenes(true)[$scene])) {
            $scene = 'cozy-home';
        }
        $pet = isset($manifest['pet']) && is_array($manifest['pet']) ? $manifest['pet'] : [];
        return [
            'version'   => max(1, (int) ($manifest['version'] ?? 1)),
            'slug'      => $slug,
            'bucket'    => $bucket,
            'scene'     => $scene,
            'title'     => sanitize_text_field((string) ($manifest['title'] ?? '')),
            'pet'       => ['name' => sanitize_text_field((string) ($pet['name'] ?? ''))],
            'story'     => sanitize_textarea_field((string) ($manifest['story'] ?? '')),
            'media'     => $media,
            'timeline'  => self::sanitize_timeline($manifest['timeline'] ?? []),
            'updatedAt' => sanitize_text_field((string) ($manifest['updatedAt'] ?? '')),
        ];
    }

    /** Return a v1 local media file only while it is waiting for its first bucket migration. */
    public static function legacy_media_file(string $slug, string $relative): string
    {
        if (! Memory_Pages::is_valid_slug($slug)) {
            return '';
        }
        $relative = ltrim($relative, '/');
        if (str_starts_with($relative, 'media/')) {
            $relative = substr($relative, 6);
        }
        if (! self::is_valid_media_path($relative)) {
            return '';
        }
        $candidate = self::public_root() . '/' . $slug . '/media/' . $relative;
        $root = realpath(self::public_root() . '/' . $slug . '/media');
        $file = realpath($candidate);
        return $root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) ? $file : '';
    }

    /** @return array<string, array{label:string,description:string}> */
    public static function scenes(bool $include_hidden = false): array
    {
        $root = TIKOOL_MEMORY_PAGE_DIR . 'templates/scenes';
        $scenes = [];
        $orders = [];
        foreach (glob($root . '/*/scene.json') ?: [] as $config_file) {
            $slug = basename(dirname($config_file));
            if (! preg_match('/^[a-z0-9-]+$/', $slug) || ! is_file(dirname($config_file) . '/index.html')) {
                continue;
            }
            $config = json_decode((string) file_get_contents($config_file), true);
            if (! is_array($config) || (! $include_hidden && ! empty($config['hidden']))) {
                continue;
            }
            $scenes[$slug] = [
                'label'       => sanitize_text_field((string) ($config['label'] ?? ucfirst($slug))),
                'description' => sanitize_text_field((string) ($config['description'] ?? '')),
            ];
            $orders[$slug] = (int) ($config['order'] ?? 999);
        }
        uksort($scenes, static function (string $left, string $right) use ($orders): int {
            $order = ($orders[$left] ?? 999) <=> ($orders[$right] ?? 999);
            return $order !== 0 ? $order : strcmp($left, $right);
        });
        return $scenes;
    }

    /**
     * @param array<string, mixed> $input Form values.
     * @param array<string, mixed> $files Uploaded files.
     * @return array{token:string,media:array<string,mixed>}|WP_Error
     */
    public function stage(int $page_id, array $input, array $files)
    {
        if (! self::ensure_private_root()) {
            return new WP_Error('tikool_memory_task_storage_unavailable', __('The private publishing-task directory is unavailable.', 'tikool-memory-page'));
        }

        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $bucket_git_url = (string) get_post_meta($page_id, '_tikool_memory_bucket_git_url', true);
        if (! Settings::is_valid_bucket_git_url($bucket_git_url)) {
            $bucket_git_url = Settings::get('bucket_git_url');
            update_post_meta($page_id, '_tikool_memory_bucket_git_url', $bucket_git_url);
        }
        $bucket = Settings::bucket_from_git_url($bucket_git_url);
        if (! Memory_Pages::is_valid_slug($slug) || $bucket === '') {
            return new WP_Error('tikool_memory_page_storage_invalid', __('The Memory Page directory or bucket repository is invalid.', 'tikool-memory-page'));
        }

        $scenes = self::scenes(true);
        $scene = sanitize_key((string) ($input['scene'] ?? 'cozy-home'));
        if (! isset($scenes[$scene])) {
            return new WP_Error('tikool_memory_scene_invalid', __('Choose a valid Memory Page scene.', 'tikool-memory-page'));
        }
        $title = sanitize_text_field((string) ($input['title'] ?? ''));
        $pet_name = sanitize_text_field((string) ($input['pet_name'] ?? ''));
        $story = sanitize_textarea_field((string) ($input['story'] ?? ''));
        if ($title === '' || $pet_name === '') {
            return new WP_Error('tikool_memory_required_fields', __('Page title and pet name are required.', 'tikool-memory-page'));
        }

        $token = bin2hex(random_bytes(16));
        $bundle = self::bundle_directory($token);
        if ($bundle === '' || ! wp_mkdir_p($bundle . '/uploads')) {
            return new WP_Error('tikool_memory_task_bundle', __('The publishing-task bundle could not be created.', 'tikool-memory-page'));
        }

        $media = get_post_meta($page_id, '_tikool_memory_media', true);
        $media = self::normalize_media(is_array($media) ? $media : []);
        foreach (array_keys(self::KINDS) as $kind) {
            $remove = isset($input['remove_media'][$kind]) && is_array($input['remove_media'][$kind])
                ? array_map(static fn ($path): string => self::normalize_media_path((string) $path), $input['remove_media'][$kind])
                : [];
            $media[$kind] = array_values(array_filter(
                $media[$kind],
                static fn (array $item): bool => ! in_array((string) $item['src'], $remove, true)
            ));

            $uploaded = [];
            if (isset($files[$kind]) && is_array($files[$kind])) {
                $uploaded = $this->stage_uploads($bundle, $kind, $files[$kind]);
                if ($uploaded instanceof WP_Error) {
                    self::delete_bundle($token);
                    return $uploaded;
                }
            }

            $order = isset($input['media_order'][$kind]) && is_array($input['media_order'][$kind])
                ? $input['media_order'][$kind]
                : [];
            $media[$kind] = self::apply_media_order($media[$kind], $uploaded, $order);

            $max_count = match ($kind) {
                'photos' => self::PHOTO_MAX_COUNT,
                'videos' => self::VIDEO_MAX_COUNT,
                'audio'  => self::AUDIO_MAX_COUNT,
            };
            if (count($media[$kind]) > $max_count) {
                self::delete_bundle($token);
                return new WP_Error(
                    'tikool_memory_media_count',
                    sprintf(
                        __('A Memory Page can contain up to %1$d %2$s. Remove existing media before adding more.', 'tikool-memory-page'),
                        $max_count,
                        $kind === 'audio' ? __('audio clips', 'tikool-memory-page') : $kind
                    )
                );
            }
        }

        if (get_post_meta($page_id, '_tikool_memory_bucket_initialized', true) !== 'yes') {
            $legacy = $this->stage_legacy_media($bundle, $slug, $media);
            if ($legacy instanceof WP_Error) {
                self::delete_bundle($token);
                return $legacy;
            }
        }

        $template_path = TIKOOL_MEMORY_PAGE_DIR . 'templates/scenes/' . $scene . '/index.html';
        $template = is_file($template_path) ? file_get_contents($template_path) : false;
        if (! is_string($template) || ! str_contains($template, '__TIKOOL_MEMORY_MANIFEST_JSON__')) {
            self::delete_bundle($token);
            return new WP_Error('tikool_memory_scene_placeholder', __('The scene template is missing its embedded manifest placeholder.', 'tikool-memory-page'));
        }

        $timeline = self::sanitize_timeline($input['timeline'] ?? []);
        $payload = [
            'version'      => 1,
            'pageId'       => $page_id,
            'slug'         => $slug,
            'bucket'       => $bucket,
            'bucketGitUrl' => $bucket_git_url,
            'scene'        => $scene,
            'title'        => $title,
            'pet'          => ['name' => $pet_name],
            'story'        => $story,
            'media'        => $media,
            'timeline'     => $timeline,
            'updatedAt'    => gmdate('c'),
        ];
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)
            || file_put_contents($bundle . '/payload.json', $json . "\n", LOCK_EX) === false
            || file_put_contents($bundle . '/template.html', $template, LOCK_EX) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions
            self::delete_bundle($token);
            return new WP_Error('tikool_memory_task_bundle_write', __('The publishing-task data could not be written.', 'tikool-memory-page'));
        }

        update_post_meta($page_id, '_tikool_memory_bucket_git_url', $bucket_git_url);
        delete_post_meta($page_id, '_tikool_memory_bucket');
        update_post_meta($page_id, '_tikool_memory_scene', $scene);
        update_post_meta($page_id, '_tikool_memory_pet_name', $pet_name);
        update_post_meta($page_id, '_tikool_memory_story', $story);
        update_post_meta($page_id, '_tikool_memory_timeline', $timeline);
        update_post_meta($page_id, '_tikool_memory_media', $media);
        wp_update_post(['ID' => $page_id, 'post_title' => $title]);

        return ['token' => $token, 'media' => $media];
    }

    /** @return array<int, array{src:string,name:string}>|WP_Error */
    private function stage_uploads(string $bundle, string $kind, array $upload)
    {
        $config = self::KINDS[$kind];
        $names = isset($upload['name']) && is_array($upload['name']) ? $upload['name'] : [];
        $stored = [];
        $target_dir = $bundle . '/uploads/' . $kind;
        foreach ($names as $index => $raw_name) {
            $error = (int) ($upload['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                return new WP_Error('tikool_memory_upload_error', sprintf(__('Upload failed for %s (code %d).', 'tikool-memory-page'), sanitize_file_name((string) $raw_name), $error));
            }
            $tmp = (string) ($upload['tmp_name'][$index] ?? '');
            $size = (int) ($upload['size'][$index] ?? 0);
            if ($size < 1 || $size > $config['max']) {
                return new WP_Error('tikool_memory_upload_size', sprintf(__('%1$s exceeds the %2$s limit.', 'tikool-memory-page'), sanitize_file_name((string) $raw_name), size_format($config['max'])));
            }
            $mime = $this->detected_mime($tmp);
            if (! isset($config['mimes'][$mime])) {
                return new WP_Error('tikool_memory_upload_type', sprintf(__('%s is not an allowed media type.', 'tikool-memory-page'), sanitize_file_name((string) $raw_name)));
            }
            if (! is_dir($target_dir) && ! wp_mkdir_p($target_dir)) {
                return new WP_Error('tikool_memory_media_directory', __('A task media directory could not be created.', 'tikool-memory-page'));
            }
            $filename = bin2hex(random_bytes(12)) . '.' . $config['mimes'][$mime];
            if (! is_uploaded_file($tmp) || ! move_uploaded_file($tmp, $target_dir . '/' . $filename)) {
                return new WP_Error('tikool_memory_upload_move', sprintf(__('%s could not be staged.', 'tikool-memory-page'), sanitize_file_name((string) $raw_name)));
            }
            $stored[] = ['src' => $kind . '/' . $filename, 'name' => sanitize_file_name((string) $raw_name)];
        }
        return $stored;
    }

    /** @return true|WP_Error */
    private function stage_legacy_media(string $bundle, string $slug, array $media)
    {
        foreach (array_keys(self::KINDS) as $kind) {
            foreach ($media[$kind] as $item) {
                $relative = (string) ($item['src'] ?? '');
                $source = self::legacy_media_file($slug, $relative);
                if ($source === '') {
                    continue;
                }
                $target = $bundle . '/uploads/' . $relative;
                if (! is_dir(dirname($target)) && ! wp_mkdir_p(dirname($target))) {
                    return new WP_Error('tikool_memory_legacy_directory', __('Legacy media could not be staged for bucket migration.', 'tikool-memory-page'));
                }
                if (! copy($source, $target)) {
                    return new WP_Error('tikool_memory_legacy_copy', __('A legacy media file could not be staged for bucket migration.', 'tikool-memory-page'));
                }
            }
        }
        return true;
    }

    /** @return array<string, array<int, array{src:string,name:string}>> */
    public static function normalize_media(array $media): array
    {
        $normalized = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $normalized[$kind] = [];
            $items = isset($media[$kind]) && is_array($media[$kind]) ? $media[$kind] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $src = self::normalize_media_path((string) ($item['src'] ?? ''));
                if (! str_starts_with($src, $kind . '/') || ! self::is_valid_media_path($src)) {
                    continue;
                }
                $normalized[$kind][] = ['src' => $src, 'name' => sanitize_file_name((string) ($item['name'] ?? basename($src)))];
            }
        }
        return $normalized;
    }

    /**
     * Rebuild one media collection from the customer-visible card order.
     * Unknown or duplicate tokens are ignored, while omitted valid items are
     * appended so a malformed request can never make media disappear.
     *
     * @param array<int, array{src:string,name:string}> $existing
     * @param array<int, array{src:string,name:string}> $uploaded
     * @param array<int, mixed>                         $order
     * @return array<int, array{src:string,name:string}>
     */
    private static function apply_media_order(array $existing, array $uploaded, array $order): array
    {
        $combined = array_merge($existing, $uploaded);
        if ($order === []) {
            return $combined;
        }

        $existing_by_src = [];
        foreach ($existing as $item) {
            $existing_by_src[(string) $item['src']] = $item;
        }

        $ordered = [];
        $used = [];
        foreach (array_slice($order, 0, count($combined) + 10) as $raw_token) {
            $token = sanitize_text_field((string) $raw_token);
            $item = null;
            if (str_starts_with($token, 'existing:')) {
                $src = self::normalize_media_path(substr($token, 9));
                $item = $existing_by_src[$src] ?? null;
            } elseif (preg_match('/^new:(\d+)$/', $token, $match)) {
                $item = $uploaded[(int) $match[1]] ?? null;
            }
            if (! is_array($item)) {
                continue;
            }
            $src = (string) $item['src'];
            if (isset($used[$src])) {
                continue;
            }
            $ordered[] = $item;
            $used[$src] = true;
        }

        foreach ($combined as $item) {
            $src = (string) $item['src'];
            if (! isset($used[$src])) {
                $ordered[] = $item;
                $used[$src] = true;
            }
        }
        return $ordered;
    }

    private static function normalize_media_path(string $path): string
    {
        $path = ltrim($path, '/');
        return str_starts_with($path, 'media/') ? substr($path, 6) : $path;
    }

    private static function is_valid_media_path(string $path): bool
    {
        return (bool) preg_match('#^(?:photos|videos|audio)/[A-Za-z0-9._-]+$#', $path);
    }

    private function detected_mime(string $file): string
    {
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file);
            if (is_string($mime)) {
                return strtolower($mime);
            }
        }
        return function_exists('mime_content_type') ? strtolower((string) mime_content_type($file)) : 'application/octet-stream';
    }

    /** @return array<int, array<string, string>> */
    public static function sanitize_timeline($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $timeline = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = sanitize_text_field((string) ($row['date'] ?? ''));
            $title = sanitize_text_field((string) ($row['title'] ?? ''));
            $text = sanitize_textarea_field((string) ($row['text'] ?? ''));
            if ($date !== '' || $title !== '' || $text !== '') {
                $timeline[] = ['date' => $date, 'title' => $title, 'text' => $text];
            }
        }
        return array_slice($timeline, 0, 100);
    }

    public static function delete_bundle(string $token): void
    {
        $directory = self::bundle_directory($token);
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        rmdir($directory); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }
}
