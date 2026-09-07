<?php
/**
 * Plugin Name: Trovesia Shop Plugin
 * Description: Site-specific business functionality for the Trovesia shop.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: trovesia-plugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('TROVESIA_PLUGIN_VERSION', '1.0.0');
define('TROVESIA_PLUGIN_FILE', __FILE__);
define('TROVESIA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TROVESIA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TROVESIA_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(
    TROVESIA_PLUGIN_FILE,
    [Trovesia\Plugin\Plugin::class, 'activate']
);

Trovesia\Plugin\Plugin::instance()->boot();
