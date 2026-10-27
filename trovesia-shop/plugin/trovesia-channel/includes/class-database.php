<?php
/**
 * Database access helpers.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

namespace Trovesia\Channel;

if (! defined('ABSPATH')) {
    exit;
}

final class Database
{
    public const VERSION = '1.0.0';

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'trovesia_channel_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $channels = self::table('channels');
        $campaigns = self::table('campaigns');
        $users = self::table('users');
        $visits = self::table('visits');
        $conversions = self::table('conversions');
        $audit = self::table('audit_log');

        dbDelta("CREATE TABLE {$channels} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            code varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            UNIQUE KEY code (code),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$campaigns} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            channel_id bigint(20) unsigned NOT NULL,
            token varchar(64) NOT NULL,
            name varchar(190) NOT NULL DEFAULT '',
            target_type varchar(32) NOT NULL,
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY channel_status (channel_id,status),
            KEY target (target_type,target_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$users} (
            user_id bigint(20) unsigned NOT NULL,
            channel_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (user_id),
            KEY channel_id (channel_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$visits} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visit_token char(64) NOT NULL,
            visitor_token char(64) NOT NULL,
            channel_id bigint(20) unsigned NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            landing_url text NOT NULL,
            referrer_host varchar(255) NOT NULL DEFAULT '',
            device varchar(20) NOT NULL DEFAULT 'unknown',
            is_bot tinyint(1) unsigned NOT NULL DEFAULT 0,
            clicked_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY visit_token (visit_token),
            KEY visitor_clicked (visitor_token,clicked_at),
            KEY channel_clicked (channel_id,clicked_at),
            KEY campaign_clicked (campaign_id,clicked_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$conversions} (
            order_id bigint(20) unsigned NOT NULL,
            channel_id bigint(20) unsigned NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            visit_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL,
            currency char(3) NOT NULL DEFAULT '',
            order_total decimal(19,4) NOT NULL DEFAULT 0,
            refund_total decimal(19,4) NOT NULL DEFAULT 0,
            paid_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (order_id),
            KEY channel_created (channel_id,created_at),
            KEY campaign_created (campaign_id,created_at),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(64) NOT NULL,
            object_type varchar(32) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            details longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type,object_id),
            KEY created_at (created_at)
        ) {$charset};");

        update_option('trovesia_channel_db_version', self::VERSION, false);
    }

    /** @return object|null */
    public static function get_channel(int $channel_id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table('channels') . ' WHERE id = %d', $channel_id)
        );

        return is_object($row) ? $row : null;
    }

    /** @return object|null */
    public static function get_user_channel(int $user_id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT c.* FROM ' . self::table('channels') . ' c INNER JOIN ' . self::table('users') . ' u ON u.channel_id = c.id WHERE u.user_id = %d LIMIT 1',
                $user_id
            )
        );

        return is_object($row) ? $row : null;
    }

    /** @return list<object> */
    public static function get_channels(bool $active_only = false): array
    {
        global $wpdb;

        $sql = 'SELECT * FROM ' . self::table('channels');
        if ($active_only) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= ' ORDER BY name ASC';

        $rows = $wpdb->get_results($sql);

        return is_array($rows) ? $rows : [];
    }

    /** @return object|null */
    public static function get_campaign_by_token(string $token): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT p.*, c.name AS channel_name, c.code AS channel_code, c.status AS channel_status FROM ' . self::table('campaigns') . ' p INNER JOIN ' . self::table('channels') . ' c ON c.id = p.channel_id WHERE p.token = %s LIMIT 1',
                $token
            )
        );

        return is_object($row) ? $row : null;
    }

    /** @return object|null */
    public static function get_current_visit(string $visit_token): ?object
    {
        global $wpdb;

        $window_days = Installer::attribution_window_days();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * $window_days));
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT v.*, c.code AS channel_code, c.status AS channel_status, p.status AS campaign_status FROM ' . self::table('visits') . ' v INNER JOIN ' . self::table('channels') . ' c ON c.id = v.channel_id INNER JOIN ' . self::table('campaigns') . ' p ON p.id = v.campaign_id WHERE v.visit_token = %s AND v.clicked_at >= %s LIMIT 1',
                $visit_token,
                $cutoff
            )
        );

        if (! is_object($row) || 'active' !== $row->channel_status || 'active' !== $row->campaign_status || (int) $row->is_bot === 1) {
            return null;
        }

        return $row;
    }

    public static function audit(string $action, string $object_type, int $object_id, array $details = []): void
    {
        global $wpdb;

        $wpdb->insert(
            self::table('audit_log'),
            [
                'actor_user_id' => get_current_user_id(),
                'action' => sanitize_key($action),
                'object_type' => sanitize_key($object_type),
                'object_id' => $object_id,
                'details' => $details === [] ? null : wp_json_encode($details),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );
    }
}
