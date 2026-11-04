<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
final class Schema {
    public static function table(string $suffix): string {
        global $wpdb;
        if (!in_array($suffix, ['catalog', 'audit', 'identities'], true)) { throw new \InvalidArgumentException('Unknown manager table.'); }
        return $wpdb->base_prefix . 'gsm_' . $suffix;
    }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $catalog = self::table('catalog');
        $audit = self::table('audit');
        dbDelta("CREATE TABLE $catalog (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            master_store bigint(20) unsigned NOT NULL,
            master_product bigint(20) unsigned NOT NULL,
            target_store bigint(20) unsigned NOT NULL,
            target_product bigint(20) unsigned NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_target (master_store,master_product,target_store),
            UNIQUE KEY destination (target_store,target_product)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            store_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            action varchar(64) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            details longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY store_date (store_id,created_at)
        ) ENGINE=InnoDB $charset;");
        $identities = self::table('identities');
        dbDelta("CREATE TABLE $identities (
            user_id bigint(20) unsigned NOT NULL,
            network_id bigint(20) unsigned NOT NULL,
            store_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(12) NOT NULL,
            login_name varchar(60) NOT NULL,
            login_key varchar(60) NOT NULL,
            email_key varchar(100) NOT NULL,
            PRIMARY KEY  (user_id),
            UNIQUE KEY store_login (network_id,store_id,login_key),
            UNIQUE KEY store_email (network_id,store_id,email_key),
            KEY store_kind (network_id,store_id,kind)
        ) ENGINE=InnoDB $charset;");
        update_site_option('gsm_schema_version', GSM_VERSION);
        $main = Stores::main_id();
        Stores::within($main, static function (): void {
            if (!get_option('gsm_store_settings')) {
                update_option('gsm_store_settings', [
                    'uuid' => wp_generate_uuid4(),
                    'support_email' => sanitize_email(get_option('woocommerce_email_from_address', get_option('admin_email'))),
                    'from_email' => sanitize_email(get_option('woocommerce_email_from_address', get_option('admin_email'))),
                    'from_name' => get_bloginfo('name'),
                    'mail_enabled' => true,
                ], false);
            }
        });
    }
    public static function audit(string $action, int $store, int $object = 0, array $details = []): void {
        global $wpdb;
        // Only operational fields. Never serialize mail bodies, addresses, passwords or credentials.
        $allowed = array_flip(['fields', 'before', 'after', 'mode', 'status', 'source_store', 'source_product', 'reason']);
        $wpdb->insert(self::table('audit'), [
            'store_id' => $store, 'actor_id' => get_current_user_id(), 'action' => sanitize_key($action),
            'object_id' => $object, 'details' => wp_json_encode(array_intersect_key($details, $allowed)),
            'created_at' => current_time('mysql', true),
        ]);
    }
}
