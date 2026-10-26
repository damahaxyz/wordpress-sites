<?php
/**
 * Source-image validation and private-file conversion.
 */

declare(strict_types=1);

namespace Tikool\AI;

if (! defined('ABSPATH')) {
    exit;
}

final class Image_Input
{
    private const MAX_FILE_BYTES = 10 * MB_IN_BYTES;
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param mixed $image One URL/data URI, or an array of them.
     * @return string|array<int, string>|\WP_Error
     */
    public static function normalize($image)
    {
        if (is_array($image)) {
            if ($image === [] || count($image) > 4) {
                return new \WP_Error('tikool_ai_invalid_image_count', __('请提供 1–4 张源图片。', 'tikool-ai'));
            }

            $normalized = [];
            foreach ($image as $item) {
                $value = self::normalize_one($item);
                if (is_wp_error($value)) {
                    return $value;
                }
                $normalized[] = $value;
            }

            return $normalized;
        }

        return self::normalize_one($image);
    }

    /**
     * Convert a server-side private image to a supported data URI.
     *
     * @return string|\WP_Error
     */
    public static function from_file(string $path)
    {
        $real_path = realpath($path);
        if ($real_path === false || ! is_file($real_path) || ! is_readable($real_path)) {
            return new \WP_Error('tikool_ai_image_unreadable', __('源图片文件无法读取。', 'tikool-ai'));
        }

        $size = filesize($real_path);
        if ($size === false || $size < 1 || $size > self::MAX_FILE_BYTES) {
            return new \WP_Error('tikool_ai_image_size', __('源图片必须小于 10 MB。', 'tikool-ai'));
        }

        $file_type = wp_check_filetype_and_ext($real_path, basename($real_path));
        $mime = (string) ($file_type['type'] ?? '');
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return new \WP_Error('tikool_ai_image_type', __('源图片仅支持 JPG、PNG 或 WebP。', 'tikool-ai'));
        }

        $contents = file_get_contents($real_path);
        if ($contents === false) {
            return new \WP_Error('tikool_ai_image_unreadable', __('源图片文件无法读取。', 'tikool-ai'));
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    /**
     * @param mixed $image
     * @return string|\WP_Error
     */
    private static function normalize_one($image)
    {
        if (! is_string($image)) {
            return new \WP_Error('tikool_ai_invalid_image', __('源图片格式无效。', 'tikool-ai'));
        }

        $image = trim($image);
        if (preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=\r\n]+$#', $image) === 1) {
            if (strlen($image) > (int) ceil(self::MAX_FILE_BYTES * 1.38) + 128) {
                return new \WP_Error('tikool_ai_image_size', __('源图片必须小于 10 MB。', 'tikool-ai'));
            }
            return $image;
        }

        $url = esc_url_raw($image, ['https']);
        if ($url === '' || ! wp_http_validate_url($url)) {
            return new \WP_Error('tikool_ai_invalid_image_url', __('源图片必须是有效的 HTTPS URL 或图片 data URI。', 'tikool-ai'));
        }

        return $url;
    }
}
