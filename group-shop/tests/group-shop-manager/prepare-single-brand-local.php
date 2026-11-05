<?php
if (!defined('WP_CLI')||!WP_CLI||!is_multisite()||wp_get_environment_type()!=='local'||get_home_url(1)!=='http://www.main.local') { throw new RuntimeException('Mapped multisite QA only'); }
$b=unserialize(file_get_contents('/gsm-private/multisite-export.bin'));
$roots=[]; foreach ($b['staff'] as $id=>$row) { if ($row['root']) { $roots[]=(int)$id; } }
if (!$roots) { throw new RuntimeException('No platform administrator'); }
$brands=[]; foreach($b['brands'] as $id=>$row) { $brands[$id]=['name'=>$row['name'],'domain'=>$row['domain'],'url'=>untrailingslashit($row['options']['home']),'uuid'=>$row['options']['gsm_store_settings']['uuid']??wp_generate_uuid4()]; }
update_option('gsm_platform_admins',$roots,false); update_option('gsm_brands',$brands,false);
foreach($b['network'] as $key=>$value) { update_option($key,$value,false); }
$plugins=array_values(array_unique(array_merge((array)get_option('active_plugins'),array_keys((array)get_site_option('active_sitewide_plugins')))));sort($plugins);update_option('active_plugins',$plugins);
echo wp_json_encode(['prepared'=>true,'brands'=>count($brands),'platform_admins'=>count($roots),'active_plugins'=>$plugins])."\n";
