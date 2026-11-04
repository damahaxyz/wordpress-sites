<?php
/**
 * Installation and shared settings.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

namespace Trovesia\Channel;

if (! defined('ABSPATH')) {
    exit;
}

final class Installer
{
    public const WINDOW_OPTION = 'trovesia_channel_attribution_window_days';
    public const PORTAL_PAGE_OPTION = 'trovesia_channel_portal_page_id';

    public static function activate(): void
    {
        Database::install();
        add_option(self::WINDOW_OPTION, 30, '', false);
        self::install_role();
        self::install_portal_page();
        Attribution::add_rewrite_rule();
        flush_rewrite_rules();
        update_option('trovesia_channel_version', TROVESIA_CHANNEL_VERSION, false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function refresh_routes_after_upgrade(): void
    {
        if (get_option('trovesia_channel_version') === TROVESIA_CHANNEL_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option('trovesia_channel_version', TROVESIA_CHANNEL_VERSION, false);
    }

    public static function attribution_window_days(): int
    {
        return max(1, min(365, (int) get_option(self::WINDOW_OPTION, 30)));
    }

    private static function install_role(): void
    {
        $capabilities = [
            'read' => true,
            'view_trovesia_channel_portal' => true,
            'manage_trovesia_channel_links' => true,
        ];

        $role = get_role('trovesia_channel_manager');
        if (! $role) {
            add_role('trovesia_channel_manager', __('Channel Manager', 'trovesia-channel'), $capabilities);
            return;
        }

        foreach ($capabilities as $capability => $grant) {
            if ($grant) {
                $role->add_cap($capability);
            }
        }
    }

    private static function install_portal_page(): void
    {
        $existing_id = (int) get_option(self::PORTAL_PAGE_OPTION, 0);
        if ($existing_id > 0 && get_post($existing_id) instanceof \WP_Post) {
            return;
        }

        $page = get_page_by_path('channel-portal', OBJECT, 'page');
        if ($page instanceof \WP_Post) {
            update_option(self::PORTAL_PAGE_OPTION, $page->ID, false);
            return;
        }

        $page_id = wp_insert_post(
            [
                'post_title' => __('Channel Portal', 'trovesia-channel'),
                'post_name' => 'channel-portal',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => '[trovesia_channel_portal]',
                'comment_status' => 'closed',
            ],
            true
        );

        if (! is_wp_error($page_id)) {
            update_option(self::PORTAL_PAGE_OPTION, (int) $page_id, false);
        }
    }
}
