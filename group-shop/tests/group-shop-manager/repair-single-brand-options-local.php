<?php
use GroupShop\Manager\{Brands,Stores};
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||!Brands::active()) { throw new RuntimeException('Migrated local QA only'); }
$map=get_option('gsm_single_migration_map');$bundle=unserialize(file_get_contents('/gsm-private/multisite-export.bin'));global $wpdb;$report=[];
foreach(Stores::all() as $brand=>$_) {
 $table=$wpdb->base_prefix.($brand===1?'':$brand.'_').'options';
 foreach(['wp_page_for_privacy_policy','site_icon'] as $key) {
   $old=(int)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM $table WHERE option_name=%s",$key));
   $new=(int)($map['posts'][$brand][$old]??0);Brands::set_option((int)$brand,$key,$new);$bundle['brands'][$brand]['options'][$key]=$old;
   $report[$brand][$key]=['source'=>$old,'native'=>$new];
 }
}
file_put_contents('/gsm-private/multisite-export.bin',serialize($bundle));chmod('/gsm-private/multisite-export.bin',0600);
echo wp_json_encode(['brand_policy_and_icon_maps'=>$report],JSON_PRETTY_PRINT)."\n";
