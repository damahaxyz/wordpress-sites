<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Native pages are independent documents owned by one brand; no template system. */
final class NativePages {
    private static array $owners=[];
    private static bool $writing=false;
    public static function scope(): int {return Brands::root()?max(1,absint($_REQUEST['gsm_brand']??Brands::id())):Brands::id();}
    public static function owner(int $id): int {return (int)get_post_meta($id,'_gsm_brand_id',true);}
    public static function edit_url(int $id): string {return add_query_arg('gsm_brand',self::owner($id),admin_url('post.php?post='.$id.'&action=edit'));}
    public static function boot(): void {
        // Retire only the old single-store policy writer, which can overwrite pages
        // by global slug. Native privacy/terms settings and existing content remain.
        if(class_exists('Trovesia\\Plugin\\Site_Experience')){remove_action('init',[\Trovesia\Plugin\Site_Experience::instance(),'install_policy_pages'],20);}
        add_action('admin_init',static function():void{
            global $pagenow;if(in_array($pagenow,['edit.php','post-new.php'],true)&&($_REQUEST['post_type']??'')==='page'){
                try{Stores::assert_store(self::scope());}catch(\Throwable $e){wp_die('店铺不存在。','页面范围无效',['response'=>404]);}
            }
        },1);
        add_action('pre_get_posts',static function($q):void{
            if(is_admin()&&$q->is_main_query()&&$q->get('post_type')==='page'&&!Brands::unrestricted()){$meta=(array)$q->get('meta_query');$meta[]=BrandScope::clause(self::scope());$q->set('meta_query',$meta);}
        },40);
        add_filter('rest_page_query',static function($args){if(!Brands::unrestricted()){$args['meta_query'][]=BrandScope::clause(Brands::query_scope()??Brands::id());}return $args;});
        add_filter('page_attributes_dropdown_pages_args',static function($args,$post){$args['meta_query'][]=BrandScope::clause(self::owner((int)$post->ID));return $args;},20,2);
        add_action('restrict_manage_posts',[self::class,'filters'],20,2);
        add_filter('manage_pages_columns',static function($columns){$out=[];foreach($columns as $key=>$label){$out[$key]=$label;if($key==='title'){$out['gsm_page_brand']='所属店铺';}}return $out;});
        add_action('manage_pages_custom_column',static function($column,$id):void{if($column==='gsm_page_brand'){echo esc_html(Brands::name(self::owner((int)$id)));}},10,2);
        add_filter('get_edit_post_link',static fn($url,$id)=>get_post_type($id)==='page'?self::edit_url((int)$id):$url,40,2);
        add_filter('admin_url',static function($url,$path){$screen=function_exists('get_current_screen')?get_current_screen():null;return $path==='post-new.php?post_type=page'&&$screen?->post_type==='page'?add_query_arg('gsm_brand',self::scope(),$url):$url;},30,2);
        add_filter('views_edit-page',[self::class,'views']);
        add_filter('wp_count_posts',[self::class,'counts'],40,3);
        add_action('add_meta_boxes_page',static fn()=>add_meta_box('gsm-page-brand','所属店铺',[self::class,'metabox'],'page','side','high'));
        add_filter('wp_insert_post_empty_content',[self::class,'guard_write'],20,2);
        add_action('wp_after_insert_post',[self::class,'saved'],20,2);
        foreach(['add_post_metadata','update_post_metadata','delete_post_metadata'] as $hook){
            add_filter($hook,static function($check,$id,$key){
                if($key!=='_gsm_brand_id'||get_post_type($id)!=='page'||self::$writing){return $check;}
                // Ownership is assigned by the validated page save, never by a free meta write.
                return false;
            },25,3);
        }
        add_filter('rest_pre_insert_page',[self::class,'rest_before'],20,2);
        add_filter('preview_post_link',static function($url,$post){
            if($post->post_type!=='page'||!current_user_can('edit_post',$post->ID)){return $url;}
            $args=[];parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$args);
            return add_query_arg(['page_id'=>$post->ID,'preview'=>'true','gsm_preview_nonce'=>wp_create_nonce('gsm_page_preview_'.$post->ID)]+array_intersect_key($args,array_flip(['preview_id','preview_nonce'])),trailingslashit(get_option('siteurl')));
        },30,2);
        add_action('template_redirect',static function():void{
            $id=get_queried_object_id();if(is_preview()&&get_post_type($id)==='page'&&self::owner($id)===Brands::id()&&current_user_can('edit_post',$id)&&wp_verify_nonce((string)($_GET['gsm_preview_nonce']??''),'gsm_page_preview_'.$id)){remove_action('template_redirect','redirect_canonical');nocache_headers();}
        },-1);
        add_filter('redirect_canonical',static function($url){
            if(!$url||!is_404()){return $url;}$host=wp_parse_url($url,PHP_URL_HOST);
            foreach(Brands::registry() as $brand=>$row){if($row['domain']===$host&&(int)$brand!==Brands::id()){return false;}}
            return $url;
        },30);
        add_action('admin_enqueue_scripts',static function():void{
            if((get_current_screen()->id??'')==='edit-page'){wp_add_inline_style('common','.wp-list-table .column-gsm_page_brand{width:140px}');}
        });
    }
    public static function filters(string $type,string $which='top'): void {
        if($type!=='page'||$which==='bottom'){return;}$scope=self::scope();
        echo '<label for="gsm-page-brand" class="screen-reader-text">所属店铺</label><select id="gsm-page-brand" name="gsm_brand">';
        foreach(Stores::all() as $brand=>$name){if(Brands::root()||(Identities::accessible(get_current_user_id(),(int)$brand)&&Stores::within((int)$brand,static fn()=>current_user_can('edit_pages')))){echo '<option value="'.(int)$brand.'" '.selected($scope,$brand,false).'>'.esc_html($name).'</option>';}}echo '</select>';
    }
    public static function metabox(\WP_Post $post): void {echo '<p><strong>'.esc_html(Brands::name(self::owner($post->ID))).'</strong></p><p>该页面由此店铺独立维护，保存后不能更换归属。新建其他店铺页面请先回页面列表切换店铺。</p>';}
    public static function views(array $views): array {
        foreach($views as &$view){$html=new \WP_HTML_Tag_Processor($view);while($html->next_tag('a')){$href=$html->get_attribute('href');if(is_string($href)){$html->set_attribute('href',add_query_arg('gsm_brand',self::scope(),$href));}}$view=$html->get_updated_html();}unset($view);
        if(isset($views['mine'])){
            global $wpdb;$sql=self::meta_sql();$mine=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT {$wpdb->posts}.ID) FROM {$wpdb->posts} {$sql['join']} WHERE post_type='page' AND post_author=%d AND post_status NOT IN ('trash','auto-draft') {$sql['where']}",get_current_user_id()));
            if($mine){$views['mine']=preg_replace('/(<span class="count">)\([0-9,]+\)(<\/span>)/','$1('.number_format_i18n($mine).')$2',$views['mine']);}else{unset($views['mine']);}
        }
        return $views;
    }
    private static function meta_sql(): array {global $wpdb;$mq=new \WP_Meta_Query([BrandScope::clause(self::scope())]);return $mq->get_sql('post',$wpdb->posts,'ID');}
    public static function counts($counts,string $type,string $perm){
        if($type!=='page'||!is_admin()||(get_current_screen()->id??'')!=='edit-page'||Brands::unrestricted()){return $counts;}
        global $wpdb;$sql=self::meta_sql();$out=(object)array_fill_keys(get_post_stati(),0);
        foreach($wpdb->get_results("SELECT {$wpdb->posts}.post_status,COUNT(DISTINCT {$wpdb->posts}.ID) total FROM {$wpdb->posts} {$sql['join']} WHERE post_type='page' {$sql['where']} GROUP BY {$wpdb->posts}.post_status") as $row){$out->{$row->post_status}=(int)$row->total;}return $out;
    }
    private static function validate(array $input): int {
        $id=absint($input['ID']??0);$existing=$id&&get_post_type($id)==='page';$old=$existing?self::owner($id):0;
        $brand=$existing?$old:(Brands::root()?absint($input['meta_input']['_gsm_brand_id']??self::scope()):Brands::id());
        Stores::assert_store($brand);
        if(isset($input['meta_input']['_gsm_brand_id'])&&(int)$input['meta_input']['_gsm_brand_id']!==$brand){throw new \RuntimeException('页面所属店铺不能更换。');}
        if(!Brands::root()&&(!Identities::accessible(get_current_user_id(),$brand)||$brand!==Brands::id()||!current_user_can($existing?'edit_post':'edit_pages',$id))){throw new \RuntimeException('没有该店铺页面的编辑权限。');}
        $parent=absint($input['post_parent']??($existing?wp_get_post_parent_id($id):0));
        if($parent&&(get_post_type($parent)!=='page'||self::owner($parent)!==$brand||$parent===$id||($id&&in_array($id,get_post_ancestors($parent),true)))){throw new \RuntimeException('父页面必须属于同店铺，且不能形成循环。');}
        return $brand;
    }
    public static function rest_before(mixed $prepared,\WP_REST_Request $request): mixed {
        if(is_wp_error($prepared)){return $prepared;}$input=['ID'=>absint($request->get_param('id')),'post_type'=>'page','meta_input'=>(array)$request->get_param('meta')];if($request->has_param('parent')){$input['post_parent']=$request->get_param('parent');}
        try{self::validate($input);return $prepared;}catch(\Throwable $e){return new \WP_Error('gsm_page_scope',$e->getMessage(),['status'=>403]);}
    }
    public static function guard_write(bool $empty,array $input): bool {
        if(($input['post_type']??get_post_type(absint($input['ID']??0)))!=='page'){return $empty;}
        try{self::$owners[absint($input['ID']??0)]=self::validate($input);return $empty;}catch(\Throwable $e){return true;}
    }
    public static function saved(int $id,\WP_Post $post): void {
        if($post->post_type!=='page'){return;}$owner=self::$owners[$id]??self::$owners[0]??null;unset(self::$owners[$id],self::$owners[0]);if($owner===null){return;}
        self::$writing=true;try{
            if(!metadata_exists('post',$id,'_gsm_brand_id')){update_post_meta($id,'_gsm_brand_id',$owner);}
            if($post->post_status!=='auto-draft'&&in_array((string)get_post_meta($id,'_gsm_public_slug',true),['','auto-draft'],true)){update_post_meta($id,'_gsm_public_slug',$post->post_name?:sanitize_title($post->post_title));}
        }finally{self::$writing=false;}
    }
}
