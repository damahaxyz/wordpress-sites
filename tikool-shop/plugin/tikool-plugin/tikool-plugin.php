<?php
/**
 * Plugin Name: Tikool Shop Plugin
 * Description: Site-specific business functionality for the Tikool shop.
 * Version: 3.7.2
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: tikool-plugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('TIKOOL_PLUGIN_VERSION', '3.7.2');
define('TIKOOL_PLUGIN_FILE', __FILE__);
define('TIKOOL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TIKOOL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TIKOOL_PLUGIN_DIR . 'includes/class-plugin.php';
require_once TIKOOL_PLUGIN_DIR . 'includes/class-site-experience.php';
require_once TIKOOL_PLUGIN_DIR . 'includes/class-account-experience.php';
require_once TIKOOL_PLUGIN_DIR . 'includes/class-email-experience.php';
require_once TIKOOL_PLUGIN_DIR . 'includes/class-page-cache.php';
require_once TIKOOL_PLUGIN_DIR . 'includes/class-review-experience.php';
require_once TIKOOL_PLUGIN_DIR . 'includes/class-addon-pricing.php';

register_activation_hook(
    TIKOOL_PLUGIN_FILE,
    [Tikool\Plugin\Plugin::class, 'activate']
);

Tikool\Plugin\Plugin::instance()->boot();
