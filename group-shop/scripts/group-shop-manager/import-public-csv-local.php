<?php
use GroupShop\Manager\{Brands,Stores,Schema};
require '/gsm-scripts/local-catalog-common.php';
$manifest=gsm_catalog_guard('import');$rows=gsm_catalog_rows();
if(!file_exists('/gsm-import/reset-complete.json')||file_exists('/gsm-import/import-complete.json')||count($rows)!==$manifest['expected_rows']){throw new RuntimeException('Completed reset and uncompleted import required');}
foreach($rows as $row){if($row['Type']!=='simple'||!$row['SKU']||!is_numeric($row['Regular price'])){throw new RuntimeException('This explicit import supports the inspected simple-product source only');}}
require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
require_once WC_ABSPATH.'includes/import/class-wc-product-csv-importer.php';
// CSV content and URLs are data, never instructions. All image bytes are staged locally.
add_filter('pre_http_request',static fn()=>new WP_Error('gsm_local_import_offline','This import only uses local staged files.'),PHP_INT_MAX);
$progress=file_exists('/gsm-import/import-progress.json')?json_decode(file_get_contents('/gsm-import/import-progress.json'),true):['attachments'=>[],'products'=>[]];
foreach($manifest['image_files'] as $url=>$file){
    if(!preg_match('~^images/[a-f0-9]+\.(?:jpe?g|png|webp)$~D',$file)||hash_file('sha256','/gsm-import/'.$file)!==$manifest['image_sha256'][$url]){throw new RuntimeException('Staged image integrity failed');}
    if(!empty($progress['attachments'][$url])&&wp_attachment_is_image((int)$progress['attachments'][$url])){continue;}
    $tmp=wp_tempnam(basename($file));copy('/gsm-import/'.$file,$tmp);
    $id=media_handle_sideload(['name'=>basename($file),'tmp_name'=>$tmp],0);
    if(is_wp_error($id)){@unlink($tmp);throw new RuntimeException('Local image upload failed: '.$id->get_error_code());}
    update_post_meta($id,'_wc_attachment_source',$url);update_post_meta($id,'_gsm_import_image_sha256',$manifest['image_sha256'][$url]);
    $progress['attachments'][$url]=(int)$id;gsm_catalog_write('import-progress.json',$progress);
    if(count($progress['attachments'])%12===0){echo 'Local images prepared: '.count($progress['attachments'])."\n";}
}
global $wpdb;
foreach($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product'") as $id){if(get_post_meta($id,'_gsm_import_source_sha256',true)!==$manifest['source_sha256']){throw new RuntimeException('Unrelated products appeared during import window');}$p=wc_get_product($id);$progress['products'][$p->get_sku()]=(int)$id;}
class GSM_Local_Catalog_Columns extends WC_Product_CSV_Importer_Controller { public function columns(array $headers): array { return $this->auto_map_columns($headers,false); } }
class GSM_Local_Catalog_Importer extends WC_Product_CSV_Importer {
    public function omit_completed(array $products): void { $this->parsed_data=array_values(array_filter($this->parsed_data,static fn($row)=>!isset($products[$row['sku']??'']))); }
}
$mapping=(new GSM_Local_Catalog_Columns())->columns(array_keys($rows[0]));
// Source IDs belong to another installation. Preserve as provenance, never reuse them.
$mapping['ID']='meta:_gsm_import_source_id';
add_filter('woocommerce_product_import_pre_insert_product_object',static function($product,$data)use($manifest){$product->update_meta_data('_gsm_brand_id',0);$product->update_meta_data('_gsm_kind','base');$product->update_meta_data('_gsm_base_product',0);$product->update_meta_data('_gsm_requested_brands',[]);$product->update_meta_data('_gsm_import_source_sha256',$manifest['source_sha256']);$product->update_meta_data('_gsm_business_sku',$product->get_sku());return $product;},50,2);
add_action('woocommerce_product_import_inserted_product_object',static function($product)use(&$progress){$progress['products'][$product->get_sku()]=$product->get_id();gsm_catalog_write('import-progress.json',$progress);if(count($progress['products'])%12===0){echo 'Public products imported: '.count($progress['products'])."\n";}},50);
$importer=new GSM_Local_Catalog_Importer('/gsm-import/source.csv',['mapping'=>$mapping,'parse'=>true,'update_existing'=>false,'prevent_timeouts'=>false,'character_encoding'=>'UTF-8']);
$importer->omit_completed($progress['products']);
$result=$importer->import();
if($result['failed']||$result['skipped']){gsm_catalog_write('import-errors.json',['failed'=>array_map(static fn($e)=>$e->get_error_message(),$result['failed']),'skipped'=>array_map(static fn($e)=>$e->get_error_message(),$result['skipped'])]);throw new RuntimeException('Native CSV import reported errors; inspect private import-errors.json');}
if(count($progress['products'])!==count($rows)){throw new RuntimeException('Public import row count mismatch');}
wc_delete_product_transients();wp_cache_flush();Stores::purge();
Schema::audit('local_public_csv_import',1,0,['fields'=>['public_catalog','source_sha256','local_images'],'status'=>'complete']);
$report=['source_sha256'=>$manifest['source_sha256'],'source_rows'=>count($rows),'public_products'=>count($progress['products']),'local_images'=>count($progress['attachments']),'brand_editions_created'=>0,'native_import_failures'=>count($result['failed']),'native_import_skipped'=>count($result['skipped']),'native_import_updated'=>count($result['updated']),'completed_at'=>gmdate('c')];
gsm_catalog_write('import-complete.json',$report);echo wp_json_encode($report,JSON_PRETTY_PRINT)."\n";
gsm_catalog_discard_cli_session();
