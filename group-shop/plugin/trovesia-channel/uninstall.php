<?php
/**
 * Preserve channel and attribution data unless an administrator explicitly
 * opts in to destructive cleanup before uninstalling the plugin.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('trovesia_channel_version');

if (! defined('TROVESIA_CHANNEL_REMOVE_DATA') || TROVESIA_CHANNEL_REMOVE_DATA !== true) {
    return;
}

global $wpdb;

$tables = [
    'trovesia_channel_audit_log',
    'trovesia_channel_conversions',
    'trovesia_channel_visits',
    'trovesia_channel_users',
    'trovesia_channel_campaigns',
    'trovesia_channel_channels',
];

foreach ($tables as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option('trovesia_channel_db_version');
delete_option('trovesia_channel_attribution_window_days');
delete_option('trovesia_channel_portal_page_id');
remove_role('trovesia_channel_manager');
