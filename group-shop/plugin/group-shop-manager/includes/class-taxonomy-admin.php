<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Shared vocabulary and store collections have separate native list views. */
final class TaxonomyAdmin {
    private static bool $ordering_shared=false;
    public static function boot(): void {
        add_filter('get_terms_args',[self::class,'list_args'],40,2);
        add_action('admin_init',[self::class,'guard_native'],2);
        add_action('wp_ajax_inline-save-tax',[self::class,'guard_native'],0);
        add_action('wp_ajax_add-tag',[self::class,'guard_native'],0);
        add_action('wp_ajax_woocommerce_term_ordering',[self::class,'guard_native'],0);
        add_filter('get_edit_term_link',static function ($url,$id,$taxonomy) { return self::is_collection((int)$id,(string)$taxonomy)&&Stores::can_manage()?self::collection_url((int)$id):$url; },30,3);
        add_filter('bulk_actions-edit-product_cat',static fn($actions)=>self::list_screen()&&self::list_scope()>0?[]:$actions);
        add_filter('taxonomy_parent_dropdown_args',static function ($args,$taxonomy) {
            if ($taxonomy==='product_cat') { $args['meta_query']=['relation'=>'AND',(array)($args['meta_query']??[]),self::shared_clause()]; }
            return $args;
        },30,2);
        foreach (['product_cat','product_tag'] as $taxonomy) {
            add_filter('manage_edit-'.$taxonomy.'_columns',[self::class,'columns'],30);
            add_filter('manage_'.$taxonomy.'_custom_column',static fn($html,$column,$id)=>self::column((string)$html,(string)$column,(int)$id,$taxonomy),10,3);
            add_filter($taxonomy.'_row_actions',static function ($actions,$term) use ($taxonomy): array {
                if ($taxonomy==='product_cat' && get_term_meta($term->term_id,'_gsm_collection',true) && Stores::can_manage()) {
                    $actions=array_intersect_key($actions,array_flip(['view']));
                    $actions=['gsm_collection'=>'<a href="'.esc_url(self::collection_url((int)$term->term_id)).'">管理商品集合</a>']+$actions;
                }
                return $actions;
            },20,2);
            add_action($taxonomy.'_edit_form_fields',[self::class,'edit_fields'],20,2);
        }
        add_action('admin_notices',[self::class,'notice']);
        add_action('admin_footer-edit-tags.php',[self::class,'filter_form']);
        add_action('admin_enqueue_scripts',static function (): void {
            $screen=get_current_screen();
            if (!$screen || !in_array($screen->taxonomy,['product_cat','product_tag'],true)) { return; }
            wp_enqueue_style('gsm-taxonomy-admin',plugins_url('assets/taxonomy-admin.css',GSM_FILE),[],GSM_VERSION.'.'.filemtime(GSM_DIR.'assets/taxonomy-admin.css'));
            if (self::list_screen()&&self::list_scope()>0) { wp_dequeue_script('woocommerce_term_ordering'); wp_add_inline_style('gsm-taxonomy-admin','#col-left{display:none!important}#col-right{float:none;width:100%}'); }
        },100);
    }
    private static function list_screen(): bool {
        global $pagenow;
        return is_admin()&&$pagenow==='edit-tags.php'&&($_REQUEST['taxonomy']??'')==='product_cat'&&!wp_doing_ajax()&&in_array($_REQUEST['action']??'',['','-1'],true)&&in_array($_REQUEST['action2']??'',['','-1'],true);
    }
    public static function list_scope(): int { return Brands::root()?absint($_GET['gsm_brand']??0):Brands::id(); }
    private static function shared_clause(): array { return ['key'=>'_gsm_collection','compare'=>'NOT EXISTS']; }
    public static function list_args(array $args,array $taxonomies): array {
        // Never filter the id=>parent query that populates WordPress's global hierarchy cache.
        if ((!self::list_screen()&&!self::$ordering_shared)||Brands::unrestricted()||$taxonomies!==['product_cat']||!in_array($args['fields']??'all',['all','count'],true)) { return $args; }
        $scope=self::$ordering_shared?0:self::list_scope();
        if (!$scope) {
            // NOT EXISTS makes WP convert all meta joins to LEFT JOIN, breaking Woo's
            // optional order-meta join for categories that have only other metadata.
            global $wpdb;
            $collections=array_map('intval',$wpdb->get_col("SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key='_gsm_collection'"));
            $args['exclude']=array_unique(array_merge(wp_parse_id_list($args['exclude']??[]),$collections));
            return $args;
        }
        $filter=['relation'=>'AND',['key'=>'_gsm_collection','value'=>1],BrandScope::clause($scope)];
        $args['meta_query']=['relation'=>'AND',(array)($args['meta_query']??[]),$filter];
        return $args;
    }
    /** Collection writes belong to the complete, nonce-protected collection editor. */
    public static function guard_native(): void {
        global $pagenow;
        if (($_REQUEST['taxonomy']??$_REQUEST['thetaxonomy']??'')!=='product_cat') { return; }
        if (self::list_screen()&&Brands::root()&&self::list_scope()&&!isset(Brands::registry()[self::list_scope()])) { wp_die('店铺不存在。','分类范围无效',['response'=>404]); }
        if (!in_array($pagenow,['edit-tags.php','term.php','admin-ajax.php'],true)) { return; }
        $action=(string)($_REQUEST['action']??'');
        if ($action==='woocommerce_term_ordering') {
            if (!Stores::can_manage()||self::is_collection(absint($_REQUEST['id']??0),'product_cat')||self::is_collection(absint($_REQUEST['nextid']??0),'product_cat')) { wp_die('集合排序请在商品集合管理中设置。','请使用集合管理',['response'=>403]); }
            self::$ordering_shared=true;
        }
        $ids=wp_parse_id_list($_REQUEST['delete_tags']??[$_REQUEST['tag_ID']??$_REQUEST['tax_ID']??0]);
        foreach ($ids as $id) {
            if (!self::is_collection($id,'product_cat')) { continue; }
            if (!Stores::can_manage()) { wp_die('没有集合管理权限。','权限不足',['response'=>403]); }
            if ($pagenow==='term.php'&&($_SERVER['REQUEST_METHOD']??'GET')==='GET') { wp_safe_redirect(wp_make_link_relative(self::collection_url($id))); exit; }
            if (in_array($action,['editedtag','delete','bulk-delete','inline-save-tax'],true)||($_REQUEST['action2']??'')==='delete') { wp_die('请在商品集合管理中编辑、删除或恢复集合。','请使用集合管理',['response'=>403]); }
        }
        if (in_array($action,['add-tag','editedtag','inline-save-tax'],true)) {
            $parent=absint($_REQUEST['parent']??0);
            if ($parent&&self::is_collection($parent,'product_cat')) { wp_die('共享分类不能使用商品集合作为父级。','父级无效',['response'=>403]); }
            if ($action==='add-tag'&&absint($_REQUEST['gsm_brand']??0)>0) { wp_die('请在商品集合管理中创建店铺集合。','请使用集合管理',['response'=>403]); }
        }
    }
    public static function columns(array $columns): array {
        $result=[];
        foreach ($columns as $key=>$label) {
            $result[$key]=$label;
            if ($key==='name') { $result['gsm_scope']='类型'; $result['gsm_store']='所属品牌／范围'; }
        }
        return $result;
    }
    private static function is_collection(int $id,string $taxonomy): bool { return $taxonomy==='product_cat' && (bool)get_term_meta($id,'_gsm_collection',true); }
    private static function collection_url(int $id): string {
        $owner=(int)get_term_meta($id,'_gsm_brand_id',true)?:Brands::id(); $rule=Stores::within($owner,static fn()=>Collections::collections()[$id]??null);
        $args=['store'=>$owner];
        if ($rule && !empty($rule['deleted_at'])) { $args['view']='trash'; }
        elseif ($rule) { $args['collection']=$id; }
        return Admin::url('collections',$args);
    }
    public static function column(string $html,string $column,int $id,string $taxonomy): string {
        if (!in_array($column,['gsm_scope','gsm_store'],true) || !term_exists($id,$taxonomy)) { return $html; }
        $collection=self::is_collection($id,$taxonomy);
        if ($column==='gsm_scope') {
            if (!$collection) { return '<span class="gsm-taxonomy-kind">共享'.($taxonomy==='product_tag'?'标签':'分类').'</span>'; }
            $owner=(int)get_term_meta($id,'_gsm_brand_id',true)?:Brands::id(); $rule=Stores::within($owner,static fn()=>Collections::collections()[$id]??null);
            $state=!$rule?'规则缺失':(!empty($rule['deleted_at'])?'回收站':(!empty($rule['needs_review'])?'需修正规则':(!empty($rule['enabled'])?'已启用':'已停用')));
            return '<span class="gsm-taxonomy-kind is-collection">商品集合</span><br><span class="description">'.esc_html($state).'</span>';
        }
        if (!$collection) { return '<strong>全站共享</strong><br><span class="description">由商城统一维护</span>'; }
        $store=(int)get_term_meta($id,'_gsm_brand_id',true)?:Brands::id();
        $label=Brands::name($store).'（'.($store===Stores::main_id()?'主店 ':'店铺 ').'#'.$store.'）';
        $label=esc_html($label);
        if (Stores::can_manage()) { $label='<a href="'.esc_url(self::collection_url($id)).'">'.$label.'</a>'; }
        return $label.'<br><span class="description gsm-taxonomy-store-url">'.esc_html(Brands::url($store)).'</span>';
    }
    public static function edit_fields(\WP_Term $term,string $taxonomy): void {
        echo '<tr class="form-field"><th scope="row">类型</th><td>'.self::column('','gsm_scope',(int)$term->term_id,$taxonomy).'</td></tr><tr class="form-field"><th scope="row">所属品牌／范围</th><td>'.self::column('','gsm_store',(int)$term->term_id,$taxonomy);
        if (self::is_collection((int)$term->term_id,$taxonomy) && Stores::can_manage()) {
            echo '<p><a class="button" href="'.esc_url(self::collection_url((int)$term->term_id)).'">管理商品集合</a> <span class="description">选品条件、启用状态及删除恢复请在集合管理中操作。</span></p>';
        }
        echo '</td></tr>';
    }
    public static function notice(): void {
        if (!function_exists('get_current_screen')) { return; }
        $screen=get_current_screen();
        if (!$screen || !in_array($screen->base,['edit-tags','term'],true) || !in_array($screen->taxonomy,['product_cat','product_tag'],true)) { return; }
        echo '<div class="notice notice-info gsm-taxonomy-notice"><p>普通产品分类和标签为<strong>全站共享</strong>；商品集合及其父子层级按店铺独立管理。</p></div>';
    }
    public static function filter_form(): void {
        if (self::list_screen()&&Stores::can_manage()) {
            $scope=self::list_scope();
            echo '<form method="get" action="'.esc_url(admin_url('edit-tags.php')).'" class="gsm-taxonomy-filter"><input type="hidden" name="taxonomy" value="product_cat"><input type="hidden" name="post_type" value="product"><label for="gsm-taxonomy-brand">分类范围</label> <select id="gsm-taxonomy-brand" name="gsm_brand"><option value="0" '.selected($scope,0,false).'>共享分类</option>';
            foreach (Stores::all() as $id=>$name) { echo '<option value="'.(int)$id.'" '.selected($scope,$id,false).'>'.esc_html($name).' · 商品集合</option>'; }
            echo '</select> <button class="button" type="submit">切换</button>';
            if ($scope) { echo ' <a class="button button-primary" href="'.esc_url(Admin::url('collections',['store'=>$scope,'collection'=>'new'])).'">创建集合</a> <a href="'.esc_url(Admin::url('collections',['store'=>$scope])).'">管理该店铺集合</a>'; }
            echo '<p class="description">'.($scope?'当前只显示所选店铺的商品集合；编辑、父级、选品及回收站统一在集合管理中设置。':'当前只显示全商城共用的商品分类，按店铺切换可查看对应商品集合。').'</p></form>';
            // Woo's notification drawer collects admin_notices, including arbitrary forms.
            echo '<script>(()=>{const f=document.querySelector(".gsm-taxonomy-filter"),search=document.querySelector(".search-form");if(f&&search){search.before(f);}})();</script>';
        }
    }
}
