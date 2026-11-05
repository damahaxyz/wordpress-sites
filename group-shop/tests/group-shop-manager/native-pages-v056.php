<?php
use GroupShop\Manager\{Brands,Stores,NativePages};
if(!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local'||!Brands::root()){throw new RuntimeException('Private local QA only');}
$path='/gsm-private/native-pages-v056.json';if(file_exists($path)){throw new RuntimeException('Clean previous QA056 pages first');}global $wpdb;
$f=['suffix'=>bin2hex(random_bytes(3)),'ids'=>[],'before'=>$wpdb->get_results("SELECT ID,post_title,post_content,post_excerpt,post_status,post_parent,post_name,post_author FROM {$wpdb->posts} WHERE post_type='page' ORDER BY ID",ARRAY_A),'brands'=>Brands::registry(),'banks'=>[]];foreach(Stores::all() as $id=>$_){$f['banks'][$id]=Brands::raw_options((int)$id);}
$persist=static function()use(&$f,$path){file_put_contents($path,wp_json_encode($f));chmod($path,0600);};register_shutdown_function($persist);$persist();$root=get_current_user_id();$checks=[];
$check=static function($ok,$name)use(&$checks){if(!$ok){throw new RuntimeException('FAIL: '.$name);}$checks[]=$name;};
$make=static function($brand,$key,$parent=0)use(&$f,$persist){$_REQUEST['gsm_brand']=$brand;$id=wp_insert_post(['post_type'=>'page','post_title'=>'QA056 '.$key.' '.$f['suffix'],'post_content'=>'QA056 '.$key.' initial','post_status'=>'draft','post_parent'=>$parent],true);if(is_wp_error($id)||!$id){throw new RuntimeException('Fixture page creation failed');}$f[$key]=$id;$f['ids'][]=$id;$persist();return $id;};
$main=$make(1,'main');$a=$make(6,'a');$child=$make(6,'child',$a);$b=$make(7,'b');
$check(NativePages::owner($main)===1&&NativePages::owner($a)===6&&NativePages::owner($b)===7,'New native pages receive their selected store ownership');
$check((int)get_post($child)->post_parent===$a,'Same-store native page hierarchy remains supported');
$_REQUEST['gsm_brand']=6;wp_update_post(['ID'=>$a,'post_content'=>'QA056 A independently edited','post_status'=>'publish']);
$check(get_post_field('post_content',$a)==='QA056 A independently edited'&&get_post_field('post_content',$b)==='QA056 b initial','Page content is independent across stores with no inheritance or synchronization');
$check(get_post_status($a)==='publish'&&get_post_status($b)==='draft','Page publishing state is independent across stores');
$bad=wp_update_post(['ID'=>$a,'post_parent'=>$b,'post_title'=>'Rejected foreign parent'],true);$check(is_wp_error($bad)&&wp_get_post_parent_id($a)===0&&str_contains(get_post_field('post_title',$a),'QA056 a'),'Foreign parent is rejected before partially saving title or hierarchy');
$bad=wp_update_post(['ID'=>$a,'post_parent'=>$child],true);$check(is_wp_error($bad),'Native page hierarchy cannot form a cycle');
$bad=wp_update_post(['ID'=>$a,'meta_input'=>['_gsm_brand_id'=>7]],true);$check(is_wp_error($bad)&&NativePages::owner($a)===6,'Root native CRUD cannot reassign an existing page to another store');
$bad=wp_update_post(['ID'=>$a,'meta_input'=>['_gsm_brand_id'=>0]],true);$check(is_wp_error($bad)&&NativePages::owner($a)===6,'A store page cannot be converted to an unowned public template');
update_post_meta($a,'_gsm_brand_id',7);delete_post_meta($a,'_gsm_brand_id');$check(NativePages::owner($a)===6,'Raw metadata cannot bypass fixed page ownership');
$raw=get_post_meta($a,'_gsm_brand_id',false);$check(count($raw)===1,'Page creation does not leave duplicate ownership metadata');
$check(str_starts_with(get_permalink($a),Brands::url(6))&&str_starts_with(get_permalink($b),Brands::url(7)),'Native page permalinks retain their owning storefront domain');
$identity=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);wp_set_current_user((int)$identity['staff']);$_REQUEST['gsm_brand']=7;
$result=Stores::within(7,static fn()=>wp_update_post(['ID'=>$b,'post_content'=>'QA056 B employee edit'],true));$check(!is_wp_error($result)&&get_post_field('post_content',$b)==='QA056 B employee edit','A brand administrator can independently edit its own page');
$bad=Stores::within(7,static fn()=>wp_update_post(['ID'=>$a,'post_content'=>'Foreign edit'],true));$check(is_wp_error($bad)&&get_post_field('post_content',$a)==='QA056 A independently edited','A brand administrator cannot write another store page by numeric ID');
$employee=Stores::within(7,static fn()=>$make(7,'employee'));$check(NativePages::owner($employee)===7,'An authorized employee can create an ordinary page in its own store');
$_REQUEST['gsm_brand']=6;$bad=Stores::within(6,static fn()=>wp_insert_post(['post_type'=>'page','post_title'=>'Rejected shop manager page'],true));$check(is_wp_error($bad),'Shop Manager does not acquire brand administrator page creation rights');wp_set_current_user($root);
$_REQUEST['gsm_brand']=1;$copy=wp_insert_post(['post_type'=>'page','post_title'=>'QA056 copy '.$f['suffix'],'post_content'=>'Independent initialized copy','meta_input'=>['_gsm_brand_id'=>7,'_gsm_public_slug'=>'qa056-copy-'.$f['suffix']],'post_status'=>'draft'],true);$f['copy']=$copy;$f['ids'][]=$copy;$persist();$check(!is_wp_error($copy)&&NativePages::owner($copy)===7,'Trusted root initialization can create an independent page for a specified store');
$check(has_action('init',[Trovesia\Plugin\Site_Experience::instance(),'install_policy_pages'])===false,'Legacy policy auto-writer is disabled and cannot overwrite independent brand pages');
$check(!class_exists('GroupShop\\Manager\\Pages')&&!function_exists('group_shop_page_url'),'No template inheritance service or new policy-binding layer remains');
foreach([1,6,7] as $brand){$old=(int)($f['banks'][$brand]['wp_page_for_privacy_policy']??0);$check(Stores::within($brand,static fn()=>(int)get_option('wp_page_for_privacy_policy'))===$old,'Existing native privacy-page assignment remains unchanged for store '.$brand);}
$f['a_url']=get_permalink($a);$persist();echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
