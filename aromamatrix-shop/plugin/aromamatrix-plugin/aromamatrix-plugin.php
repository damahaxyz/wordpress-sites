<?php
/**
 * Plugin Name: AROMAMATRIX Plugin
 * Plugin URI: https://www.13799.com
 * Description: Site-specific business functionality for AROMAMATRIX.
 * Version: 1.4.8
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Author: AROMAMATRIX
 * Text Domain: aromamatrix-plugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('AROMAMATRIX_PLUGIN_VERSION', '1.4.8');
define('AROMAMATRIX_PLUGIN_FILE', __FILE__);
define('AROMAMATRIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AROMAMATRIX_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AROMAMATRIX_PLUGIN_DIR . 'includes/class-plugin.php';
require_once AROMAMATRIX_PLUGIN_DIR . 'includes/class-account-access.php';
require_once AROMAMATRIX_PLUGIN_DIR . 'includes/class-factory-model.php';
require_once AROMAMATRIX_PLUGIN_DIR . 'includes/class-media-folder-api.php';
require_once AROMAMATRIX_PLUGIN_DIR . 'includes/class-product-note.php';

register_activation_hook(
    AROMAMATRIX_PLUGIN_FILE,
    [Aromamatrix\Plugin\Plugin::class, 'activate']
);

Aromamatrix\Plugin\Plugin::instance()->boot();
