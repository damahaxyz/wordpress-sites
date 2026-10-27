<?php
/**
 * Plugin Name: Trovesia Channel
 * Description: First-party channel links, WooCommerce order attribution, and a private channel portal.
 * Version: 1.2.2
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * Text Domain: trovesia-channel
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('TROVESIA_CHANNEL_VERSION', '1.2.2');
define('TROVESIA_CHANNEL_FILE', __FILE__);
define('TROVESIA_CHANNEL_DIR', plugin_dir_path(__FILE__));
define('TROVESIA_CHANNEL_URL', plugin_dir_url(__FILE__));

require_once TROVESIA_CHANNEL_DIR . 'includes/class-database.php';
require_once TROVESIA_CHANNEL_DIR . 'includes/class-installer.php';
require_once TROVESIA_CHANNEL_DIR . 'includes/class-attribution.php';
require_once TROVESIA_CHANNEL_DIR . 'includes/class-order-attribution.php';
require_once TROVESIA_CHANNEL_DIR . 'includes/class-admin.php';
require_once TROVESIA_CHANNEL_DIR . 'includes/class-portal.php';
require_once TROVESIA_CHANNEL_DIR . 'includes/class-plugin.php';

add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                TROVESIA_CHANNEL_FILE,
                true
            );
        }
    }
);

register_activation_hook(TROVESIA_CHANNEL_FILE, [Trovesia\Channel\Installer::class, 'activate']);
register_deactivation_hook(TROVESIA_CHANNEL_FILE, [Trovesia\Channel\Installer::class, 'deactivate']);

Trovesia\Channel\Plugin::instance()->boot();
