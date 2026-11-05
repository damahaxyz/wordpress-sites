<?php
use GroupShop\Manager\{Brands,Stores,Identities,Schema};
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local') {throw new RuntimeException('Migrated private local QA only');}
$x=json_decode(file_get_contents('/gsm-private/single-fixtures.json'),true);$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);$report=['products_removed'=>0,'coupons_removed'=>0,'orders_removed'=>0,'users_removed'=>0,'comments_removed'=>0,'retained_demo_product_records'=>count($x['ids'])];
$map=get_option('gsm_single_migration_map');$cutoff=0;foreach($map['posts'] as $posts){foreach($posts as $id){$cutoff=max($cutoff,(int)$id);$base=(int)get_post_meta($id,'_gsm_base_product',true);if($base){$cutoff=max($cutoff,$base);$bp=wc_get_product($base);foreach($bp&&$bp->is_type('variable')?$bp->get_children():[] as $child){$cutoff=max($cutoff,(int)$child);}}}}
Brands::unscoped(static function()use($x,$f,$cutoff,&$report):void{
 foreach(wc_get_orders(['limit'=>-1]) as $o){if((int)$o->get_meta('_gsm_store_id')!==6||$o->get_customer_id()!==(int)$f['a']||(float)$o->get_total()!==0.0||$o->get_payment_method()!==''){continue;}$items=array_values($o->get_items());if(count($items)===1&&(int)$items[0]->get_product_id()===(int)$x['a']){$o->delete(true);++$report['orders_removed'];}}
 foreach(get_posts(['post_type'=>'shop_coupon','post_status'=>'any','numberposts'=>-1]) as $post){if($post->ID>$cutoff&&str_starts_with($post->post_title,'qa-brand-')){(new WC_Coupon($post->ID))->delete(true);++$report['coupons_removed'];}}
 foreach(get_comments(['post_id'=>(int)$x['a'],'author_email'=>'qa-isolation@example.invalid','fields'=>'ids','status'=>'all']) as $id){wp_delete_comment($id,true);++$report['comments_removed'];}
 foreach(get_posts(['post_type'=>'product','post_status'=>'any','numberposts'=>-1]) as $post){if($post->ID>$cutoff&&!in_array($post->ID,$x['ids'],true)&&preg_match('/^QA (common|Brand A|variable|Native A|scope temporary)/',$post->post_title)){$p=wc_get_product($post->ID);if($p){$report['products_removed']+=1+($p->is_type('variable')?count($p->get_children()):0);$p->delete(true);}}}
 global $wpdb;$rows=$wpdb->get_results('SELECT user_id,login_name FROM '.Schema::table('identities'),ARRAY_A);
 require_once ABSPATH.'wp-admin/includes/user.php';
 foreach($rows as $row){$id=(int)$row['user_id'];$user=get_userdata($id);if(in_array($id,[1,(int)$f['a'],(int)$f['b'],(int)$f['staff']],true)||!preg_match('/^single(?:http|race|employee)[a-z0-9]+$/',$row['login_name'])||!$user||!str_ends_with($user->user_email,'@example.invalid')){continue;}if(wc_get_orders(['customer_id'=>$id,'limit'=>1])){throw new RuntimeException('Disposable user still has an order');}Identities::internal(static fn()=>wp_delete_user($id));++$report['users_removed'];}
 foreach([(int)$f['a'],(int)$f['b'],(int)$f['staff']] as $id){WP_Session_Tokens::get_instance($id)->destroy_all();Identities::internal(static fn()=>wp_update_user(['ID'=>$id,'user_activation_key'=>'']));}
 foreach([(int)$f['a'],(int)$f['b']] as $id){delete_user_meta($id,'_woocommerce_persistent_cart_1');(new WC_Session_Handler())->delete_session((string)$id);}
});
$report['brands']=count(Stores::all());$report['native_multisite']=is_multisite();$report['native_blog_id']=get_current_blog_id();$report['source_record_cutoff']=$cutoff;
foreach($x['ids'] as $id){if(!wc_get_product((int)$id)){throw new RuntimeException('Lost retained demo');}}
wp_cache_flush();Stores::purge();
echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
