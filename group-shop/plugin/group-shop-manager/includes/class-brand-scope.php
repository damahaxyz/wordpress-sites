<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Native queries and direct-object entry points share the same brand boundary. */
final class BrandScope {
    public static function product_brand(int $id): int {
        $parent=wp_get_post_parent_id($id); if (get_post_type($id)==='product_variation'&&$parent) { $id=$parent; }
        return (int)get_post_meta($id,'_gsm_brand_id',true);
    }
    public static function product_here(int $id): bool { return self::product_brand($id)===Brands::id()&&get_post_meta($id,'_gsm_kind',true)!=='base'; }
    public static function clause(?int $brand=null): array { return ['key'=>'_gsm_brand_id','value'=>$brand??Brands::id(),'compare'=>'=','type'=>'NUMERIC']; }
    public static function boot(): void {
        require_once GSM_DIR.'includes/class-brand-session.php';
        add_action('admin_init',static function (): void { if (!Brands::root()&&in_array($_GET['page']??'',['wc-admin','wc-reports','wc-settings','wc-status'],true)) { wp_die('跨品牌经营统计仅限商城总管理员。','权限不足',['response'=>403]); } });
        add_action('wp_dashboard_setup',static function (): void { if (!Brands::root()) { remove_meta_box('woocommerce_dashboard_status','dashboard','normal'); } },100);
        add_action('woocommerce_new_coupon',static function ($id): void { if (!metadata_exists('post',(int)$id,'_gsm_brand_id')) { update_post_meta((int)$id,'_gsm_brand_id',Brands::query_scope()??0); } });
        add_filter('woocommerce_product_is_visible',static fn($ok,$id)=>$ok&&self::product_here((int)$id),30,2);
        add_filter('get_terms',[self::class,'term_counts'],30,4);
        add_action('created_nav_menu',static function ($id): void { if (!Brands::unrestricted()) { update_term_meta((int)$id,'_gsm_brand_id',Brands::id()); } });
        add_filter('get_terms_args',static function ($args,$taxonomies) { if (in_array('nav_menu',(array)$taxonomies,true)&&!Brands::unrestricted()) { $args['meta_query']=is_array($args['meta_query']??null)?$args['meta_query']:[]; $args['meta_query'][]=self::clause(); } return $args; },30,2);
        add_filter('woocommerce_get_coupon_id_from_code',static function ($id,$code) { global $wpdb; return (int)$wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} b ON b.post_id=p.ID AND b.meta_key='_gsm_brand_id' WHERE p.post_type='shop_coupon' AND p.post_status='publish' AND p.post_title=%s AND b.meta_value IN (0,%d) ORDER BY b.meta_value DESC LIMIT 1",$code,Brands::id())); },30,2);
        add_filter('woocommerce_coupon_is_valid',static function ($ok,$coupon) { $brand=(int)get_post_meta($coupon->get_id(),'_gsm_brand_id',true); return $ok&&(!$brand||$brand===Brands::id()); },20,2);
        add_filter('woocommerce_session_handler',static fn()=>BrandSession::class);
        add_action('wp_after_insert_post',static function ($id,$post,$update): void { if (Brands::unrestricted()||(in_array($post->post_type,['product','product_variation'],true)&&$post->post_status!=='auto-draft')||!in_array($post->post_type,['page','post','product','product_variation','wp_block','nav_menu_item','trovesia_inquiry'],true)) { return; } if (!metadata_exists('post',$id,'_gsm_brand_id')) { update_post_meta($id,'_gsm_brand_id',$post->post_type==='product'&&Brands::root()?0:Brands::id()); } if (in_array($post->post_type,['product','product_variation'],true)) { return; } if (!get_post_meta($id,'_gsm_public_slug',true)) { update_post_meta($id,'_gsm_public_slug',$post->post_name?:sanitize_title($post->post_title)); } },30,3);
        foreach (['add_post_metadata','update_post_metadata','delete_post_metadata'] as $hook) { add_filter($hook,static function ($check,$id,$key,$value) {
            if (Brands::root()||Brands::unrestricted()||!in_array($key,['_gsm_brand_id','_gsm_kind','_gsm_base_product','_gsm_requested_brands','_gsm_base_variation'],true)) { return $check; }
            // Brand staff cannot use native REST meta_data to reassign an owned product.
            if ($key==='_gsm_brand_id'&&!metadata_exists('post',$id,$key)&&(int)$value===Brands::id()) { return $check; }
            return false;
        },20,4); }
        add_filter('get_terms_args',static function ($args,$taxonomies) {
            if (Brands::query_scope()===null||Brands::unrestricted()||!in_array('product_cat',(array)$taxonomies,true)) { return $args; }
            // WordPress persists this internal query as a global taxonomy hierarchy.
            if (($args['fields']??'')==='id=>parent'&&($args['get']??'')==='all') { return $args; }
            global $wpdb;
            $foreign=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key='_gsm_brand_id' AND CAST(meta_value AS UNSIGNED)<>%d",Brands::id())));
            $args['exclude']=array_unique(array_merge(wp_parse_id_list($args['exclude']??[]),$foreign));return $args;
        },30,2);
        add_action('set_object_terms',static function ($id,$terms,$tt,$taxonomy): void {
            if ($taxonomy!=='product_cat'||Brands::unrestricted()||get_post_type($id)!=='product') { return; }
            $brand=self::product_brand((int)$id);$remove=[];
            foreach (Brands::unscoped(static fn()=>wp_get_object_terms($id,'product_cat')) as $term) { if (get_term_meta($term->term_id,'_gsm_collection',true)&&(int)get_term_meta($term->term_id,'_gsm_brand_id',true)!==$brand) { $remove[]=$term->term_id; } }
            if ($remove) { wp_remove_object_terms($id,$remove,'product_cat'); }
        },200,4);
        add_action('pre_get_posts',[self::class,'posts'],30);
        add_filter('woocommerce_product_data_store_cpt_get_products_query',static function ($query,$vars) {
            $brand=Brands::query_scope(); $parent=(int)($query['post_parent']??0);
            if ($parent&&Brands::root()) { $brand=self::product_brand($parent); }
            if ($brand!==null) { $query['meta_query'][]=self::clause($brand); }
            return $query;
        },30,2);
        add_filter('woocommerce_order_query_args',static function ($args) {
            $brand=Brands::query_scope();
            if ($brand!==null) { $args['meta_query'][]=['key'=>'_gsm_store_id','value'=>$brand,'type'=>'NUMERIC']; $args['gsm_brand']=$brand; }
            return $args;
        },30);
        add_filter('woocommerce_order_data_store_cpt_get_orders_query',static function ($query,$vars) { if (isset($vars['gsm_brand'])) { $query['meta_query'][]=['key'=>'_gsm_store_id','value'=>(int)$vars['gsm_brand'],'type'=>'NUMERIC']; } return $query; },30,2);
        add_filter('woocommerce_order_list_table_prepare_items_query_args',static function ($args) { $brand=Brands::query_scope(); if ($brand!==null) { $args['meta_query'][]=['key'=>'_gsm_store_id','value'=>$brand,'type'=>'NUMERIC']; } return $args; });
        add_filter('woocommerce_rest_shop_order_object_query',static function ($args) { $args['meta_query'][]=['key'=>'_gsm_store_id','value'=>Brands::id(),'type'=>'NUMERIC']; return $args; });
        add_action('pre_get_comments',static function ($query): void {
            if (Brands::unrestricted()||Brands::query_scope()===null||($query->query_vars['type']??'')==='order_note') { return; }
            $meta=$query->query_vars['meta_query']??[]; $meta=is_array($meta)?$meta:[]; $meta[]=self::clause(); $query->query_vars['meta_query']=$meta;
        });
        add_filter('preprocess_comment',static function ($data) {
            $post=(int)($data['comment_post_ID']??0); $brand=(int)get_post_meta($post,'_gsm_brand_id',true);
            if ($brand&&$brand!==Brands::id()&&!Brands::unrestricted()) { wp_die('不能向其他品牌提交评论。','品牌错误',['response'=>403]); }
            return $data;
        });
        add_action('comment_post',static function ($id,$approved,$data): void { update_comment_meta($id,'_gsm_brand_id',(int)get_post_meta((int)$data['comment_post_ID'],'_gsm_brand_id',true)?:Brands::id()); },10,3);
        add_action('pre_get_users',static function ($query): void { if (Brands::unrestricted()||Brands::query_scope()===null) { return; } $query->query_vars=self::user_query($query->query_vars); });
        add_filter('map_meta_cap',[self::class,'caps'],50,4);
        add_filter('post_type_link',[self::class,'permalink'],20,2);
        add_filter('page_link',static function ($link,$id) { $post=get_post($id); return $post?self::permalink($link,$post):$link; },20,2);
        add_filter('post_link',[self::class,'permalink'],20,2);
        add_action('parse_request',[self::class,'route'],5);
        add_action('template_redirect',static function (): void {
            if (is_singular()) { $id=get_queried_object_id(); $brand=(int)get_post_meta($id,'_gsm_brand_id',true); if ($brand!==Brands::id()||get_post_meta($id,'_gsm_kind',true)==='base') { global $wp_query; $wp_query->set_404(); status_header(404); } }
        },0);
        add_filter('woocommerce_add_to_cart_validation',static function ($ok,$id) { if (!self::product_here((int)$id)) { wc_add_notice('此商品不属于当前品牌。','error'); return false; } return $ok; },20,2);
        add_action('woocommerce_store_api_validate_add_to_cart',static function ($product): void { if (!self::product_here($product->get_id())) { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('gsm_brand_product','此商品不属于当前品牌。',403); } });
        add_action('woocommerce_check_cart_items',static function (): void { foreach (WC()->cart?->get_cart()??[] as $item) { if (!self::product_here((int)$item['product_id'])) { wc_add_notice('购物车包含其他品牌商品，请清空后重试。','error'); break; } } });
        add_filter('woocommerce_is_purchasable',static fn($ok,$p)=>$ok&&self::product_here($p->get_id()),20,2);
        add_filter('woocommerce_variation_is_purchasable',static fn($ok,$p)=>$ok&&self::product_here($p->get_id()),20,2);
        add_filter('rest_pre_dispatch',[self::class,'rest'],10,3);
        add_filter('woocommerce_customer_get_downloadable_products',static fn($rows)=>array_values(array_filter($rows,static fn($r)=>self::product_here((int)$r['product_id']))));

    }
    public static function posts(\WP_Query $query): void {
        if (Brands::unrestricted()) { return; }
        $type=$query->get('post_type'); $types=(array)$type;
        if (!$type&&($query->get('product')||$query->get('product_cat')||$query->get('product_tag'))) { $types=['product']; }
        if (!$type&&$query->is_search()) { $types=['post','product']; }
        if (!$type&&($query->get('page_id')||$query->get('pagename'))) { $types=['page']; }
        if (!$type&&!$types&&($query->is_home()||$query->is_archive()||$query->is_feed()||$query->get('p')||$query->get('name'))) { $types=['post']; }
        if (!$types||(!in_array('any',$types,true)&&!array_diff($types,['attachment','revision','wp_template','wp_template_part','wp_global_styles','customize_changeset']))) { return; }
        $brand=Brands::query_scope();
        if (in_array('product_variation',$types,true)&&$query->get('post_parent')&&Brands::root()) { $brand=self::product_brand((int)$query->get('post_parent')); }
        $meta=(array)$query->get('meta_query');
        if ($brand!==null) { $meta=$meta?['relation'=>'AND',$meta,self::clause($brand)]:[self::clause($brand)]; }
        $query->set('meta_query',$meta);
    }
    public static function user_query(array $args): array {
        if (Brands::query_scope()===null) { return $args; }
        global $wpdb;
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT user_id FROM '.Schema::table('identities')." WHERE kind='customer' AND store_id=%d",Brands::id())));
        foreach ($wpdb->get_col('SELECT user_id FROM '.Schema::table('identities')." WHERE kind='staff'") as $id) { if (Identities::accessible((int)$id)) { $ids[]=(int)$id; } }
        if(Brands::root()){$ids=array_merge($ids,array_map('intval',(array)get_option('gsm_platform_admins',[])));}
        $args['include']=$ids?:[0]; $args['blog_id']=1; return $args;
    }
    public static function caps(array $caps,string $cap,int $actor,array $args): array {
        if (Brands::unrestricted()||Brands::root($actor)) { return $caps; }
        if (in_array($cap,['manage_product_terms','edit_product_terms','delete_product_terms'],true)) { return ['do_not_allow']; }
        if (in_array($cap,['edit_post','delete_post','read_post','edit_product','delete_product','read_product'],true)&&!empty($args[0])) {
            $post=get_post((int)$args[0]); if ($post&&$post->post_type==='attachment'&&($cap==='delete_post'||($cap==='edit_post'&&(int)$post->post_author!==$actor))) { return ['do_not_allow']; } if ($post&&!in_array($post->post_type,['attachment','shop_order','shop_order_refund','shop_order_placehold'],true)) { $brand=self::product_brand($post->ID); if (!$brand||$brand!==Brands::id()) { return ['do_not_allow']; } }
        }
        if (in_array($cap,['edit_shop_order','delete_shop_order','read_shop_order','view_order','pay_for_order','order_again'],true)&&!empty($args[0])) {
            $o=wc_get_order((int)$args[0]); if ($o&&(int)$o->get_meta('_gsm_store_id')!==Brands::id()) { return ['do_not_allow']; }
        }
        return $caps;
    }
    public static function permalink(string $url,\WP_Post $post): string {
        $brand=(int)get_post_meta($post->ID,'_gsm_brand_id',true); $slug=(string)get_post_meta($post->ID,'_gsm_public_slug',true);
        if (!$brand||!$slug||!Brands::url($brand)) { return $url; }
        $base=(string)(get_option('woocommerce_permalinks')['product_base']??''); $base=$base!==''?$base:'product';
        $path=$post->post_type==='product'?trim($base,'/').'/'.$slug:$slug;
        if ($post->post_type==='product'&&$post->post_name!==''&&str_contains((string)wp_parse_url($url,PHP_URL_PATH),'/'.$post->post_name.'/')) { $path=trim(str_replace('/'.$post->post_name.'/','/'.$slug.'/',(string)wp_parse_url($url,PHP_URL_PATH)),'/'); }
        return Brands::url($brand).'/'.trim((string)$path,'/').'/';
    }
    public static function route(\WP $wp): void {
        if (is_admin()||str_starts_with((string)$wp->request,'wp-json')) { return; }
        $slug=(string)($wp->query_vars['product']??$wp->query_vars['pagename']??$wp->query_vars['name']??'');
        if ($slug==='') { return; }
        $type=isset($wp->query_vars['product'])?'product':(isset($wp->query_vars['pagename'])?'page':'post');
        global $wpdb;
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_gsm_public_slug' JOIN {$wpdb->postmeta} b ON b.post_id=p.ID AND b.meta_key='_gsm_brand_id' WHERE s.meta_value=%s AND b.meta_value=%d AND p.post_type=%s LIMIT 1",$slug,Brands::id(),$type));
        if (!$id) { return; }
        $post=get_post($id); unset($wp->query_vars['name'],$wp->query_vars['pagename'],$wp->query_vars['product']);
        if ($post->post_type==='page') { $wp->query_vars['page_id']=$id; }
        else { $wp->query_vars['p']=$id; $wp->query_vars['post_type']=$post->post_type; }
    }
    public static function rest(mixed $result,mixed $server,\WP_REST_Request $request): mixed {
        if (Brands::unrestricted()) { return $result; }
        if ($token=$request->get_header('Cart-Token')) {
            try { $payload=\Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token_payload($token); $customer=(string)($payload['user_id']??''); if (ctype_digit($customer)&&(Identities::get((int)$customer)['kind']??'')==='staff'&&str_contains($request->get_route(),'/checkout')) { return new \WP_Error('gsm_staff_checkout','员工账户请使用独立顾客身份下单。',['status'=>403]); } if (!BrandSession::belongs($customer)) { return new \WP_Error('gsm_cart_brand','购物车令牌不属于当前品牌。',['status'=>403]); } } catch (\Throwable $e) { return new \WP_Error('gsm_cart_token','购物车令牌无效。',['status'=>403]); }
        }
        if (Brands::query_scope()===null&&Brands::root()) { return $result; }
        $route=$request->get_route();
        if (!Brands::root()&&(str_starts_with($route,'/wc-analytics/')||preg_match('#^/wc/v[1-3]/reports#',$route))) { return new \WP_Error('gsm_analytics_scope','跨品牌统计仅限商城总管理员。',['status'=>403]); }
        if (preg_match('#^/(?:wc/(?:v[1-3]|store/v1)/products|wp/v2/(?:product|pages|posts))/(\d+)(?:/variations/(\d+))?#',$route,$m)) {
            $id=(int)($m[2]??$m[1]); if (get_post_type($id)!=='attachment'&&!self::product_here($id)) { return new \WP_Error('gsm_brand_product','商品不属于当前品牌。',['status'=>404]); }
        }
        if (preg_match('#^/wc/(?:v[1-3]|store/v1)/orders/(\d+)#',$route,$m)) { $o=wc_get_order((int)$m[1]); if ($o&&(int)$o->get_meta('_gsm_store_id')!==Brands::id()) { return new \WP_Error('gsm_brand_order','订单不属于当前品牌。',['status'=>404]); } }
        if (preg_match('#^/wc/v[1-3]/coupons/(\d+)#',$route,$m)) { $brand=(int)get_post_meta((int)$m[1],'_gsm_brand_id',true); if ($brand&&$brand!==Brands::id()) { return new \WP_Error('gsm_brand_coupon','优惠券不属于当前品牌。',['status'=>404]); } }
        if (str_ends_with($route,'/batch')&&preg_match('#^/wc/v[1-3]/(products|customers|orders|coupons)/batch$#',$route,$m)) {
            foreach (array_merge((array)$request->get_param('update'),(array)$request->get_param('delete')) as $row) {
                $id=is_array($row)?(int)($row['id']??0):(int)$row;
                if (!$id) { continue; }
                $ok=$m[1]==='coupons'?((int)get_post_meta($id,'_gsm_brand_id',true)===Brands::id()):($m[1]==='products'?self::product_here($id):($m[1]==='customers'?Customers::local_customer($id):((int)(wc_get_order($id)?->get_meta('_gsm_store_id'))===Brands::id())));
                if (!$ok) { return new \WP_Error('gsm_brand_batch','批量请求包含其他品牌记录。',['status'=>403]); }
            }
        }
        return $result;
    }
    public static function term_counts($terms,$taxonomies,$args,$query) {
        $brand=Brands::query_scope();
        if ($brand===null||Brands::unrestricted()||!is_array($terms)||!array_intersect((array)$taxonomies,['product_cat','product_tag'])||!$terms||!(reset($terms) instanceof \WP_Term)) { return $terms; }
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT tt.term_id,tt.parent,tt.taxonomy,p.ID FROM {$wpdb->term_taxonomy} tt LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id LEFT JOIN {$wpdb->posts} p ON p.ID=tr.object_id AND p.post_type='product' AND p.post_status='publish' LEFT JOIN {$wpdb->postmeta} b ON b.post_id=p.ID AND b.meta_key='_gsm_brand_id' WHERE tt.taxonomy IN ('product_cat','product_tag') AND (b.meta_value=%d OR p.ID IS NULL)",$brand),ARRAY_A);
        $ids=[]; $parents=[];
        foreach ($rows as $r) { $id=(int)$r['term_id']; $parents[$id]=(int)$r['parent']; if ($r['ID']) { $ids[$id][(int)$r['ID']]=true; } }
        // Include ancestors with no direct products. The complete tree is shared and not brand data.
        foreach ($wpdb->get_results("SELECT term_id,parent FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat'",ARRAY_A) as $r) { $parents[(int)$r['term_id']]=(int)$r['parent']; }
        foreach ($ids as $id=>$products) { $seen=[]; $parent=$parents[$id]??0; while ($parent&&!isset($seen[$parent])) { $seen[$parent]=true; if (!get_term_meta($parent,'_gsm_collection',true)) { $ids[$parent]=($ids[$parent]??[])+$products; } $parent=$parents[$parent]??0; } }
        $out=[]; foreach ($terms as $term) { if (!in_array($term->taxonomy,['product_cat','product_tag'],true)) { $out[]=$term; continue; } $owner=(int)get_term_meta($term->term_id,'_gsm_brand_id',true); if (get_term_meta($term->term_id,'_gsm_collection',true)&&$owner!==$brand) { continue; } $term=clone $term; $term->count=count($ids[$term->term_id]??[]); if (empty($args['hide_empty'])||$term->count>0) { $out[]=$term; } }
        return $out;
    }
}
