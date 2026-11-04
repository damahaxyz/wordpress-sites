<?php
/**
 * Plugin Name: Group Shop Manager
 * Description: Store-isolated customers, shared authorized staff and central administration for WooCommerce Multisite.
 * Version: 0.4.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * Network: true
 * Text Domain: group-shop-manager
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
define('GSM_VERSION', '0.4.0');
define('GSM_FILE', __FILE__);
define('GSM_DIR', plugin_dir_path(__FILE__));
foreach (['schema', 'stores', 'media', 'catalog', 'availability', 'collections', 'collection-display', 'collection-admin', 'category-migration', 'shared-taxonomies', 'taxonomy-admin', 'identities', 'identity-privacy', 'user-migration', 'customers', 'user-admin', 'orders', 'review-admin', 'mail', 'product-admin', 'admin'] as $module) {
    require_once GSM_DIR . 'includes/class-' . $module . '.php';
}
register_activation_hook(__FILE__, [GroupShop\Manager\Schema::class, 'install']);
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', GSM_FILE, true);
    }
});
add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) { return; }
    if (get_site_option('gsm_schema_version') !== GSM_VERSION && GroupShop\Manager\Stores::can_manage()) {
        GroupShop\Manager\Schema::install();
    }
    GroupShop\Manager\Stores::boot();
    GroupShop\Manager\Media::boot();
    GroupShop\Manager\Availability::boot();
    GroupShop\Manager\Collections::boot();
    GroupShop\Manager\CollectionDisplay::boot();
    GroupShop\Manager\CollectionAdmin::boot();
    GroupShop\Manager\CategoryMigration::boot();
    GroupShop\Manager\SharedTaxonomies::boot();
    GroupShop\Manager\TaxonomyAdmin::boot();
    GroupShop\Manager\Identities::boot();
    GroupShop\Manager\Customers::boot();
    GroupShop\Manager\IdentityPrivacy::boot();
    GroupShop\Manager\UserAdmin::boot();
    GroupShop\Manager\Orders::boot();
    GroupShop\Manager\ReviewAdmin::boot();
    GroupShop\Manager\Mail::boot();
    GroupShop\Manager\Admin::boot();
});
/** Presentation helper; an absent plugin never breaks the existing theme. */
function group_shop_store_setting(string $key, mixed $fallback = ''): mixed {
    return GroupShop\Manager\Stores::settings()[$key] ?? $fallback;
}

/** Current-store public collection data and safe product query arguments for theme modules. */
function group_shop_collection(int $id): ?array { return GroupShop\Manager\CollectionDisplay::data($id); }
function group_shop_collection_query_args(int $id,array $args=[]): array { return GroupShop\Manager\CollectionDisplay::query_args($id,$args); }
