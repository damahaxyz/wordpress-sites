<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('trovesia_plugin_version');
