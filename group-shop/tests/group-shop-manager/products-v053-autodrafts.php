<?php
use GroupShop\Manager\Brands;
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local') {throw new RuntimeException('Private local QA only');}
$backup='/gsm-private/products-v053-autodrafts.json';$changed=[];
Brands::unscoped(static function()use($backup,&$changed):void{$before=is_file($backup)?json_decode(file_get_contents($backup),true):[];foreach(get_posts(['post_type'=>'product','post_status'=>'auto-draft','numberposts'=>-1]) as $post){$p=wc_get_product($post->ID);if(!$p||(int)$p->get_meta('_gsm_brand_id')===0||$p->get_meta('_gsm_base_product')){continue;}$before[$post->ID]=['status'=>$post->post_status,'meta'=>get_post_meta($post->ID)];file_put_contents($backup,wp_json_encode($before));chmod($backup,0600);$p->update_meta_data('_gsm_brand_id',0);$p->update_meta_data('_gsm_kind','base');$p->update_meta_data('_gsm_overrides',[]);$p->save_meta_data();$changed[]=$post->ID;}});
echo wp_json_encode(['version'=>GSM_VERSION,'unsaved_auto_drafts_reclassified_public'=>count($changed),'saved_products_changed'=>0,'deleted_records'=>0],JSON_PRETTY_PRINT)."\n";
