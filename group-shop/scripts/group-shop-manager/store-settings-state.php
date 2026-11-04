<?php
use GroupShop\Manager\Stores;
if (!defined('WP_CLI')||!WP_CLI||get_home_url(Stores::main_id())!=='https://www.animecats.online') { throw new RuntimeException('Wrong installation'); }
Stores::authorize(); $state=[];
foreach (array_keys(Stores::all()) as $store) {
    $state[$store]=Stores::within($store,static fn()=>['domain'=>get_site(get_current_blog_id())->domain,'path'=>get_site(get_current_blog_id())->path,'home'=>get_option('home'),'siteurl'=>get_option('siteurl'),'uuid'=>Stores::settings()['uuid']??'', 'public'=>(string)get_option('blog_public'),'logo'=>(int)get_option('gsm_logo_id',get_theme_mod('custom_logo',0)),'theme'=>get_stylesheet()]);
}
echo wp_json_encode(['stores'=>$state,'users'=>count_users()['total_users'],'orders'=>wc_get_orders(['limit'=>1,'paginate'=>true])->total],JSON_PRETTY_PRINT)."\n";
