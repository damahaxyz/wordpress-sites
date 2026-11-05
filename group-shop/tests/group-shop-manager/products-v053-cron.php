<?php
use GroupShop\Manager\{Brands,Stores};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||!Brands::active()||get_option('home')!=='http://www.main.local') {throw new RuntimeException('Private local QA only');}
$x=json_decode(file_get_contents('/gsm-private/products-v053.json'),true);foreach(['base','a','b'] as $key){if(!str_starts_with((string)get_post_field('post_title',$x[$key],'raw'),'QA053 ')){throw new RuntimeException('Fixture only');}}
if (!defined('DOING_CRON')) {define('DOING_CRON',true);}wp_set_current_user(0);$before=wc_get_product($x['b'])->get_regular_price();$base=wc_get_product($x['base']);$base->set_regular_price('58');$base->save();if(wc_get_product($x['base'])->get_regular_price()!=='58'||wc_get_product($x['b'])->get_regular_price()!==$before){throw new RuntimeException('Scheduled public save failed');}
Stores::within(6,static function()use($x){$p=wc_get_product($x['a']);$p->set_regular_price('102');$p->save();if(wc_get_product($x['a'])->get_regular_price()!=='102'){throw new RuntimeException('Scheduled edition save failed');}});
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>2,'checks'=>['Existing public products accept Woo cron saves with brand overrides preserved','Existing brand editions accept Woo cron saves with their public association retained']],JSON_PRETTY_PRINT)."\n";
