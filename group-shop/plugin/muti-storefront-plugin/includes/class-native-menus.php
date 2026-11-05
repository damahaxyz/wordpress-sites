<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Classic native menus are owned by one store; shared taxonomies remain shared. */
final class NativeMenus {
    private const AJAX=['add-menu-item','delete-menu-item','menu-locations-save','menu-quick-search','menu-get-metabox'];
    private const POSTS=['page','post','product'];
    private const TAXONOMIES=['product_cat','product_tag','category','post_tag'];
    public static function request_context(): bool {
        return ($GLOBALS['pagenow']??basename((string)($_SERVER['SCRIPT_NAME']??'')))==='nav-menus.php'
            || (wp_doing_ajax()&&in_array($_REQUEST['action']??'',self::AJAX,true));
    }
    public static function owner(int $id): int { return (int)get_term_meta($id,'_gsm_brand_id',true); }
    public static function here(int $id): bool { $term=get_term($id,'nav_menu');return $term instanceof \WP_Term&&self::owner($id)===Brands::id(); }
    public static function can_manage(): bool {
        $id=get_current_user_id();$roles=(array)get_user_meta($id,'_gsm_brand_roles',true);
        return Brands::root()||((Identities::get($id)['kind']??'')==='staff'&&($roles[Brands::id()]??'')==='administrator');
    }
    public static function boot(): void {
        add_action('load-nav-menus.php',[self::class,'load']);
        foreach(self::AJAX as $action){add_action('wp_ajax_'.$action,[self::class,'ajax'],0);}
        add_action('admin_notices',[self::class,'selector']);
        add_action('admin_enqueue_scripts',[self::class,'assets']);
        add_filter('admin_url',static function($url,$path){return self::request_context()&&str_starts_with($path,'nav-menus.php')?add_query_arg(['gsm_brand'=>Brands::id(),'gsm_menu_scope_nonce'=>wp_create_nonce('gsm_menu_scope_'.Brands::id())],$url):$url;},20,2);
        add_action('admin_menu',static function(){if(!Brands::root()&&self::can_manage()){add_menu_page('店铺菜单','店铺菜单','gsm_manage_menus','nav-menus.php','','dashicons-menu',61);}});
        add_filter('wp_get_nav_menu_object',static fn($menu)=>Brands::unrestricted()||!$menu||self::here((int)$menu->term_id)?$menu:false);
        add_filter('theme_mod_nav_menu_locations',[self::class,'locations']);
        add_filter('pre_option',static function($pre,$key){if($key==='nav_menu_options'&&!Brands::unrestricted()){return self::auto_options(is_array($pre)?$pre:[]);}return $pre;},15,2);
        add_filter('pre_update_option',[self::class,'option_write'],4,3);
        foreach(['add_term_metadata','update_term_metadata','delete_term_metadata'] as $hook){add_filter($hook,static function($check,$id,$key){if($key==='_gsm_brand_id'&&get_term($id,'nav_menu') instanceof \WP_Term&&metadata_exists('term',$id,$key)&&!Brands::unrestricted()){return false;}return $check;},50,3);}
        add_filter('nav_menu_meta_box_object',static function($object){$allowed=$object instanceof \WP_Post_Type?self::POSTS:self::TAXONOMIES;return in_array($object->name??'',$allowed,true)?$object:false;},50);
        add_filter('wp_get_nav_menu_items',[self::class,'items'],50,3);
        add_filter('get_terms_args',[self::class,'picker_terms'],40,2);
        add_filter('hidden_meta_boxes',static function($hidden,$screen){return $screen->id==='nav-menus'?array_values(array_diff($hidden,['add-post-type-product','add-product_cat'])):$hidden;},20,2);
        add_action('admin_init',static function(){if(in_array($GLOBALS['pagenow']??'',['edit-tags.php','term.php'],true)&&($_REQUEST['taxonomy']??'')==='nav_menu'){self::fail('请使用原生店铺菜单编辑器。');}if(($GLOBALS['pagenow']??'')==='post.php'&&get_post_type(absint($_REQUEST['post']??$_REQUEST['post_ID']??0))==='nav_menu_item'){self::fail('请使用原生店铺菜单编辑器。');}},5);
        add_filter('rest_pre_dispatch',static function($result,$server,$request){if(preg_match('~^/wp/v2/(?:menus|menu-items|menu-locations|navigation)(?:/|$)~',$request->get_route())){return new \WP_Error('gsm_native_menus','请在外观 → 菜单按店铺管理菜单。',['status'=>403]);}return $result;},20,3);
        // Keep one fully validated editor; the global Customizer has no store switch.
        add_filter('customize_loaded_components',static fn($components)=>array_values(array_diff($components,['nav_menus'])));
        remove_action('transition_post_status','_wp_auto_add_pages_to_menu',10);
        add_action('wp_after_insert_post',static function($id,$post,$update,$before){if($post->post_type!=='page'||$post->post_status!=='publish'||($before&&$before->post_status==='publish')||$post->post_parent){return;}$brand=(int)get_post_meta($id,'_gsm_brand_id',true);if(isset(Brands::registry()[$brand])){Brands::within($brand,static fn()=>_wp_auto_add_pages_to_menu('publish',$before->post_status??'new',$post));}},40,4);
    }
    public static function picker_terms(array $args,array $taxonomies): array {
        if(!self::request_context()||Brands::unrestricted()||!array_intersect($taxonomies,self::TAXONOMIES)||(($args['fields']??'')==='id=>parent'&&($args['get']??'')==='all')){return $args;}
        global $wpdb;$exclude=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key='_gsm_brand_id' AND CAST(meta_value AS UNSIGNED)<>%d",Brands::id())));
        foreach(Collections::collections() as $id=>$rule){if(empty($rule['enabled'])||!empty($rule['deleted_at'])){$exclude[]=(int)$id;}}
        $args['exclude']=array_unique(array_merge(wp_parse_id_list($args['exclude']??[]),$exclude));return $args;
    }
    public static function locations($locations): array { return Brands::unrestricted()?(array)$locations:array_map('intval',array_filter((array)$locations,static fn($id)=>!$id||self::here((int)$id))); }
    private static function auto_options(array $options): array { $options['auto_add']=array_values(array_filter(array_map('intval',(array)($options['auto_add']??[])),[self::class,'here']));return $options; }
    public static function option_write($value,string $key,$old) {
        if(Brands::unrestricted()){return $value;}
        if(str_starts_with($key,'theme_mods_')&&is_array($value)&&isset($value['nav_menu_locations'])){$locations=(array)$value['nav_menu_locations'];if(self::locations($locations)!==array_map('intval',$locations)){return $old;}}
        if($key==='nav_menu_options'&&is_array($value)&&self::auto_options($value)!==$value){return $old;}
        return $value;
    }
    /** Return an error before ANY native request writes menu names, items or locations. */
    private static function fail(string $message,int $status=403): never { wp_die(esc_html($message),'店铺菜单',['response'=>$status]); }
    private static function scope(bool $signed): void {
        $brand=absint($_REQUEST['gsm_brand']??0);
        if(isset($_REQUEST['gsm_brand'])&&(!isset(Brands::registry()[$brand])||$brand!==Brands::id())){self::fail('请选择有效店铺。',404);}
        if(!self::can_manage()){self::fail('没有当前店铺的菜单管理权限。');}
        if($signed&&(!$brand||!wp_verify_nonce((string)($_REQUEST['gsm_menu_scope_nonce']??''),'gsm_menu_scope_'.$brand))){self::fail('店铺菜单签名无效，请刷新页面。');}
    }
    public static function load(): void {
        require_once ABSPATH.'wp-admin/includes/nav-menu.php';
        _wp_expand_nav_menu_post_data();unset($_POST['nav-menu-data']);
        $_REQUEST=array_replace($_REQUEST,$_POST);
        $action=(string)($_REQUEST['action']??'edit');
        self::scope(!in_array($action,['edit','locations'],true)||!empty($_POST));
        $menu=absint($_REQUEST['menu']??0);
        if($menu&&!self::here($menu)){self::fail('菜单不属于当前店铺。',404);}
        if(isset($_REQUEST['delete_menus'])){foreach((array)$_REQUEST['delete_menus'] as $id){if(!self::here(absint($id))){self::fail('不能删除其他店铺的菜单。');}}}
        if(in_array($action,['move-down-menu-item','move-up-menu-item','delete-menu-item'],true)){self::require_item(absint($_REQUEST['menu-item']??0),$menu);}
        self::validate_locations((array)($_REQUEST['menu-locations']??[]));
        if($action==='add-menu-item'){self::validate_rows((array)($_REQUEST['menu-item']??[]),$menu);}
        if($action==='update'){
            $rows=[];
            foreach((array)($_POST['menu-item-db-id']??[]) as $key=>$id){$row=[];foreach($_POST as $field=>$values){if(str_starts_with($field,'menu-item-')&&is_array($values)){$row[$field]=$values[$key]??'';}}$rows[$key]=$row;}
            self::validate_rows($rows,$menu);
        }
    }
    public static function ajax(): void {
        self::scope(true);$action=(string)$_REQUEST['action'];$menu=absint($_POST['menu']??0);
        if($menu&&!self::here($menu)){self::fail('菜单不属于当前店铺。');}
        if($action==='add-menu-item'){self::validate_rows((array)($_POST['menu-item']??[]),$menu);}
        if($action==='delete-menu-item'){self::require_item(absint($_POST['menu-item']??0),$menu);}
        if($action==='menu-locations-save'){self::validate_locations((array)($_POST['menu-locations']??[]));}
        if($action==='menu-get-metabox'){$types=($_POST['item-type']??'')==='post_type'?self::POSTS:self::TAXONOMIES;if(!in_array($_POST['item-object']??'',$types,true)){self::fail('不支持该菜单资源。');}}
        if($action==='menu-quick-search'){
            $type=(string)($_POST['type']??'');
            if($type==='get-post-item'){$object=(string)($_POST['object_type']??'');$kind=in_array($object,self::POSTS,true)?'post_type':'taxonomy';if(!self::resource(['menu-item-type'=>$kind,'menu-item-object'=>$object,'menu-item-object-id'=>absint($_POST['ID']??0)])){self::fail('资源不属于当前店铺。');}}
            elseif(!preg_match('/^quick-search-(posttype|taxonomy)-([\w-]+)$/D',$type,$m)||!in_array($m[2],$m[1]==='posttype'?self::POSTS:self::TAXONOMIES,true)){self::fail('不支持该菜单资源。');}
        }
    }
    private static function validate_locations(array $locations): void { foreach($locations as $id){if($id&&!self::here(absint($id))){self::fail('菜单位置只能绑定当前店铺的菜单。');}} }
    private static function require_item(int $id,int $menu=0): void {
        if(!$id||get_post_type($id)!=='nav_menu_item'||(int)get_post_meta($id,'_gsm_brand_id',true)!==Brands::id()){self::fail('菜单项不属于当前店铺。');}
        $menus=wp_get_object_terms($id,'nav_menu',['fields'=>'ids']);
        if(is_wp_error($menus)||($menus&&(!$menu||!in_array($menu,array_map('intval',$menus),true)))){self::fail('不能移动其他菜单的菜单项。');}
    }
    private static function validate_rows(array $rows,int $menu): void {
        $parents=[];
        foreach($rows as $key=>$row){
            if(!is_array($row)||!self::resource(wp_unslash($row))){self::fail('菜单只能选择本站商品、页面、文章、商品集合及共享分类／标签；自定义链接请使用本站地址。');}
            $id=absint($row['menu-item-db-id']??0);if($id){self::require_item($id,$menu);}
            $parent=(int)($row['menu-item-parent-id']??0);$parents[(int)$key]=$parent;
            if($parent){if($parent>0){self::require_item($parent,$menu);}elseif(!isset($rows[$parent])){self::fail('菜单父项无效。');}}
        }
        foreach($parents as $key=>$parent){$seen=[$key=>true];while($parent&&isset($parents[$parent])){if(isset($seen[$parent])){self::fail('菜单层级不能成环。');}$seen[$parent]=true;$parent=$parents[$parent];}}
    }
    public static function resource(array $row): bool {
        $type=$row['menu-item-type']??'';$object=$row['menu-item-object']??'';$id=absint($row['menu-item-object-id']??0);
        if($type==='post_type'){$post=get_post($id);return in_array($object,self::POSTS,true)&&$post&&$post->post_type===$object&&$post->post_status==='publish'&&(int)get_post_meta($id,'_gsm_brand_id',true)===Brands::id()&&($object!=='product'||get_post_meta($id,'_gsm_kind',true)==='edition');}
        if($type==='taxonomy'){$term=in_array($object,self::TAXONOMIES,true)?get_term($id,$object):null;if(!$term instanceof \WP_Term){return false;}$brand=(int)get_term_meta($id,'_gsm_brand_id',true);if($brand&&$brand!==Brands::id()){return false;}if(get_term_meta($id,'_gsm_collection',true)){return $brand===Brands::id()&&CollectionDisplay::data($id)!==null;}return true;}
        if($type==='post_type_archive'){return in_array($object,['product','post'],true);}
        return $type==='custom'&&self::local_link((string)($row['menu-item-url']??''));
    }
    private static function local_link(string $url): bool {
        $url=trim(html_entity_decode($url,ENT_QUOTES));if($url===''||str_contains($url,'\\')||preg_match('/[\x00-\x20]/',$url)){return false;}
        if(str_starts_with($url,'#')){return true;}
        $parts=wp_parse_url($url);if(!$parts){return false;}
        if(isset($parts['host'])){if(strtolower($parts['host'])!==strtolower((string)wp_parse_url(Brands::url(Brands::id()),PHP_URL_HOST))||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])){return false;}}
        elseif(!str_starts_with($url,'/')||str_starts_with($url,'//')){return false;}
        if(isset($parts['scheme'])&&!in_array(strtolower($parts['scheme']),['http','https'],true)){return false;}
        parse_str($parts['query']??'',$query);foreach(['page_id','p'] as $key){if(isset($query[$key])){$post=get_post(absint($query[$key]));if(!$post||(int)get_post_meta($post->ID,'_gsm_brand_id',true)!==Brands::id()){return false;}}}
        return true;
    }
    public static function items(array $items,$menu,array $args): array {
        if(Brands::unrestricted()){return $items;}if(!self::here((int)$menu->term_id)){return [];}
        if(is_admin()){return $items;}
        $kept=[];foreach($items as $item){if((int)get_post_meta($item->ID,'_gsm_brand_id',true)===Brands::id()&&self::resource(['menu-item-type'=>$item->type,'menu-item-object'=>$item->object,'menu-item-object-id'=>$item->object_id,'menu-item-url'=>$item->url])){$kept[$item->ID]=clone $item;}}
        foreach($kept as $item){if($item->menu_item_parent&&!isset($kept[(int)$item->menu_item_parent])){$item->menu_item_parent=0;}}return array_values($kept);
    }
    public static function selector(): void {
        if(($GLOBALS['pagenow']??'')!=='nav-menus.php'||!self::can_manage()){return;}
        echo '<div id="gsm-menu-store" class="notice notice-info"><form method="get" action="'.esc_url(remove_query_arg(['gsm_brand','gsm_menu_scope_nonce'],admin_url('nav-menus.php'))).'"><p><label for="gsm-menu-brand"><strong>菜单所属店铺</strong></label> <select name="gsm_brand" id="gsm-menu-brand">';
        foreach(Stores::all() as $id=>$name){$roles=(array)get_user_meta(get_current_user_id(),'_gsm_brand_roles',true);if(Brands::root()||($roles[$id]??'')==='administrator'){echo '<option value="'.(int)$id.'" '.selected(Brands::id(),$id,false).'>'.esc_html($name).'</option>';}}
        echo '</select> <button class="button">切换店铺</button></p><p>当前店铺的菜单独立保存，可选择本站商品、页面、文章、商品集合及共享分类／标签。主菜单与页脚菜单的位置绑定只影响本店。</p></form></div>';
    }
    public static function assets(): void {
        if(($GLOBALS['pagenow']??'')!=='nav-menus.php'){return;}
        wp_enqueue_script('gsm-native-menus',plugins_url('assets/native-menus.js',GSM_FILE),['jquery','nav-menu'],GSM_VERSION,true);
        wp_add_inline_script('gsm-native-menus','window.gsmNativeMenus='.wp_json_encode(['brand'=>Brands::id(),'nonce'=>wp_create_nonce('gsm_menu_scope_'.Brands::id()),'actions'=>self::AJAX]).';','before');
        wp_add_inline_style('nav-menus','#gsm-menu-store{margin:12px 20px 12px 0;padding:6px 14px}#gsm-menu-store form{margin:0}#gsm-menu-brand{min-width:180px}.wrap .page-title-action[href*="customize.php"]{display:none}');
    }
}
