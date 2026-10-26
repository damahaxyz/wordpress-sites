<?php
/**
 * Plugin Name: Tikool Memory Page
 * Description: Creates NFC-ready pet memory pages from paid WooCommerce orders and publishes customer media to GitHub.
 * Version: 2.0.1
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * Text Domain: tikool-memory-page
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('TIKOOL_MEMORY_PAGE_VERSION', '2.0.1');
define('TIKOOL_MEMORY_PAGE_FILE', __FILE__);
define('TIKOOL_MEMORY_PAGE_DIR', plugin_dir_path(__FILE__));
define('TIKOOL_MEMORY_PAGE_URL', plugin_dir_url(__FILE__));

require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-settings.php';
require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-git-publisher.php';
require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-storage.php';
require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-tasks.php';
require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-memory-pages.php';
require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-claims.php';
require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-account.php';
require_once TIKOOL_MEMORY_PAGE_DIR . 'includes/class-plugin.php';

register_activation_hook(TIKOOL_MEMORY_PAGE_FILE, [Tikool\MemoryPage\Plugin::class, 'activate']);
register_deactivation_hook(TIKOOL_MEMORY_PAGE_FILE, [Tikool\MemoryPage\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Tikool\MemoryPage\Plugin::instance()->boot();
}, 30);
