<?php
/**
 * Plugin Name: Group Shop Manager
 * Description: Store-isolated customers, shared authorized staff and central administration for WooCommerce with one shared catalog and multiple brand storefronts.
 * Version: 0.5.9
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * Text Domain: group-shop-manager
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
define('GSM_VERSION', '0.5.9');
define('GSM_FILE', __FILE__);
define('GSM_DIR', plugin_dir_path(__FILE__));
foreach (['brands', 'schema', 'stores', 'media', 'availability', 'collections', 'collection-display', 'collection-admin', 'category-migration', 'shared-taxonomies', 'taxonomy-admin', 'identities', 'identity-privacy', 'user-migration', 'brand-scope', 'product-versions', 'product-experience', 'native-products', 'bulk-products', 'native-pages', 'native-menus', 'single-migration', 'customers', 'user-admin', 'orders', 'review-admin', 'mail', 'admin'] as $module) {
    require_once GSM_DIR . 'includes/class-' . $module . '.php';
}
require_once GSM_DIR.'includes/class-seo-isolation.php';
register_activation_hook(__FILE__, [GroupShop\Manager\Schema::class, 'install']);
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', GSM_FILE, true);
    }
});
add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) { return; }
    GroupShop\Manager\Brands::boot();
    if (is_multisite()) {
        if (!defined('WP_CLI')||!WP_CLI) { add_action('init',static fn()=>wp_die('此版本需要先完成单站多品牌迁移。回退请恢复匹配的源码、数据库和配置。','迁移维护',['response'=>503]),0); }
        return;
    }
    if (!GroupShop\Manager\Brands::active()) {
        add_action('admin_notices',static function (): void { if (current_user_can('manage_options')) { echo '<div class="notice notice-warning"><p>多品牌模块尚未初始化。请先按 OPERATIONS 完成显式备份、初始化或迁移；当前不会自动改造原有用户和商品。</p></div>'; } });
        return;
    }
    if (get_site_option('gsm_schema_version') !== GSM_VERSION && GroupShop\Manager\Stores::can_manage()) {
        GroupShop\Manager\Schema::install();
    }
    GroupShop\Manager\Stores::boot();
    GroupShop\Manager\Media::boot();
    GroupShop\Manager\Availability::boot();
    GroupShop\Manager\Collections::boot();
    GroupShop\Manager\CollectionDisplay::boot();
    GroupShop\Manager\CollectionAdmin::boot();
    if (GroupShop\Manager\Brands::active()) { GroupShop\Manager\BrandScope::boot(); GroupShop\Manager\ProductVersions::boot(); GroupShop\Manager\ProductExperience::boot(); GroupShop\Manager\NativeProducts::boot(); GroupShop\Manager\BulkProducts::boot(); GroupShop\Manager\NativePages::boot(); GroupShop\Manager\NativeMenus::boot(); }
    GroupShop\Manager\TaxonomyAdmin::boot();
    GroupShop\Manager\Identities::boot();
    GroupShop\Manager\Customers::boot();
    GroupShop\Manager\IdentityPrivacy::boot();
    GroupShop\Manager\UserAdmin::boot();
    GroupShop\Manager\Orders::boot();
    GroupShop\Manager\ReviewAdmin::boot();
    GroupShop\Manager\Mail::boot();
    GroupShop\Manager\Admin::boot();
    GroupShop\Manager\SeoIsolation::boot();
});
/** Presentation helper; an absent plugin never breaks the existing theme. */
function group_shop_store_setting(string $key, mixed $fallback = ''): mixed {
    return GroupShop\Manager\Stores::settings()[$key] ?? $fallback;
}

/** Current-store public collection data and safe product query arguments for theme modules. */
function group_shop_collection(int $id): ?array { return GroupShop\Manager\CollectionDisplay::data($id); }
function group_shop_collection_query_args(int $id,array $args=[]): array { return GroupShop\Manager\CollectionDisplay::query_args($id,$args); }
