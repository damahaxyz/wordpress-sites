<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package TikoolShopPlugin
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('tikool_plugin_version');
delete_option('tikool_checkout_delivery_title');
delete_option('tikool_checkout_delivery_description');
delete_option('tikool_policy_pages_version');
delete_option('tikool_page_cache_generation');
delete_option('tikool_page_cache_last_purged_at');
delete_option('tikool_page_cache_last_reason');
