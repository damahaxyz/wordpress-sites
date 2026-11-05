<?php
use GroupShop\Manager\{Brands,Stores,Collections,BrandScope,TaxonomyAdmin};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()) {throw new RuntimeException('Private local only');}
$f=json_decode(file_get_contents('/gsm-private/collections-v055.json'),true);$checks=[];$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}$checks[]=$name;};
// The hierarchy cache is global even when a native term save invalidates it inside one store.
delete_option('product_cat_children');$tree=Stores::within(7,static fn()=>_get_term_hierarchy('product_cat'));
$check(in_array($f['child'],$tree[$f['parent']]??[],true)&&in_array($f['sharedchild'],$tree[$f['shared']]??[],true),'Rebuilding hierarchy from B retains A and shared parent-child links');
Stores::within(6,static function()use($f,$check){$terms=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'include'=>[$f['parent'],$f['child']],'orderby'=>'name']);$counts=array_column($terms,'count','term_id');$check((int)$counts[$f['parent']]===1&&(int)$counts[$f['child']]===1,'Collection counts remain independent instead of summing descendant-only products');});
if(!defined('WP_ADMIN')){define('WP_ADMIN',true);}global $pagenow;require_once ABSPATH.'wp-admin/includes/screen.php';set_current_screen('edit-product_cat');$pagenow='edit-tags.php';
foreach([0,6,7] as $scope){$_REQUEST=['taxonomy'=>'product_cat'];$_GET=['gsm_brand'=>$scope];
 Stores::within($scope?:1,static function()use($scope,$check,$f){
  $terms=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'number'=>0]);$count=(int)wp_count_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
  $check(count($terms)===$count,'Native SQL list and count agree for scope '.$scope);
  foreach($terms as $term){$private=(bool)get_term_meta($term->term_id,'_gsm_collection',true);if($private!==($scope>0)||($scope&&(int)get_term_meta($term->term_id,'_gsm_brand_id',true)!==$scope)){throw new RuntimeException('Unexpected native list ownership');}}
  $check(true,'Every native category row has the selected ownership for scope '.$scope);
 });
}
$_GET=['gsm_brand'=>0];$_REQUEST=['taxonomy'=>'product_cat'];
// Shared vocabulary remains available even if a term has metadata but no Woo order key.
$check(get_term_meta($f['shared'],'order',true)===''&&in_array($f['shared'],wp_list_pluck(get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]),'term_id'),true),'Shared terms with no Woo order metadata remain visible');
global $wpdb;$missing=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta} c LEFT JOIN {$wpdb->termmeta} b ON b.term_id=c.term_id AND b.meta_key='_gsm_brand_id' WHERE c.meta_key='_gsm_collection' AND b.meta_id IS NULL");
$check($missing===0,'Existing and newly created collection terms all have explicit brand ownership');
$_REQUEST=['action'=>'woocommerce_term_ordering','thetaxonomy'=>'product_cat','id'=>$f['shared']];$pagenow='admin-ajax.php';TaxonomyAdmin::guard_native();
$ordered=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
$check(!array_filter($ordered,static fn($term)=>get_term_meta($term->term_id,'_gsm_collection',true)),'Native shared drag-order traversal excludes every private collection');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
