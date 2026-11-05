<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Public business resources stay within the host brand; media and assets remain shared. */
final class SeoIsolation {
    private static bool $blocked=false;
    private static array $stats=[];

    public static function scoped(): bool {
        return !is_admin()&&!Brands::unrestricted()&&Brands::query_scope()!==null;
    }
    public static function post_allowed(int $id): bool {
        $post=get_post($id);
        if (!$post) { return false; }
        if ($post->post_type==='attachment') { return true; }
        if ($post->post_type==='revision') { return self::post_allowed((int)$post->post_parent); }
        return BrandScope::product_brand($id)===Brands::id()&&get_post_meta($id,'_gsm_kind',true)!=='base';
    }
    private static function product_taxonomy(string $tax): bool {
        return in_array($tax,['product_cat','product_tag','product_brand'],true)||str_starts_with($tax,'pa_');
    }
    public static function term_allowed(int $id,string $tax): bool {
        $term=get_term($id,$tax);
        if (!$term instanceof \WP_Term) { return false; }
        $owner=(int)get_term_meta($id,'_gsm_brand_id',true);
        if ($owner&&$owner!==Brands::id()) { return false; }
        if (get_term_meta($id,'_gsm_collection',true)) {
            $rule=Collections::collections()[$id]??null;
            return $owner===Brands::id()&&$rule&&!empty($rule['enabled'])&&empty($rule['deleted_at']);
        }
        return true;
    }
    public static function boot(): void {
        add_action('parse_request',[self::class,'request'],20);
        add_action('template_redirect',[self::class,'template'],1);
        add_filter('redirect_canonical',static fn($url)=>self::scoped()&&(is_404()||self::foreign_url((string)$url))?false:$url,100);
        add_filter('old_slug_redirect_post_id',static fn($id)=>self::scoped()&&(!self::post_allowed((int)$id)||self::$blocked)?0:$id,100);
        add_filter('old_slug_redirect_url',static fn($url)=>self::scoped()&&(self::$blocked||self::foreign_url((string)$url))?false:$url,100);
        add_filter('get_canonical_url',static fn($url,$post)=>self::scoped()&&(!self::post_allowed($post->ID)||self::foreign_url((string)$url))?false:$url,100,2);
        foreach (['the_content','the_excerpt','woocommerce_short_description'] as $hook) { add_filter($hook,[self::class,'content_links'],100); }
        add_filter('pre_oembed_result',static fn($result,$url)=>self::scoped()&&self::foreign_business_url((string)$url)?'':$result,10,2);
        add_filter('get_terms_args',[self::class,'terms'],50,2);
        add_filter('rest_request_before_callbacks',[self::class,'rest_guard'],5,3);
        add_filter('rest_dispatch_request',[self::class,'rest_dispatch'],20,4);
        // Apply within dispatch, so embedded/internal REST subrequests get the
        // same safe term counts as a top-level HTTP response.
        add_filter('rest_request_after_callbacks',static fn($response,$handler,$request)=>is_wp_error($response)?$response:self::rest_response(rest_ensure_response($response),null,$request),100,3);
        add_filter('wp_sitemaps_taxonomies_query_args',[self::class,'sitemap_terms'],20,2);
        add_filter('wp_sitemaps_add_provider',static fn($provider,$name)=>self::scoped()&&$name==='users'?false:$provider,20,2);
        add_filter('woocommerce_related_products',[self::class,'product_ids'],100);
        foreach (['upsell_ids','cross_sell_ids'] as $key) { add_filter('woocommerce_product_get_'.$key,[self::class,'product_ids'],100); }
        // Woo caches FilterData before executing WP_Query. Put the brand into its
        // cache input as well as its SQL, including internal Store API subrequests.
        add_filter('woocommerce_pre_product_filter_data',[self::class,'filter_data'],5,4);
    }
    private static function foreign_url(string $url): bool {
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
        foreach (Brands::registry() as $id=>$brand) { if ($host===$brand['domain']&&(int)$id!==Brands::id()) { return true; } }
        return false;
    }
    private static function foreign_business_url(string $url): bool {
        return self::foreign_url($url)&&!str_starts_with((string)wp_parse_url($url,PHP_URL_PATH),'/wp-content/');
    }
    /** Do not let old rich-text links or embeds send the crawler to another store. */
    public static function content_links(string $content): string {
        if (!self::scoped()||$content==='') { return $content; }
        $html=new \WP_HTML_Tag_Processor($content);
        while ($html->next_tag()) {
            $tag=$html->get_tag();$attr=$tag==='A'?'href':($tag==='IFRAME'?'src':null);
            if ($attr&&self::foreign_business_url((string)$html->get_attribute($attr))) { $html->remove_attribute($attr); }
        }
        return $html->get_updated_html();
    }
    public static function product_ids(array $ids): array {
        return self::scoped()?array_values(array_filter($ids,static fn($id)=>BrandScope::product_here((int)$id))):$ids;
    }
    private static function deny(\WP $wp): void {
        self::$blocked=true;$wp->query_vars=['error'=>'404'];
    }
    public static function request(\WP $wp): void {
        if (!self::scoped()||isset($wp->query_vars['rest_route'])) { return; }
        if (($wp->query_vars['sitemap']??'')==='users') { self::deny($wp);return; }
        foreach (['p','page_id'] as $key) {
            $id=absint($wp->query_vars[$key]??0);
            if ($id&&!self::post_allowed($id)) { self::deny($wp);return; }
        }
        foreach (['product_cat','product_tag','product_brand'] as $tax) {
            $slug=(string)($wp->query_vars[$tax]??'');
            if ($slug==='') { continue; }
            $term=Brands::unscoped(static fn()=>get_term_by('slug',basename($slug),$tax));
            if ($term instanceof \WP_Term&&!self::term_allowed($term->term_id,$tax)) { self::deny($wp);return; }
        }
        if (($wp->query_vars['taxonomy']??'')&&($wp->query_vars['term']??'')) {
            $tax=(string)$wp->query_vars['taxonomy'];$slug=(string)$wp->query_vars['term'];
            $term=Brands::unscoped(static fn()=>get_term_by('slug',$slug,$tax));
            if ($term instanceof \WP_Term&&!self::term_allowed($term->term_id,$tax)) { self::deny($wp); }
        }
    }
    public static function template(): void {
        if (!self::scoped()) { return; }
        $object=get_queried_object();
        if (self::$blocked||($object instanceof \WP_Term&&!self::term_allowed($object->term_id,$object->taxonomy))) {
            $GLOBALS['wp_query']->set_404();status_header(404);nocache_headers();
        }
    }
    public static function terms(array $args,array $taxonomies): array {
        if (!self::scoped()||!array_filter($taxonomies,[self::class,'product_taxonomy'])) { return $args; }
        // This is a global WordPress hierarchy cache, never a public result list.
        if (($args['fields']??'')==='id=>parent'&&($args['get']??'')==='all') { return $args; }
        $condition=['relation'=>'OR',['key'=>'_gsm_brand_id','compare'=>'NOT EXISTS'],['key'=>'_gsm_brand_id','value'=>[0,Brands::id()],'compare'=>'IN','type'=>'NUMERIC']];
        $old=(array)($args['meta_query']??[]);$args['meta_query']=$old?['relation'=>'AND',$old,$condition]:[$condition];
        $excluded=[];
        foreach (Collections::collections() as $id=>$rule) { if (empty($rule['enabled'])||!empty($rule['deleted_at'])) { $excluded[]=(int)$id; } }
        if ($excluded) {
            // WP ignores exclude when include is supplied; subtract explicitly.
            if (!empty($args['include'])) { $args['include']=array_values(array_diff(wp_parse_id_list($args['include']),$excluded))?:[0]; }
            $args['exclude']=array_unique(array_merge(wp_parse_id_list($args['exclude']??[]),$excluded));
        }
        return $args;
    }
    public static function sitemap_terms(array $args,string $tax): array {
        if (!self::scoped()||!self::product_taxonomy($tax)) { return $args; }
        global $wpdb;
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT tt.term_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID=tr.object_id JOIN {$wpdb->postmeta} b ON b.post_id=p.ID AND b.meta_key='_gsm_brand_id' WHERE tt.taxonomy=%s AND p.post_type='product' AND p.post_status='publish' AND b.meta_value=%d",$tax,Brands::id())));
        if (is_taxonomy_hierarchical($tax)) { foreach ($ids as $id) { $ancestors=get_ancestors($id,$tax,'taxonomy');$ids=array_merge($ids,$ancestors); } }
        $ids=array_values(array_filter(array_unique($ids),static fn($id)=>self::term_allowed((int)$id,$tax)));
        $args['include']=isset($args['include'])?array_values(array_intersect(wp_parse_id_list($args['include']),$ids)):$ids;
        $args['include']=$args['include']?:[0];$args['hide_empty']=false;
        return $args;
    }
    private static function missing(): \WP_Error { return new \WP_Error('gsm_resource_scope','资源不属于当前店铺或不可用。',['status'=>404]); }
    public static function rest_guard(mixed $result,array $handler,\WP_REST_Request $request): mixed {
        if (!self::scoped()||is_wp_error($result)) { return $result; }
        $route=$request->get_route();
        // Woo 11 also resolves product slugs with get_page_by_path/direct SQL,
        // bypassing WP_Query and is_visible(). Resolve within the host first.
        if (self::slug_handler($handler)) {
            $id=self::slug_id($request);
            return $id&&self::post_allowed($id)?$result:self::missing();
        }
        if (preg_match('#^/wc/(?:v[1-3]|store/v1)/products/(categories|tags|brands)/(\d+)(?:/|$)#',$route,$m)) {
            $tax=['categories'=>'product_cat','tags'=>'product_tag','brands'=>'product_brand'][$m[1]];
            if (!self::term_allowed((int)$m[2],$tax)) { return self::missing(); }
        }
        if (preg_match('#^/wp/v2/(product_cat|product_tag|product_brand)/(\d+)(?:/|$)#',$route,$m)&&!self::term_allowed((int)$m[2],$m[1])) { return self::missing(); }
        if (preg_match('#^/(?:wp/v2/comments|wc/v[1-3]/products/reviews)/(\d+)(?:/|$)#',$route,$m)) {
            $comment=get_comment((int)$m[1]);
            if (!$comment||!self::post_allowed((int)$comment->comment_post_ID)||(int)get_comment_meta($comment->comment_ID,'_gsm_brand_id',true)!==Brands::id()) { return self::missing(); }
        }
        if (preg_match('#^/wc/(?:v[1-3]|store/v1)/products/attributes/(\d+)/terms/(\d+)(?:/|$)#',$route,$m)&&!self::term_allowed((int)$m[2],wc_attribute_taxonomy_name_by_id((int)$m[1]))) { return self::missing(); }
        if (preg_match('#^/(?:wc/(?:v[1-3]|store/v1)/products|wp/v2/(?:product|pages|posts))/(\d+)(?:/variations/(\d+))?#',$route,$m)) {
            if (!self::post_allowed((int)$m[1])||(!empty($m[2])&&(!self::post_allowed((int)$m[2])||wp_get_post_parent_id((int)$m[2])!==(int)$m[1]))) { return self::missing(); }
        }
        return $result;
    }
    private static function slug_id(\WP_REST_Request $request): int {
        $slug=sanitize_title((string)$request->get_param('slug'));global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} b WHERE b.post_id=p.ID AND b.meta_key='_gsm_brand_id' AND b.meta_value=%d) AND (p.post_name=%s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id=p.ID AND s.meta_key='_gsm_public_slug' AND s.meta_value=%s)) ORDER BY p.ID LIMIT 1",Brands::id(),$slug,$slug));
    }
    private static function slug_handler(array $handler): bool {
        $callback=$handler['callback']??null;
        return is_array($callback)&&isset($callback[0])&&$callback[0] instanceof \Automattic\WooCommerce\StoreApi\Routes\V1\ProductsBySlug;
    }
    public static function rest_dispatch(mixed $result,\WP_REST_Request $request,string $route,array $handler): mixed {
        if ($result!==null||!self::scoped()||!self::slug_handler($handler)) { return $result; }
        $id=self::slug_id($request);if (!$id||!self::post_allowed($id)) { return self::missing(); }
        $sub=new \WP_REST_Request('GET','/wc/store/v1/products/'.$id);$sub->set_query_params($request->get_query_params());
        return rest_do_request($sub);
    }
    /** Counts use published brand editions, never Woo's global cached term count. */
    public static function term_stats(int $id,string $tax): array {
        $key=Brands::id().':'.$tax.':'.$id;
        if (isset(self::$stats[$key])) { return self::$stats[$key]; }
        if (!self::term_allowed($id,$tax)) { return ['count'=>0,'review_count'=>0]; }
        $terms=[$id];
        if (is_taxonomy_hierarchical($tax)&&!get_term_meta($id,'_gsm_collection',true)) { $children=get_term_children($id,$tax);if (!is_wp_error($children)) { $terms=array_merge($terms,$children); } }
        $terms=implode(',',array_map('intval',$terms));global $wpdb;
        $where=$wpdb->prepare("p.post_type='product' AND p.post_status='publish' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} b WHERE b.post_id=p.ID AND b.meta_key='_gsm_brand_id' AND b.meta_value=%d) AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy=%s AND tt.term_id IN ($terms))",Brands::id(),$tax);
        $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where");
        $reviews=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE $where AND c.comment_approved='1' AND c.comment_type IN ('review','')");
        return self::$stats[$key]=['count'=>$count,'review_count'=>$reviews];
    }
    public static function rest_response(mixed $response,mixed $server,\WP_REST_Request $request): mixed {
        if (!self::scoped()||!$response instanceof \WP_REST_Response||$response->get_status()>=400) { return $response; }
        $route=$request->get_route();
        if (preg_match('#^/wc/(?:v[1-3]|store/v1)/products/(categories|tags|brands)(?:/(\d+))?$#',$route,$m)) {
            $tax=['categories'=>'product_cat','tags'=>'product_tag','brands'=>'product_brand'][$m[1]];$single=!empty($m[2]);
        } elseif (preg_match('#^/wc/(?:v[1-3]|store/v1)/products/attributes/(\d+)/terms(?:/(\d+))?$#',$route,$m)) {
            $tax=wc_attribute_taxonomy_name_by_id((int)$m[1]);$single=!empty($m[2]);
        } elseif (preg_match('#^/wp/v2/(product_cat|product_tag|product_brand|pa_[\w-]+)(?:/(\d+))?$#',$route,$m)) {
            $tax=$m[1];$single=!empty($m[2]);
        } else { return $response; }
        $data=$response->get_data();$out=[];
        foreach ($single?[$data]:(array)$data as $row) {
            if (!is_array($row)||!self::term_allowed((int)($row['id']??0),$tax)) { continue; }
            $stats=self::term_stats((int)$row['id'],$tax);$row['count']=$stats['count'];
            if (array_key_exists('review_count',$row)) { $row['review_count']=$stats['review_count']; }
            $out[]=$row;
        }
        if ($single&&!$out) { return rest_convert_error_to_response(self::missing()); }
        $response->set_data($single?$out[0]:$out);return $response;
    }
    public static function filter_data(mixed $pre,string $type,array $vars,array $params): mixed {
        if (!self::scoped()||$pre!==null||!in_array($type,['taxonomy','attribute'],true)||!class_exists('Automattic\\WooCommerce\\Internal\\ProductFilters\\FilterDataProvider')) { return $pre; }
        if (!empty($vars['_gsm_filter_scoped'])) { return $pre; }
        $vars['_gsm_filter_scoped']=Brands::id();$meta=(array)($vars['meta_query']??[]);
        $vars['meta_query']=$meta?['relation'=>'AND',$meta,BrandScope::clause()]:[BrandScope::clause()];
        $provider=wc_get_container()->get(\Automattic\WooCommerce\Internal\ProductFilters\FilterDataProvider::class);
        // with() must use the same Store API clauses generator as Woo's caller.
        $filters=$provider->with(new \Automattic\WooCommerce\StoreApi\Utilities\ProductQuery());
        return match($type) {
            'taxonomy'=>$filters->get_taxonomy_counts($vars,(string)($params['taxonomy']??'')),
            'attribute'=>$filters->get_attribute_counts($vars,(string)($params['taxonomy']??'')),
            default=>$pre,
        };
    }
}
