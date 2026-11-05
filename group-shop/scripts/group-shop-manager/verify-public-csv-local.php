<?php
use GroupShop\Manager\{Brands,Stores,Schema};
require '/gsm-scripts/local-catalog-common.php';
$manifest=gsm_catalog_guard('verify');$rows=gsm_catalog_rows();$before=json_decode(file_get_contents('/gsm-import/before.json'),true);$progress=json_decode(file_get_contents('/gsm-import/import-progress.json'),true);
if(WC()->session){remove_action('shutdown',[WC()->session,'save_data'],20);}
if(!file_exists('/gsm-import/import-complete.json')){throw new RuntimeException('A complete native import is required');}
global $wpdb;$checks=0;$attributes=0;$fail=[];
$check=static function($ok,$label)use(&$checks,&$fail){++$checks;if(!$ok){$fail[]=$label;}};
$ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' ORDER BY ID");
$check(count($ids)===count($rows),'Exactly the source product count');
foreach($rows as $row){
    $id=(int)($progress['products'][$row['SKU']]??0);$p=$id?wc_get_product($id):null;$key=$row['SKU'];$check((bool)$p,$key.': present');if(!$p){continue;}
    $check($p->get_sku()===$row['SKU']&&$p->get_name()===$row['Name'],$key.': SKU/title');
    $check($p->get_type()==='simple'&&$p->get_status()==='publish',$key.': type/status');
    $check((string)$p->get_regular_price()===wc_format_decimal($row['Regular price']),$key.': regular price');
    $normalize=static fn($s)=>str_replace(["\r\n","\r",'\\n'],["\n","\n","\n"],$s);
    $check($normalize($p->get_description())===$normalize($row['Description']),$key.': full description');
    $check($normalize($p->get_short_description())===$normalize($row['Short description']),$key.': short description');
    $check($p->get_catalog_visibility()===$row['Visibility in catalog']&&$p->get_featured()===($row['Is featured?']==='1'),$key.': catalog presentation');
    $check($p->get_reviews_allowed()===($row['Allow customer reviews?']==='1')&&$p->get_menu_order()===(int)$row['Position'],$key.': reviews/order');
    $check($p->get_meta('_gsm_kind')==='base'&&(int)$p->get_meta('_gsm_brand_id')===0&&!(array)$p->get_meta('_gsm_requested_brands')&&!(int)$p->get_meta('_gsm_base_product'),$key.': public-only owner');
    $check((string)$p->get_meta('_gsm_import_source_id')===$row['ID']&&$p->get_meta('_gsm_import_source_sha256')===$manifest['source_sha256'],$key.': source provenance');
    $image=$p->get_image_id();$path=get_attached_file($image);$check($image>0&&is_file($path)&&hash_file('sha256',$path)===$manifest['image_sha256'][$row['Images']],$key.': exact local image bytes');
    $check(str_starts_with((string)wp_get_attachment_url($image),'http://www.main.local/wp-content/uploads/'),$key.': local media URL');
    $terms=wp_get_object_terms($id,'product_cat');$names=wp_list_pluck($terms,'name');$check(in_array('Chanel',$names,true),$key.': Chanel category');
    $expected=0;$attrs=$p->get_attributes();foreach(range(1,6) as $i){$name=$row['Attribute '.$i.' name'];if($name===''){continue;}++$expected;++$attributes;$attr=$attrs[sanitize_title($name)]??null;$values=array_map('trim',explode(',',$row['Attribute '.$i.' value(s)']));$check($attr&&$attr->get_name()===$name&&!$attr->is_taxonomy()&&$attr->get_options()===$values&&$attr->get_visible()===($row['Attribute '.$i.' visible']==='1'),$key.': '.$name);}
    $check(count($attrs)===$expected,$key.': attribute count');
    foreach($row as $column=>$value){if(str_starts_with($column,'Meta: ')&&$value!==''){$meta=substr($column,6);$check((string)$p->get_meta($meta)===$value,$key.': enrichment metadata');}}
    $check(!$p->get_manage_stock()&&$p->get_stock_status()==='instock',$key.': existing no-stock-management policy');
}
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'")===count($manifest['image_files']),'Only the source images exist');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product_variation','page','post','nav_menu_item','shop_coupon','shop_order','shop_order_refund','shop_order_placehold','revision','trovesia_inquiry')")===0,'Old business posts cleared');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders")===0&&(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}")===0,'Orders and comments cleared');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM ".Schema::table('identities')." WHERE kind='customer'")===0,'Customer identities cleared');
$check((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions")===0,'Old shopping sessions cleared');
$check(Brands::registry()===$before['brands']&&count(Stores::all())===7,'Seven exact brand registrations retained');
$check(gsm_catalog_protected_options()===$before['protected_options'],'Environment and integration settings retained');
$admin_ids=array_map('intval',array_column($before['admin_users'],'ID'));
$check($wpdb->get_results("SELECT * FROM {$wpdb->users} ORDER BY ID",ARRAY_A)===$before['admin_users'],'Only preserved administrator/staff user rows remain unchanged');
$check($wpdb->get_results('SELECT * FROM '.Schema::table('identities').' ORDER BY user_id',ARRAY_A)===$before['staff_identities'],'Employee identity rows retained');
$brand_counts=[];foreach(Stores::all() as $brand=>$_){$brand_counts[$brand]=Stores::within((int)$brand,static fn()=>count(get_posts(['post_type'=>'product','post_status'=>'any','numberposts'=>-1,'fields'=>'ids'])));$check($brand_counts[$brand]===0,'No storefront editions for brand '.$brand);$bank=Brands::raw_options((int)$brand);$check(empty($bank['gsm_collections'])&&empty($bank['nav_menu_options']['auto_add'])&&empty($bank['page_on_front'])&&empty($bank['gsm_logo_id']),'Deleted resource bindings reset for brand '.$brand);$check(($bank['gsm_store_settings']??[])===($before['banks'][$brand]['gsm_store_settings']??[]),'Brand contact/mail configuration retained for '.$brand);}
foreach($wpdb->get_col('SHOW TABLES') as $table){if(preg_match('/^wp_\d+_(posts|postmeta|comments|commentmeta|terms|term_taxonomy|termmeta|term_relationships|wc_orders|wc_orders_meta|woocommerce_sessions)$/D',$table)){$check((int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")===0,'Legacy business table empty: '.$table);}}
$check(!(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ('nav_menu','product_tag')"),'Old menus and product tags cleared');
$chanel=get_term_by('name','Chanel','product_cat');$parent=$chanel?get_term((int)$chanel->parent,'product_cat'):null;$check($chanel&&$parent instanceof WP_Term&&$parent->name==='Designer Fragrances','CSV category hierarchy retained');
$report=['source_sha256'=>$manifest['source_sha256'],'checks'=>$checks,'passed'=>$checks-count($fail),'failures'=>$fail,'public_products'=>count($ids),'local_images'=>count($manifest['image_files']),'local_attribute_rows'=>$attributes,'brand_product_counts'=>$brand_counts,'preserved_admin_accounts'=>count($admin_ids),'original_source_unchanged'=>true];
gsm_catalog_write('verification.json',$report);echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";if($fail){throw new RuntimeException('Import verification failed');}
