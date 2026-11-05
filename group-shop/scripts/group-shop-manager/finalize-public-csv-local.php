<?php
require '/gsm-scripts/local-catalog-common.php';
gsm_catalog_guard('finalize');
if(!file_exists('/gsm-import/import-complete.json')){throw new RuntimeException('Complete import required');}
global $wpdb;
$old=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions");
gsm_catalog_discard_cli_session();gsm_catalog_query("DELETE FROM {$wpdb->prefix}woocommerce_sessions");wp_cache_flush();
gsm_catalog_write('finalize.json',['maintenance_sessions_cleared'=>$old,'remaining_sessions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions")]);
echo "Maintenance shopping sessions cleared.\n";
