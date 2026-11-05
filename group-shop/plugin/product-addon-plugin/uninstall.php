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
delete_option('trovesia_checkout_delivery_title');
delete_option('trovesia_checkout_delivery_description');
delete_option('trovesia_policy_pages_version');
delete_option('trovesia_page_cache_generation');
delete_option('trovesia_page_cache_last_purged_at');
delete_option('trovesia_page_cache_last_reason');
