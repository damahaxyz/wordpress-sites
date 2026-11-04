<?php
use GroupShop\Manager\{Stores,Collections,CategoryMigration};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!in_array(wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_PORT),[18086,18087],true)||wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_HOST)!=='localhost') { throw new RuntimeException('Local synthetic QA only'); }
Stores::authorize(); $state=[]; $passed=0;
if (CategoryMigration::run()!==[]) { throw new RuntimeException('Unexpected pending category migration'); }
foreach (array_keys(Stores::all()) as $store) {
    $state[$store]=Stores::within($store,static function () use (&$passed): array {
        global $wpdb;
        $left=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key IN ('_gsm_template_id','_gsm_template_store','_gsm_overrides')");
        if ($left) { throw new RuntimeException('Legacy metadata remains'); } $passed++;
        foreach (Collections::collections() as $spec) { if (($spec['taxonomy_scope']??'')!=='local') { throw new RuntimeException('Nonlocal collection conditions remain'); } } $passed++;
        return ['legacy_metadata'=>$left,'collections_local'=>true,'native_categories'=>count(get_terms(['taxonomy'=>'product_cat','hide_empty'=>false])),'native_tags'=>count(get_terms(['taxonomy'=>'product_tag','hide_empty'=>false]))];
    });
}
global $wpdb;
$tables=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->base_prefix.'gsm_').'%'));
sort($tables); $expected=[$wpdb->base_prefix.'gsm_audit',$wpdb->base_prefix.'gsm_catalog']; sort($expected);
if ($tables!==$expected||get_current_blog_id()!==Stores::main_id()) { throw new RuntimeException('Unexpected plugin tables or leaked store context'); } $passed++;
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>$passed,'stores'=>$state,'plugin_tables'=>$tables],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
