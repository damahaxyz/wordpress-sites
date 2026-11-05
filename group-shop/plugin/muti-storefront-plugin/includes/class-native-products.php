<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Native product lists and effective editing controls, without a second product store. */
final class NativeProducts {
    private static array $families=[];
    public static function boot(): void {
        add_action('restrict_manage_posts',[self::class,'filters'],20,2);
        add_action('pre_get_posts',[self::class,'query'],40);
        add_filter('manage_edit-product_columns',static fn($columns)=>$columns+['gsm_brand'=>'商品归属','gsm_base'=>'公共商品','gsm_versions'=>'品牌版本']);
        add_action('manage_product_posts_custom_column',[self::class,'column'],10,2);
        add_filter('post_row_actions',[self::class,'row_actions'],20,2);
        add_filter('bulk_actions-edit-product',static function ($actions) {
            global $wp_query;
            foreach ($wp_query->posts??[] as $post) { if (get_post_meta($post->ID,'_gsm_base_product',true)) { unset($actions['edit']); break; } }
            return $actions;
        });
        add_filter('get_edit_post_link',static function ($url,$id) { return in_array(get_post_type($id),['product','product_variation'],true)?self::edit_url((int)$id):$url; },30,2);
        add_filter('views_edit-product',static function ($views) {
            $args=['gsm_brand'=>self::list_scope()];
            if (self::list_scope()>0&&!empty($_GET['gsm_base'])) { $args['gsm_base']=absint($_GET['gsm_base']); }
            foreach ($views as &$view) { $html=new \WP_HTML_Tag_Processor($view); while ($html->next_tag('a')) { $url=$html->get_attribute('href');if (is_string($url)) { $html->set_attribute('href',add_query_arg($args,remove_query_arg('gsm_products',$url))); } } $view=$html->get_updated_html(); }
            return $views;
        });
        add_filter('wp_count_posts',[self::class,'counts'],30,3);
        add_action('admin_enqueue_scripts',[self::class,'assets'],30);
    }
    public static function edit_url(int $id): string {
        if (get_post_type($id)==='product_variation') { $id=wp_get_post_parent_id($id); }
        $brand=BrandScope::product_brand($id);
        return Brands::root()?Brands::admin_url($brand,'post.php?post='.$id.'&action=edit'):Brands::url($brand).'/wp-admin/post.php?post='.$id.'&action=edit';
    }
    public static function family(int $base): array {
        if (!isset(self::$families[$base])) {
            self::$families[$base]=Brands::unscoped(static fn()=>get_posts(['post_type'=>'product','post_status'=>['publish','draft','pending','private','future'],'numberposts'=>-1,'meta_key'=>'_gsm_base_product','meta_value'=>$base,'orderby'=>'ID','order'=>'ASC']));
        }
        return array_values(array_filter(self::$families[$base],static fn($post)=>Brands::root()||Identities::accessible(get_current_user_id(),BrandScope::product_brand($post->ID))));
    }
    public static function filters(string $type,string $which='top'): void {
        if ($type!=='product'||$which==='bottom') { return; }
        $scope=self::list_scope();
        echo '<label class="screen-reader-text" for="gsm-brand-filter">筛选商品归属</label><select id="gsm-brand-filter" name="gsm_brand">';
        if (Brands::root()) { echo '<option value="0" '.selected($scope,0,false).'>公共商品</option>'; }
        foreach (Stores::all() as $id=>$name) { if (Brands::root()||Identities::accessible(get_current_user_id(),(int)$id)) { echo '<option value="'.(int)$id.'" '.selected($scope,$id,false).'>'.esc_html($name).'</option>'; } }
        echo '</select>';
        if (self::list_scope()>0&&!empty($_GET['gsm_base'])) {
            $base=absint($_GET['gsm_base']);$can_name=Brands::root()||self::family($base);
            $name=$can_name&&get_post_meta($base,'_gsm_kind',true)==='base'?get_the_title($base):'公共商品 #'.$base;
            echo '<input type="hidden" name="gsm_base" value="'.$base.'"><span class="gsm-family-filter">关联公共商品：'.esc_html($name).' <a href="'.esc_url(remove_query_arg(['gsm_base','paged'])).'">清除关联筛选</a></span>';
        }
    }
    public static function query(\WP_Query $query): void {
        if (!is_admin()||!$query->is_main_query()||$query->get('post_type')!=='product'||Brands::unrestricted()) { return; }
        $meta=array_merge((array)$query->get('meta_query'),self::selection_meta());
        $query->set('meta_query',$meta);
    }
    /** Products has one catalog selector; zero means public, never all brands. */
    public static function list_scope(): int { return Brands::root()?absint($_GET['gsm_brand']??0):Brands::id(); }
    private static function selection_meta(): array {
        $brand=self::list_scope();$meta=[BrandScope::clause($brand)];
        $meta[]=$brand?['key'=>'_gsm_base_product','value'=>0,'compare'=>'>','type'=>'NUMERIC']:['key'=>'_gsm_kind','value'=>'base'];
        $base=absint($_GET['gsm_base']??0);
        if ($base&&$brand) { $meta[]=['key'=>'_gsm_base_product','value'=>$base,'type'=>'NUMERIC']; }
        return $meta;
    }
    public static function counts($counts,string $type,string $perm) {
        if ($type!=='product'||!is_admin()||(get_current_screen()->id??'')!=='edit-product'||Brands::unrestricted()) { return $counts; }
        $meta=self::selection_meta();
        if (!$meta) { return $counts; }
        global $wpdb;$mq=new \WP_Meta_Query($meta);$sql=$mq->get_sql('post',$wpdb->posts,'ID');$result=(object)array_fill_keys(get_post_stati(),0);
        foreach ($wpdb->get_results("SELECT {$wpdb->posts}.post_status,COUNT(DISTINCT {$wpdb->posts}.ID) AS total FROM {$wpdb->posts} {$sql['join']} WHERE {$wpdb->posts}.post_type='product' {$sql['where']} GROUP BY {$wpdb->posts}.post_status") as $row) { $result->{$row->post_status}=(int)$row->total; }
        return $result;
    }
    public static function column(string $column,int $id): void {
        if (!in_array($column,['gsm_brand','gsm_base','gsm_versions'],true)) { return; }
        $brand=BrandScope::product_brand($id); $base=(int)get_post_meta($id,'_gsm_base_product',true);
        if ($column==='gsm_brand') { echo esc_html($brand?Brands::name($brand):'公共商品').'<br><span class="description">'.esc_html($brand?'继承版本':'不直接销售').'</span>'; return; }
        if ($column==='gsm_base') {
            if (!$base) { echo $brand?'公共来源缺失，请联系总管理员':'—'; return; }
            $label=get_the_title($base).' (#'.$base.')';
            echo Brands::root()?'<a href="'.esc_url(self::edit_url($base)).'">'.esc_html($label).'</a>':esc_html($label);
            $over=(array)get_post_meta($id,'_gsm_overrides',true); $labels=array_intersect_key(ProductVersions::FIELDS,array_flip($over));
            echo '<br><span class="description">'.esc_html($labels?'单独设置：'.implode('、',$labels):'商品字段继承公共资料').'</span>'; return;
        }
        $source=$brand?$base:$id;
        if (!$source) { echo '—'; return; }
        $rows=self::family($source);
        if (!$rows) { echo '尚无品牌版本'; return; }
        foreach ($rows as $row) { echo '<div><a href="'.esc_url(self::edit_url($row->ID)).'">'.esc_html(Brands::name(BrandScope::product_brand($row->ID))).'</a> <span class="description">'.esc_html(wc_get_product($row->ID)?->get_status()==='publish'?'已上架':'未上架').'</span></div>'; }
    }
    public static function row_actions(array $actions,\WP_Post $post): array {
        if ($post->post_type!=='product') { return $actions; }
        $base=(int)get_post_meta($post->ID,'_gsm_base_product',true);
        if (get_post_meta($post->ID,'_gsm_kind',true)==='base') { unset($actions['view']); }
        if ($base) { unset($actions['inline hide-if-no-js']); if (Brands::root()) { $actions['gsm_base']='<a href="'.esc_url(self::edit_url($base)).'">编辑公共商品</a>'; } }
        if ($base) { unset($actions['duplicate']); }
        return $actions;
    }
    public static function assets(): void {
        $screen=get_current_screen();
        if (!$screen||$screen->post_type!=='product') { return; }
        wp_enqueue_style('gsm-native-products',plugins_url('assets/native-products.css',GSM_FILE),[],GSM_VERSION.'.'.filemtime(GSM_DIR.'assets/native-products.css'));
        wp_enqueue_script('gsm-native-products',plugins_url('assets/native-products.js',GSM_FILE),['jquery'],GSM_VERSION.'.'.filemtime(GSM_DIR.'assets/native-products.js'),true);
        $id=absint($_GET['post']??0);
        wp_localize_script('gsm-native-products','gsmNativeProduct',['baseLabel'=>$id>0?get_the_title((int)get_post_meta($id,'_gsm_base_product',true)):'','brand'=>$id>0?BrandScope::product_brand($id):Brands::id(),'linked'=>$id>0&&(int)get_post_meta($id,'_gsm_base_product',true)>0,'overrides'=>(array)get_post_meta($id,'_gsm_overrides',true)]);
    }
}
