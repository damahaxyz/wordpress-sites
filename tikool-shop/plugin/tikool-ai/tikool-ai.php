<?php
/**
 * Plugin Name: Tikool AI
 * Description: Central AI provider settings, prompt templates, generation records, and server-side image generation for Tikool customizers.
 * Version: 1.7.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: tikool-ai
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('TIKOOL_AI_VERSION', '1.7.0');
define('TIKOOL_AI_FILE', __FILE__);
define('TIKOOL_AI_DIR', plugin_dir_path(__FILE__));
define('TIKOOL_AI_URL', plugin_dir_url(__FILE__));

require_once TIKOOL_AI_DIR . 'includes/class-settings.php';
require_once TIKOOL_AI_DIR . 'includes/class-prompts.php';
require_once TIKOOL_AI_DIR . 'includes/class-image-input.php';
require_once TIKOOL_AI_DIR . 'includes/class-generation-log.php';
require_once TIKOOL_AI_DIR . 'includes/class-client.php';
require_once TIKOOL_AI_DIR . 'includes/class-service.php';
require_once TIKOOL_AI_DIR . 'includes/class-admin.php';
require_once TIKOOL_AI_DIR . 'includes/class-plugin.php';
require_once TIKOOL_AI_DIR . 'includes/functions.php';

register_activation_hook(TIKOOL_AI_FILE, [Tikool\AI\Plugin::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    Tikool\AI\Plugin::instance()->boot();
}, 10);
