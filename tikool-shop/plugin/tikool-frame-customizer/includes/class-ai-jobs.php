<?php
/**
 * Persistent AI portrait jobs used by the asynchronous storefront workflow.
 */

declare(strict_types=1);

namespace Tikool\FrameCustomizer;

if (! defined('ABSPATH')) {
    exit;
}

final class AI_Jobs
{
    private const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'tikool_fc_ai_jobs_db_version';

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            owner_hash char(64) NOT NULL,
            fingerprint char(64) NOT NULL,
            dedupe_key char(64) NOT NULL,
            public_token char(48) NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            style_key varchar(100) NOT NULL DEFAULT '',
            feature varchar(191) NOT NULL DEFAULT '',
            rate_hash char(32) NOT NULL DEFAULT '',
            source_path text NOT NULL,
            result_path text NOT NULL,
            result_mime varchar(50) NOT NULL DEFAULT '',
            error_code varchar(191) NOT NULL DEFAULT '',
            error_message text NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            UNIQUE KEY public_token (public_token),
            KEY owner_status (owner_hash,status),
            KEY updated_at (updated_at)
        ) {$charset};";
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            self::install();
        }
    }

    /** @return array<string, mixed>|null */
    public static function find_by_dedupe(string $dedupe_key): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE dedupe_key = %s LIMIT 1', $dedupe_key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public static function find_by_token(string $token): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE public_token = %s LIMIT 1', $token), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public static function find_active_for_owner(string $owner_hash): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE owner_hash = %s AND status IN ('queued','requesting') ORDER BY id DESC LIMIT 1",
                $owner_hash
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, scalar> $data
     */
    public static function create(array $data): int
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(self::table(), [
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'queued',
            'owner_hash' => (string) ($data['owner_hash'] ?? ''),
            'fingerprint' => (string) ($data['fingerprint'] ?? ''),
            'dedupe_key' => (string) ($data['dedupe_key'] ?? ''),
            'public_token' => (string) ($data['public_token'] ?? ''),
            'product_id' => (int) ($data['product_id'] ?? 0),
            'variation_id' => (int) ($data['variation_id'] ?? 0),
            'style_key' => (string) ($data['style_key'] ?? ''),
            'feature' => (string) ($data['feature'] ?? ''),
            'rate_hash' => (string) ($data['rate_hash'] ?? ''),
            'source_path' => (string) ($data['source_path'] ?? ''),
            'result_path' => '',
            'result_mime' => '',
            'error_code' => '',
            'error_message' => '',
        ]);
        return $inserted === 1 ? (int) $wpdb->insert_id : 0;
    }

    public static function requeue(int $id, string $source_path, string $rate_hash): bool
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'queued', updated_at = %s, source_path = %s, result_path = '', result_mime = '', error_code = '', error_message = '', rate_hash = %s WHERE id = %d AND status IN ('failed','success')",
            current_time('mysql', true),
            $source_path,
            $rate_hash,
            $id
        ));
        return $updated === 1;
    }

    public static function claim(int $id): bool
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'requesting', updated_at = %s WHERE id = %d AND status = 'queued'",
            current_time('mysql', true),
            $id
        ));
        return $updated === 1;
    }

    public static function succeed(int $id, string $path, string $mime): bool
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'success', updated_at = %s, source_path = '', result_path = %s, result_mime = %s, error_code = '', error_message = '' WHERE id = %d AND status = 'requesting'",
            current_time('mysql', true),
            $path,
            $mime,
            $id
        ));
        return $updated === 1;
    }

    public static function fail(int $id, string $code, string $message): bool
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'failed', updated_at = %s, source_path = '', error_code = %s, error_message = %s WHERE id = %d AND status IN ('queued','requesting')",
            current_time('mysql', true),
            sanitize_key($code),
            sanitize_text_field($message),
            $id
        ));
        return $updated === 1;
    }

    /** @return array<int, array<string, mixed>> */
    public static function stale(int $older_than_seconds): array
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(60, $older_than_seconds));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE status IN ('queued','requesting') AND updated_at < %s",
                $cutoff
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tikool_fc_ai_jobs';
    }
}
