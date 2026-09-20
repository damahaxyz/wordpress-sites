<?php
/**
 * Plugin Name: Manga Shop Plugin
 * Description: Site-specific functionality and migration tooling for Manga Product.
 * Version: 1.4.3
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Text Domain: manga-shop-plugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('MANGA_SHOP_PLUGIN_VERSION', '1.4.3');
define('MANGA_SHOP_PLUGIN_FILE', __FILE__);
define('MANGA_SHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once MANGA_SHOP_PLUGIN_DIR . 'includes/class-plugin.php';
require_once MANGA_SHOP_PLUGIN_DIR . 'includes/class-settings.php';
require_once MANGA_SHOP_PLUGIN_DIR . 'includes/class-product-details.php';
require_once MANGA_SHOP_PLUGIN_DIR . 'includes/class-seo.php';
require_once MANGA_SHOP_PLUGIN_DIR . 'includes/class-account-experience.php';
require_once MANGA_SHOP_PLUGIN_DIR . 'includes/class-page-cache.php';
require_once MANGA_SHOP_PLUGIN_DIR . 'includes/class-variable-stock.php';

register_activation_hook(
    MANGA_SHOP_PLUGIN_FILE,
    [MangaShop\Plugin\Plugin::class, 'activate']
);

MangaShop\Plugin\Plugin::instance()->boot();
