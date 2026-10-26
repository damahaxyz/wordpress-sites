<?php
/**
 * Stable procedural API for Tikool customizer plugins.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('tikool_ai_generate_image')) {
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    function tikool_ai_generate_image(array $args)
    {
        return Tikool\AI\Service::instance()->generate_image($args);
    }
}

if (! function_exists('tikool_ai_image_data_url')) {
    /**
     * @return string|WP_Error
     */
    function tikool_ai_image_data_url(string $path)
    {
        return Tikool\AI\Image_Input::from_file($path);
    }
}
