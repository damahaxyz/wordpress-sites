<?php
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||get_option('home')!=='http://www.main.local') { throw new RuntimeException('Prepared mapped single-site QA only'); }
$bundle=unserialize(file_get_contents('/gsm-private/multisite-export.bin'));
$result=GroupShop\Manager\SingleMigration::run($bundle);
echo wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
