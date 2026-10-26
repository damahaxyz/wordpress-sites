<?php
/**
 * Plugin Name: Tikool Product Customizer
 * Description: Reusable WooCommerce frame, blanket, and keepsake personalization with live previews, production geometry, and private order artwork.
 * Version: 1.13.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: tikool-frame-customizer
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('TIKOOL_FC_VERSION', '1.13.0');
define('TIKOOL_FC_FILE', __FILE__);
define('TIKOOL_FC_DIR', plugin_dir_path(__FILE__));
define('TIKOOL_FC_URL', plugin_dir_url(__FILE__));

require_once TIKOOL_FC_DIR . 'includes/class-config.php';
require_once TIKOOL_FC_DIR . 'includes/class-admin.php';
require_once TIKOOL_FC_DIR . 'includes/class-ai-jobs.php';
require_once TIKOOL_FC_DIR . 'includes/class-ai-styles.php';
require_once TIKOOL_FC_DIR . 'includes/class-customizer.php';
require_once TIKOOL_FC_DIR . 'includes/class-plugin.php';

register_activation_hook(TIKOOL_FC_FILE, [Tikool\FrameCustomizer\Plugin::class, 'activate']);
add_action('plugins_loaded', static function (): void {
    Tikool\FrameCustomizer\Plugin::instance()->boot();
}, 20);
