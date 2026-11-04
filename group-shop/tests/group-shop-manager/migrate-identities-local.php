<?php
use GroupShop\Manager\{Stores,UserMigration};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||get_home_url(Stores::main_id())!=='http://www.main.local') { throw new RuntimeException('Mapped local QA only'); }
wp_set_current_user(get_user_by('login','gsm-admin')->ID);
$plan=UserMigration::plan();
if ($plan['blocked']) { echo wp_json_encode(['blocked'=>$plan['blocked']]); throw new RuntimeException('Identity preflight blocked'); }
$result=UserMigration::run();
echo wp_json_encode(['version'=>GSM_VERSION,'plan'=>$plan,'result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
