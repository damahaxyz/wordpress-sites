<?php
/**
 * Persistent image-generation audit log.
 */

declare(strict_types=1);

namespace Tikool\AI;

if (! defined('ABSPATH')) {
    exit;
}

final class Generation_Log
{
    public const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'tikool_ai_log_db_version';

    public static function maybe_upgrade(): void
    {
        if ((string) get_option(self::DB_VERSION_OPTION, '') === self::DB_VERSION) {
            return;
        }

        self::install();
    }

    public static function install(): void
    {
        global $wpdb;

        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'failed',
            feature varchar(191) NOT NULL DEFAULT '',
            source varchar(100) NOT NULL DEFAULT '',
            model varchar(191) NOT NULL DEFAULT '',
            size varchar(32) NOT NULL DEFAULT '',
            duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
            http_status smallint(5) unsigned NOT NULL DEFAULT 0,
            request_id varchar(191) NOT NULL DEFAULT '',
            image_url text NULL,
            error_code varchar(191) NOT NULL DEFAULT '',
            error_message text NULL,
            prompt longtext NULL,
            context_json longtext NULL,
            usage_json longtext NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY status (status),
            KEY feature (feature),
            KEY source (source)
        ) {$charset};";

        dbDelta($sql);
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function record(array $entry): int
    {
        global $wpdb;

        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        $usage = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];
        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'created_at' => current_time('mysql', true),
                'status' => self::sanitize_status((string) ($entry['status'] ?? 'failed')),
                'feature' => sanitize_key((string) ($entry['feature'] ?? '')),
                'source' => sanitize_key((string) ($entry['source'] ?? '')),
                'model' => sanitize_text_field((string) ($entry['model'] ?? '')),
                'size' => sanitize_text_field((string) ($entry['size'] ?? '')),
                'duration_ms' => max(0, (int) ($entry['duration_ms'] ?? 0)),
                'http_status' => max(0, min(999, (int) ($entry['http_status'] ?? 0))),
                'request_id' => sanitize_text_field((string) ($entry['request_id'] ?? '')),
                'image_url' => esc_url_raw((string) ($entry['image_url'] ?? ''), ['https']),
                'error_code' => sanitize_key((string) ($entry['error_code'] ?? '')),
                'error_message' => sanitize_text_field((string) ($entry['error_message'] ?? '')),
                'prompt' => sanitize_textarea_field((string) ($entry['prompt'] ?? '')),
                'context_json' => $context === [] ? '' : (string) wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'usage_json' => $usage === [] ? '' : (string) wp_json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Update the lifecycle state of an existing generation request.
     *
     * @param array<string, mixed> $entry
     */
    public static function update(int $id, array $entry): bool
    {
        global $wpdb;

        if ($id < 1) {
            return false;
        }

        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        $usage = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];
        $updated = $wpdb->update(
            self::table_name(),
            [
                'status' => self::sanitize_status((string) ($entry['status'] ?? 'failed')),
                'feature' => sanitize_key((string) ($entry['feature'] ?? '')),
                'source' => sanitize_key((string) ($entry['source'] ?? '')),
                'model' => sanitize_text_field((string) ($entry['model'] ?? '')),
                'size' => sanitize_text_field((string) ($entry['size'] ?? '')),
                'duration_ms' => max(0, (int) ($entry['duration_ms'] ?? 0)),
                'http_status' => max(0, min(999, (int) ($entry['http_status'] ?? 0))),
                'request_id' => sanitize_text_field((string) ($entry['request_id'] ?? '')),
                'image_url' => esc_url_raw((string) ($entry['image_url'] ?? ''), ['https']),
                'error_code' => sanitize_key((string) ($entry['error_code'] ?? '')),
                'error_message' => sanitize_text_field((string) ($entry['error_message'] ?? '')),
                'prompt' => sanitize_textarea_field((string) ($entry['prompt'] ?? '')),
                'context_json' => $context === [] ? '' : (string) wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'usage_json' => $usage === [] ? '' : (string) wp_json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    public static function mark_stale(int $id): void
    {
        global $wpdb;

        if ($id < 1) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table_name() . "
                SET status = 'failed',
                    duration_ms = GREATEST(duration_ms, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) * 1000),
                    error_code = 'tikool_ai_interrupted',
                    error_message = %s
                WHERE id = %d AND status = 'requesting'",
            __('请求被中断或超过最长等待时间。', 'tikool-ai'),
            $id
        ));
    }

    /**
     * @param array{status?: string, feature?: string, per_page?: int, offset?: int} $args
     * @return list<array<string, mixed>>
     */
    public static function query(array $args = []): array
    {
        global $wpdb;

        [$where, $values] = self::where($args);
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $sql = 'SELECT * FROM ' . self::table_name() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $values[] = $per_page;
        $values[] = $offset;
        $prepared = $wpdb->prepare($sql, ...$values);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array{status?: string, feature?: string} $args
     */
    public static function count(array $args = []): int
    {
        global $wpdb;

        [$where, $values] = self::where($args);
        $sql = 'SELECT COUNT(*) FROM ' . self::table_name() . $where;
        if ($values !== []) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'tikool_ai_generations';
    }

    /**
     * @param array{status?: string, feature?: string} $args
     * @return array{0: string, 1: list<string>}
     */
    private static function where(array $args): array
    {
        $clauses = [];
        $values = [];
        $status = sanitize_key((string) ($args['status'] ?? ''));
        if (in_array($status, ['requesting', 'success', 'failed', 'cached'], true)) {
            $clauses[] = 'status = %s';
            $values[] = $status;
        }
        $feature = sanitize_key((string) ($args['feature'] ?? ''));
        if ($feature !== '') {
            $clauses[] = 'feature = %s';
            $values[] = $feature;
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $values];
    }

    private static function sanitize_status(string $status): string
    {
        $status = sanitize_key($status);

        return in_array($status, ['requesting', 'success', 'failed', 'cached'], true) ? $status : 'failed';
    }
}
