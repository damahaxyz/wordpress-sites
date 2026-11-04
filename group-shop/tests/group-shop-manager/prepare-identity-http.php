<?php
use GroupShop\Manager\{Stores,Identities};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||get_home_url()!=='http://www.main.local') { throw new RuntimeException('Local QA only'); }
$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true);
$f['reset_a']=Stores::within(6,static fn()=>get_password_reset_key(get_userdata($f['a'])));
if (!is_string($f['reset_a'])) { throw new RuntimeException('Reset fixture failed'); }
file_put_contents('/gsm-private/fixtures.json',wp_json_encode($f)); chmod('/gsm-private/fixtures.json',0600);
echo "Private reset fixture prepared\n";
