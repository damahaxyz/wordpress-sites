<?php
/** Non-destructive fixture setup for the current local suite. */
use GroupShop\Manager\{Stores,CategoryMigration,SharedTaxonomies};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_HOST)!=='localhost'||(int)wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_PORT)!==18087) { throw new RuntimeException('Local isolated QA only'); }
wp_set_current_user(get_user_by('login','gsm-admin')->ID);
$stores=[];
foreach (['brand-a'=>'Brand A','brand-b'=>'Brand B'] as $slug=>$name) {
    $site=get_site_by_path(get_network()->domain,'/'.$slug.'/');
    $stores[$slug]=$site&&(int)$site->blog_id!==Stores::main_id()?(int)$site->blog_id:Stores::create($name,$slug);
}
CategoryMigration::run(); SharedTaxonomies::reconcile();
$customer=get_user_by('email','gsm-editor-customer@example.invalid');
if (!$customer) {
    $id=wc_create_new_customer('gsm-editor-customer@example.invalid','gsm-editor-customer',wp_generate_password(32));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $customer=get_userdata($id);
}
if (!is_user_member_of_blog($customer->ID,$stores['brand-a'])) { add_user_to_blog($stores['brand-a'],$customer->ID,'customer'); }
echo wp_json_encode(['version'=>GSM_VERSION,'stores'=>$stores,'ready'=>true])."\n";
