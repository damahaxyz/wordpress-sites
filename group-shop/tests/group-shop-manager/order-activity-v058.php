<?php
use GroupShop\Manager\{Brands,Stores};
use Trovesia\Plugin\Site_Experience;
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()){throw new RuntimeException('Private local QA only');}
global $wpdb;$checks=[];$ids=[];$saved=[];$path='/gsm-private/order-activity-v058.json';if(file_exists($path)){throw new RuntimeException('Inspect previous order fixture');}
$before=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}wc_orders ORDER BY id",ARRAY_A);
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}$checks[]=$name;};
$method=new ReflectionMethod(Site_Experience::class,'orders_last_24_hours');$count=static fn($b)=>Stores::within($b,static fn()=>$method->invoke(Site_Experience::instance()));
foreach([6,7] as $b){$key=Stores::within($b,static fn()=>apply_filters('trovesia_order_activity_cache_key','trovesia_orders_last_24_hours'));$saved[$b]=['key'=>$key,'value'=>get_transient($key),'timeout'=>(int)get_option('_transient_timeout_'.$key)];delete_transient($key);}
$persist=static function()use(&$ids,$saved,$path){file_put_contents($path,wp_json_encode(['orders'=>$ids,'cache'=>$saved]));chmod($path,0600);};$persist();
try{
 $base=[6=>$count(6),7=>$count(7)];
 foreach([6,6,7] as $b){$order=Stores::within($b,static function(){$o=wc_create_order(['status'=>'pending']);if(is_wp_error($o)){throw new RuntimeException('Fixture order failed');}return $o;});$ids[]=$order->get_id();$persist();$order->set_status('processing');$order->save();}
 foreach($saved as $row){delete_transient($row['key']);}
 $check($saved[6]['key']!==$saved[7]['key'],'Order activity cache keys differ between stores');
 $check($count(6)===$base[6]+2,'Store A count includes only its own two synthetic orders');
 $check($count(7)===$base[7]+1,'Store B count does not reuse A cache and includes only its own order');
 $check($count(6)===$base[6]+2,'Returning to A preserves its own cached count');
}finally{
 Brands::unscoped(static function()use($ids){foreach($ids as $id){$o=wc_get_order($id);if($o){$o->delete(true);}}});
 foreach($saved as $row){delete_transient($row['key']);if($row['value']!==false&&$row['timeout']>time()){set_transient($row['key'],$row['value'],$row['timeout']-time());}}
 if(WC()->session){remove_action('shutdown',[WC()->session,'save_data'],20);}
}
$check($wpdb->get_results("SELECT * FROM {$wpdb->prefix}wc_orders ORDER BY id",ARRAY_A)===$before,'All synthetic orders removed and original HPOS order rows preserved');unlink($path);
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'synthetic_orders_removed'=>count($ids)],JSON_PRETTY_PRINT)."\n";
