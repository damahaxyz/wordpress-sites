<?php
/** Explicit local maintenance only; never loaded by the plugin or a web request. */
use GroupShop\Manager\{Brands,Stores};
function gsm_catalog_guard(string $action): array {
    global $wpdb;
    if(!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||DB_NAME!=='gsm_qa'||$wpdb->prefix!=='wp_'||!Brands::active()||!Brands::root()||get_option('home')!=='http://www.main.local'||getenv('GSM_LOCAL_CATALOG_ACTION')!==$action){throw new RuntimeException('Explicit private local maintenance only');}
    $manifest=json_decode(file_get_contents('/gsm-import/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $verified=json_decode(file_get_contents('/gsm-import/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
    if(empty($verified['verified'])||hash_file('sha256','/gsm-import/source.csv')!==$manifest['source_sha256']||hash_file('sha256','/gsm-import/backup/database.sql')!==$verified['database.sql']['sha256']||$manifest['brand_editions']!==false){throw new RuntimeException('Verified source/backup and public-only authorization required');}
    foreach(Brands::registry() as $brand){if(!str_ends_with($brand['domain'],'.local')||$brand['url']!=='http://'.$brand['domain']){throw new RuntimeException('Only exact local brands allowed');}}
    return $manifest;
}
function gsm_catalog_write(string $file,array $data): void { file_put_contents('/gsm-import/'.$file,wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));chmod('/gsm-import/'.$file,0600); }
function gsm_catalog_query(string $sql): int { global $wpdb;$n=$wpdb->query($sql);if($n===false){throw new RuntimeException('Local maintenance database operation failed');}return $n; }
function gsm_catalog_discard_cli_session(): void {
    if(WC()->session){remove_action('shutdown',[WC()->session,'save_data'],20);WC()->session->destroy_session();}
}
function gsm_catalog_rows(): array {
    $handle=fopen('/gsm-import/source.csv','r');$header=fgetcsv($handle,0,',','"','');$header[0]=ltrim($header[0],"\xEF\xBB\xBF");$rows=[];
    while(($row=fgetcsv($handle,0,',','"',''))!==false){if(count($row)!==count($header)){throw new RuntimeException('CSV column mismatch');}$rows[]=array_combine($header,$row);}fclose($handle);return $rows;
}
function gsm_catalog_protected_options(): array {
    global $wpdb;
    return $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name IN ('active_plugins','template','stylesheet','home','siteurl','permalink_structure','wp_user_roles','gsm_platform_admins','gsm_brands','gsm_brand_sequence','gsm_single_brand_ready','gsm_identity_ready','woocommerce_currency','woocommerce_price_num_decimals','woocommerce_price_decimal_sep','woocommerce_price_thousand_sep','woocommerce_default_country','woocommerce_calc_taxes','woocommerce_prices_include_tax','woocommerce_tax_based_on','woocommerce_manage_stock') OR option_name LIKE '%\\_settings' OR option_name LIKE 'fluentmail%' ORDER BY option_name",ARRAY_A);
}
function gsm_catalog_clear_bindings(array $options): array {
    foreach($options as $key=>$value){
        if(in_array($key,['page_on_front','page_for_posts','wp_page_for_privacy_policy','site_icon','gsm_logo_id','default_product_cat'],true)||preg_match('/^woocommerce_.*_page_id$/',$key)){$options[$key]=0;}
        elseif($key==='show_on_front'){$options[$key]='posts';}
        elseif(str_starts_with($key,'theme_mods_')&&is_array($value)){$value['nav_menu_locations']=[];$value['custom_logo']=0;$options[$key]=gsm_catalog_media_bindings($value);}
        elseif($key==='nav_menu_options'){$options[$key]=['auto_add'=>[]];}
        elseif(in_array($key,['gsm_collections','gsm_collections_review'],true)){$options[$key]=[];}
        elseif($key==='gsm_collections_built_at'){$options[$key]=0;}
        elseif($key==='trovesia_site_announcement_text'){$options[$key]='';}
        elseif($key==='sidebars_widgets'){$options[$key]=['wp_inactive_widgets'=>[],'array_version'=>3];}
    }
    $options['nav_menu_options']=['auto_add'=>[]];$options['gsm_collections']=[];return $options;
}
function gsm_catalog_media_bindings(array $values): array {
    foreach($values as $key=>$value){
        if(is_array($value)){$values[$key]=gsm_catalog_media_bindings($value);}
        elseif(is_string($value)&&str_contains($value,'/wp-content/uploads/')){$values[$key]='';}
        elseif(preg_match('/(?:image|logo|banner|product|page|collection|category)_id$/',(string)$key)||in_array($key,['custom_logo','header_image_data'],true)){$values[$key]=0;}
    }return $values;
}
