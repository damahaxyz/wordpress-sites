<?php
use GroupShop\Manager\{Stores,Schema};
if (!defined('WP_CLI')||!WP_CLI||!is_multisite()||wp_get_environment_type()!=='local'||get_home_url(1)!=='http://www.main.local') { throw new RuntimeException('Mapped multisite QA only'); }
$bundle=['version'=>1,'brands'=>[],'staff'=>[],'network'=>[],'catalog'=>[]];
$meta=static function($object): array { return array_map(static fn($m)=>$m->get_data(),$object->get_meta_data()); };
foreach (get_users(['blog_id'=>0,'number'=>-1]) as $user) {
    $grants=[]; foreach (get_blogs_of_user($user->ID,true) as $site) { $roles=(new WP_User($user->ID,'',(int)$site->userblog_id))->roles; foreach (['administrator','shop_manager'] as $role) { if (in_array($role,$roles,true)) { $grants[(int)$site->userblog_id]=$role; break; } } }
    $bundle['staff'][$user->ID]=['root'=>is_super_admin($user->ID),'grants'=>$grants];
}
foreach (['gsm_identity_ready','gsm_identity_migration_version','gsm_identity_migration_sources','gsm_schema_version'] as $key) { $bundle['network'][$key]=get_site_option($key); }
global $wpdb; $bundle['catalog']=$wpdb->get_results('SELECT * FROM '.Schema::table('catalog'),ARRAY_A);
foreach (Stores::all() as $brand=>$name) {
    $bundle['brands'][$brand]=Stores::within((int)$brand,static function () use ($brand,$name,$meta): array {
        global $wpdb; $site=get_site($brand); $out=['id'=>$brand,'name'=>$name,'domain'=>$site->domain,'options'=>[],'posts'=>[],'terms'=>[],'products'=>[],'orders'=>[],'comments'=>[],'downloads'=>[],'tokens'=>[],'tax_rates'=>[],'attributes'=>wc_get_attribute_taxonomies()];
        foreach ($wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options}",ARRAY_A) as $row) {
            $key=$row['option_name'];
            if (in_array($key,['blogname','blogdescription','home','siteurl','blog_public','page_on_front','page_for_posts','show_on_front','wp_page_for_privacy_policy','site_icon','stylesheet','template','custom_logo','gsm_store_settings','gsm_logo_id','gsm_collections','gsm_collections_review','sidebars_widgets','nav_menu_options','permalink_structure','woocommerce_permalinks','default_product_cat','trovesia_settings'],true)||str_starts_with($key,'theme_mods_')||preg_match('/^woocommerce_.*_page_id$/',$key)||str_starts_with($key,'trovesia_')) { $out['options'][$key]=maybe_unserialize($row['option_value']); }
        }
        foreach (get_taxonomies([],'names') as $taxonomy) {
            foreach (get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false]) as $term) { $out['terms'][$taxonomy][$term->term_id]=['data'=>(array)$term,'meta'=>get_term_meta($term->term_id)]; }
        }
        foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','customize_changeset','shop_order','shop_order_refund','shop_order_placehold') ORDER BY ID") as $id) {
            $post=get_post($id); $relations=[]; foreach (get_object_taxonomies($post->post_type) as $tax) { $relations[$tax]=wp_get_object_terms($id,$tax,['fields'=>'ids']); }
            $out['posts'][$id]=['data'=>(array)$post,'meta'=>get_post_meta($id),'terms'=>$relations];
            if (in_array($post->post_type,['product','product_variation'],true)) {
                $product=wc_get_product($id); if (!$product) { throw new RuntimeException('Missing source product'); }
                $data=$product->get_data(); $data['meta_data']=$meta($product); $data['attributes']=[];
                foreach ($product->get_attributes() as $key=>$attr) { $data['attributes'][$key]=$attr instanceof WC_Product_Attribute?$attr->get_data():$attr; }
                $data['downloads']=array_map(static fn($d)=>$d->get_data(),$product->get_downloads());
                $out['products'][$id]=['type'=>$product->get_type(),'data'=>$data];
            }
        }
        foreach (wc_get_orders(['limit'=>-1,'type'=>['shop_order','shop_order_refund'],'status'=>array_merge(array_keys(wc_get_order_statuses()),['trash'])]) as $order) {
            $data=$order->get_data(); $data['meta_data']=$meta($order); $items=[];
            foreach (['line_item','shipping','fee','tax','coupon'] as $type) { foreach ($order->get_items($type) as $item) { $itemdata=$item->get_data();$itemdata['meta_data']=$meta($item);$items[]=['type'=>$type,'data'=>$itemdata]; } }
            $notes=[]; foreach (wc_get_order_notes(['order_id'=>$order->get_id(),'limit'=>-1]) as $note) { $notes[]=(array)$note; }
            $out['orders'][$order->get_id()]=['type'=>$order->get_type(),'data'=>$data,'items'=>$items,'notes'=>$notes];
        }
        foreach (get_comments(['status'=>'all','number'=>0,'type__not_in'=>['order_note']]) as $comment) { $out['comments'][$comment->comment_ID]=['data'=>(array)$comment,'meta'=>get_comment_meta($comment->comment_ID)]; }
        foreach (WC_Data_Store::load('customer-download')->get_downloads(['limit'=>-1]) as $d) { $out['downloads'][]=$d->get_data(); }
        foreach ($wpdb->get_col("SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens") as $id) { $token=WC_Payment_Tokens::get($id); if ($token) { $d=$token->get_data();$d['meta_data']=$meta($token);$out['tokens'][]=$d; } }
        $out['tax_rates']=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates",ARRAY_A);
        return $out;
    });
}
file_put_contents('/gsm-private/multisite-export.bin',serialize($bundle)); chmod('/gsm-private/multisite-export.bin',0600);
$counts=[];foreach($bundle['brands'] as $id=>$b){$counts[$id]=array_map('count',array_intersect_key($b,array_flip(['posts','products','orders','comments','downloads','tokens'])));}
echo wp_json_encode(['source_version'=>GSM_VERSION,'brands'=>count($counts),'counts'=>$counts,'private_export'=>true],JSON_PRETTY_PRINT)."\n";
