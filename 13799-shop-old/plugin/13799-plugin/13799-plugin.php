<?php
/**
 * Plugin Name: 13799 Shop Plugin
 * Description: Site-specific business functionality for the 13799 shop.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: shop-13799-plugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('SHOP13799_PLUGIN_VERSION', '1.0.0');
define('SHOP13799_PLUGIN_FILE', __FILE__);
define('SHOP13799_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOP13799_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SHOP13799_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(
    SHOP13799_PLUGIN_FILE,
    [Shop13799\Plugin\Plugin::class, 'activate']
);

Shop13799\Plugin\Plugin::instance()->boot();
