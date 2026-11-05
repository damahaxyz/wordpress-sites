<?php
use GroupShop\Manager\{Brands,Stores,Identities,Schema};
require '/gsm-scripts/local-catalog-common.php';
$manifest=gsm_catalog_guard('reset');
if(file_exists('/gsm-import/reset-started.json')||file_exists('/gsm-import/reset-complete.json')){throw new RuntimeException('This private reset has already started; inspect/restore, never silently repeat');}
global $wpdb;
$staff=array_map('intval',$wpdb->get_col("SELECT user_id FROM ".Schema::table('identities')." WHERE kind='staff'"));$keep=array_values(array_unique(array_merge($staff,array_map('intval',(array)get_option('gsm_platform_admins')))));
if(!in_array(get_current_user_id(),$keep,true)||!$keep){throw new RuntimeException('Administrator preservation preflight failed');}
$before=['brands'=>Brands::registry(),'banks'=>[],'protected_options'=>gsm_catalog_protected_options(),'admin_users'=>$wpdb->get_results("SELECT * FROM {$wpdb->users} WHERE ID IN (".implode(',',$keep).') ORDER BY ID',ARRAY_A),'staff_identities'=>$wpdb->get_results('SELECT * FROM '.Schema::table('identities')." WHERE user_id IN (".implode(',',$keep).') ORDER BY user_id',ARRAY_A),'tables'=>[]];
foreach(Stores::all() as $brand=>$_){$before['banks'][$brand]=Brands::raw_options((int)$brand);}
foreach($wpdb->get_col('SHOW TABLES') as $table){$before['tables'][$table]=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`");}
gsm_catalog_write('before.json',$before);gsm_catalog_write('reset-started.json',['source_sha256'=>$manifest['source_sha256'],'started_at'=>gmdate('c')]);
$report=['orders_removed'=>0,'product_records_removed'=>0,'other_posts_removed'=>0,'customers_removed'=>0,'terms_removed'=>0,'data_rows_cleared'=>[]];
Brands::unscoped(static function()use($wpdb,$keep,&$report):void{
    // Empty collection rules before deletion hooks can rebuild obsolete memberships.
    foreach(Stores::all() as $brand=>$_){$bank=gsm_catalog_clear_bindings(Brands::raw_options((int)$brand));$bank['gsm_collections_review']=[];update_option('gsm_brand_options_'.(int)$brand,$bank,false);}
    foreach($wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders ORDER BY id DESC") as $id){$order=wc_get_order((int)$id);if(!$order){throw new RuntimeException('Unrecognized active HPOS order');}$order->delete(true);++$report['orders_removed'];}
    foreach($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product_variation','product') ORDER BY post_type DESC,ID DESC") as $id){if(!get_post($id)){continue;}$product=wc_get_product((int)$id);if($product){$product->delete(true);}else{wp_delete_post((int)$id,true);}++$report['product_records_removed'];}
    $preserve=['wp_global_styles','wp_template','wp_template_part','custom_css'];
    foreach($wpdb->get_results("SELECT ID,post_type FROM {$wpdb->posts} ORDER BY ID DESC") as $post){if(in_array($post->post_type,$preserve,true)){continue;}if($post->post_type==='attachment'){wp_delete_attachment((int)$post->ID,true);}else{wp_delete_post((int)$post->ID,true);}++$report['other_posts_removed'];}
    foreach($wpdb->get_col("SELECT comment_ID FROM {$wpdb->comments}") as $id){wp_delete_comment((int)$id,true);}
    require_once ABSPATH.'wp-admin/includes/user.php';
    foreach($wpdb->get_col("SELECT ID FROM {$wpdb->users}") as $id){if(!in_array((int)$id,$keep,true)){if(!Identities::internal(static fn()=>wp_delete_user((int)$id))){throw new RuntimeException('Customer deletion failed');}++$report['customers_removed'];}}
    update_option('default_category',0);update_option('default_product_cat',0);
    foreach($wpdb->get_results("SELECT term_id,taxonomy FROM {$wpdb->term_taxonomy} ORDER BY term_id DESC") as $term){if(in_array($term->taxonomy,['product_type','product_visibility','wp_theme'],true)){continue;}$deleted=wp_delete_term((int)$term->term_id,$term->taxonomy);if(is_wp_error($deleted)||!$deleted){throw new RuntimeException('Catalog term deletion failed');}++$report['terms_removed'];}
    // Legacy subsite tables are inactive archives. Keep structures/configuration,
    // clear their business rows explicitly without switching native blog tables.
    foreach($wpdb->get_col('SHOW TABLES') as $table){
        if(!preg_match('/^wp_(?:(\d+)_)?(.+)$/D',$table,$m)){throw new RuntimeException('Unexpected local table prefix');}$legacy=$m[1]!=='';$suffix=$m[2];
        $archive=$legacy&&in_array($suffix,['posts','postmeta','comments','commentmeta','links','term_relationships','term_taxonomy','termmeta','terms'],true);
        $data=in_array($suffix,['gsm_catalog','gsm_audit','links','fsmpt_email_logs','registration_log','signups','woocommerce_attribute_taxonomies','woocommerce_order_items','woocommerce_order_itemmeta','woocommerce_payment_tokens','woocommerce_payment_tokenmeta','woocommerce_downloadable_product_permissions','woocommerce_sessions','woocommerce_log','actionscheduler_actions','actionscheduler_claims','actionscheduler_groups','actionscheduler_logs'],true)
            ||(bool)preg_match('/^wc_(?:orders|orders_meta|order_.*|customer_lookup|download_log|email_unsubscribes|category_lookup|product_meta_lookup|product_attributes_lookup|reserved_stock|rate_limits|admin_notes|admin_note_actions)$/D',$suffix);
        if($archive||$data){$report['data_rows_cleared'][$table]=gsm_catalog_query("DELETE FROM `$table`");}
        if($suffix==='options'){
            $rows=$wpdb->get_results("SELECT option_name,option_value FROM `$table`",ARRAY_A);$values=[];foreach($rows as $row){$values[$row['option_name']]=maybe_unserialize($row['option_value']);}
            $clean=gsm_catalog_clear_bindings($values);
            foreach($clean as $key=>$value){if(!array_key_exists($key,$values)||$value!==$values[$key]){if($wpdb->replace($table,['option_name'=>$key,'option_value'=>maybe_serialize($value),'autoload'=>'no'])===false){throw new RuntimeException('Option binding cleanup failed');}}}
            gsm_catalog_query("DELETE FROM `$table` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name LIKE 'gsm_bulk_%' OR option_name IN ('gsm_single_migration_map','gsm_single_migration_report','gsm_identity_migration_sources','cron')");
            if(!$legacy){foreach(array_keys($values) as $key){if(str_starts_with($key,'widget_')){update_option($key,['_multiwidget'=>1],false);}}}
        }
    }
    gsm_catalog_query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'billing\\_%' OR meta_key LIKE 'shipping\\_%' OR meta_key LIKE '\\_woocommerce_persistent_cart%' OR meta_key LIKE 'woocommerce_persistent_cart%'");
    gsm_catalog_query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.ID IS NULL");
    gsm_catalog_query("DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID=cm.comment_id WHERE c.comment_ID IS NULL");
    $default=wp_insert_term('Uncategorized','category',['slug'=>'uncategorized']);if(is_wp_error($default)){throw new RuntimeException('Default post category failed');}update_option('default_category',(int)$default['term_id']);
    $default=wp_insert_term('Uncategorized','product_cat',['slug'=>'uncategorized']);if(is_wp_error($default)){throw new RuntimeException('Default product category failed');}update_option('default_product_cat',(int)$default['term_id']);
    foreach(Stores::all() as $brand=>$_){Brands::set_option((int)$brand,'default_product_cat',(int)$default['term_id']);}
});
// Remove remaining media/cache files, preserving upload access-control files.
$uploads=wp_upload_dir()['basedir'];if($uploads!==ABSPATH.'wp-content/uploads'){throw new RuntimeException('Unexpected upload root');}
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator as $file){if($file->isLink()){unlink($file->getPathname());}elseif($file->isDir()){@rmdir($file->getPathname());}elseif(!in_array($file->getFilename(),['.htaccess','index.php','web.config'],true)){if(!unlink($file->getPathname())){throw new RuntimeException('Old upload cleanup failed');}}}
wp_cache_flush();delete_option('category_children');delete_option('product_cat_children');
if(gsm_catalog_protected_options()!==$before['protected_options']||Brands::registry()!==$before['brands']){throw new RuntimeException('Protected environment options changed');}
$admins=$wpdb->get_results("SELECT * FROM {$wpdb->users} WHERE ID IN (".implode(',',$keep).') ORDER BY ID',ARRAY_A);if($admins!==$before['admin_users']){throw new RuntimeException('Preserved administrator records changed');}
$report['preserved_admin_accounts']=count($keep);$report['brands']=count(Brands::registry());$report['remaining_public_or_brand_products']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')");
if($report['remaining_public_or_brand_products']!==0){throw new RuntimeException('Products remain after reset');}
gsm_catalog_write('reset-complete.json',$report);echo wp_json_encode($report,JSON_PRETTY_PRINT)."\n";
gsm_catalog_discard_cli_session();
