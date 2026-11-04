<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** One authoritative native catalog vocabulary on the main site; local terms are Woo projections. */
final class SharedTaxonomies {
    private static bool $busy=false;
    private static bool $dirty=false;
    private static array $products=[];
    public static function boot(): void {
        add_action('admin_init',static function (): void {
            if (wp_doing_ajax()||!Stores::can_manage()) { return; }
            self::attempt();
        },20);
        foreach (['saved_term','delete_term'] as $hook) {
            add_action($hook,static function ($id,$tt,$taxonomy) use ($hook): void {
                if (!self::$busy && get_current_blog_id()===Stores::main_id() && in_array($taxonomy,['product_cat','product_tag'],true)) {
                    if ($hook==='delete_term') { self::invalidate_rules((int)$id,$taxonomy); }
                    self::dirty();
                }
            },30,3);
        }
        foreach (['added_term_meta','updated_term_meta','deleted_term_meta'] as $hook) {
            add_action($hook,static function ($meta,$term,$key): void {
                if (!self::$busy && get_current_blog_id()===Stores::main_id() && in_array($key,['thumbnail_id','order'],true)) { self::dirty(); }
            },30,3);
        }
        add_action('update_option_default_product_cat',static function (): void { if (!self::$busy && get_current_blog_id()===Stores::main_id()) { self::dirty(); } });
        add_action('set_object_terms',static function ($id,$terms,$tt,$taxonomy): void {
            if (self::$busy || !in_array($taxonomy,['product_cat','product_tag'],true) || get_post_type($id)!=='product') { return; }
            if (get_current_blog_id()===Stores::main_id()) { self::$products[(int)$id]=true; }
            else { self::restore_product((int)$id); }
        },100,4);
        add_action('deleted_term_relationships',static function ($id,$tt,$taxonomy): void {
            if (self::$busy || !in_array($taxonomy,['product_cat','product_tag'],true) || get_post_type($id)!=='product') { return; }
            if (get_current_blog_id()===Stores::main_id()) { self::$products[(int)$id]=true; }
            else { self::restore_product((int)$id); }
        },100,3);
        add_action('shutdown',static function (): void {
            if (self::$busy || (!self::$dirty&&!self::$products)) { return; }
            self::attempt();
        },5);
        // Main-site native taxonomy screens are the single editing entry point.
        add_action('admin_menu',static function (): void {
            if (get_current_blog_id()===Stores::main_id()) { return; }
            foreach (['product_cat','product_tag'] as $taxonomy) { remove_submenu_page('edit.php?post_type=product','edit-tags.php?taxonomy='.$taxonomy.'&post_type=product'); }
        },100);
        add_action('admin_init',static function (): void {
            if (wp_doing_ajax() || get_current_blog_id()===Stores::main_id() || !in_array($GLOBALS['pagenow']??'',['edit-tags.php','term.php'],true) || !in_array($_REQUEST['taxonomy']??'',['product_cat','product_tag'],true)) { return; }
            if (!Stores::can_manage()) { wp_die('分类和标签由网络管理员在主站统一维护。',403); }
            wp_redirect(get_admin_url(Stores::main_id(),'edit-tags.php?taxonomy='.sanitize_key($_REQUEST['taxonomy']).'&post_type=product')); exit;
        },25);
        add_filter('map_meta_cap',static function ($caps,$cap): array {
            if (self::$busy) { return $caps; }
            if (in_array($cap,['manage_product_terms','edit_product_terms','delete_product_terms'],true) && (get_current_blog_id()!==Stores::main_id() || !Stores::can_manage())) { return ['do_not_allow']; }
            return $caps;
        },20,2);
        add_filter('pre_insert_term',static function ($term,$taxonomy) {
            if (!self::$busy && get_current_blog_id()!==Stores::main_id() && get_option('gsm_shared_taxonomy_revision') && in_array($taxonomy,['product_cat','product_tag'],true)) { return new \WP_Error('gsm_shared_taxonomy','请在主站原生产品分类／标签中统一维护。'); }
            return $term;
        },10,2);
        add_action('add_meta_boxes_product',static function ($post): void {
            if (get_current_blog_id()!==Stores::main_id() && self::source_product((int)$post->ID)) {
                remove_meta_box('product_catdiv','product','side'); remove_meta_box('tagsdiv-product_tag','product','side');
                add_meta_box('gsm-shared-terms','统一分类与标签',static function (): void { echo '<p>此商品的分类和标签由主站原商品统一维护，修改后同步到所有店铺版本。</p>'; },'product','side');
            }
        },100);
        add_action('admin_notices',static function (): void {
            if (Stores::can_manage() && get_site_option('gsm_shared_taxonomy_error')) { echo '<div class="notice notice-error"><p>统一分类同步尚未完成，请检查冲突后刷新后台重试：'.esc_html((string)get_site_option('gsm_shared_taxonomy_error')).'</p></div>'; }
        });
    }
    private static function dirty(): void {
        self::$dirty=true; update_site_option('gsm_shared_taxonomy_revision',(int)get_site_option('gsm_shared_taxonomy_revision',0)+1);
    }
    public static function local_collection(callable $callback): mixed {
        $old=self::$busy; self::$busy=true;
        try { return $callback(); } finally { self::$busy=$old; }
    }
    private static function attempt(): void {
        try { self::reconcile(); delete_site_option('gsm_shared_taxonomy_error'); }
        catch (\Throwable $error) { update_site_option('gsm_shared_taxonomy_error',$error->getMessage()); }
    }
    /** Idempotent consolidation: retain local term IDs and collection rules, main wins slug conflicts. */
    public static function reconcile(bool $force=false): void {
        if (self::$busy) { return; }
        Stores::authorize();
        global $wpdb;
        $lock='gsm_shared_taxonomy_'.get_current_network_id();
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1) { throw new \RuntimeException('统一分类正在同步，请稍后重试。'); }
        self::$busy=true;
        try {
            $stores=array_keys(Stores::all()); $main=Stores::main_id();
            $revision=max(1,(int)get_site_option('gsm_shared_taxonomy_revision',1));
            if (!get_site_option('gsm_shared_taxonomy_revision')) { update_site_option('gsm_shared_taxonomy_revision',$revision); }
            $force=$force || (bool)get_site_option('gsm_shared_taxonomy_error');
            $pending=[];
            foreach ($stores as $store) {
                if ($store===$main) { continue; }
                $version=Stores::within($store,static fn()=>(int)get_option('gsm_shared_taxonomy_revision',0));
                if (!$version) { Collections::defer(static fn()=>self::consolidate($store)); self::$dirty=true; }
                if ($force || $version<$revision || self::$dirty) { $pending[]=$store; }
            }
            if (self::$dirty) { $pending=array_values(array_diff($stores,[$main])); }
            if ($pending) {
                $snapshot=Stores::within($main,static fn()=>self::snapshot());
                foreach ($pending as $store) {
                    Stores::within($store,static function () use ($store,$snapshot,$revision): void {
                        Collections::defer(static fn()=>self::project($snapshot));
                        Collections::rebuild($store);
                        Stores::purge();
                    });
                }
            }
            if ($pending || self::$products) {
                $rows=$wpdb->get_results('SELECT master_product,target_store,target_product FROM '.Schema::table('catalog').' WHERE master_store='.(int)$main);
                foreach ($rows as $row) {
                    if (!in_array((int)$row->target_store,$stores,true) || (!in_array((int)$row->target_store,$pending,true)&&!isset(self::$products[(int)$row->master_product]))) { continue; }
                    self::sync_product((int)$row->master_product,(int)$row->target_store,(int)$row->target_product);
                }
            }
            foreach ($pending as $store) { update_blog_option($store,'gsm_shared_taxonomy_revision',$revision); }
            self::$dirty=false; self::$products=[];
        } finally { self::$busy=false; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function snapshot(): array {
        $result=[];
        foreach (['product_cat','product_tag'] as $taxonomy) {
            $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false]);
            if (is_wp_error($terms)) { throw new \RuntimeException($terms->get_error_message()); }
            foreach ($terms as $term) {
                if (get_term_meta($term->term_id,'_gsm_collection',true)) { continue; }
                $result[$taxonomy][$term->term_id]=['name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent'=>(int)$term->parent,'order'=>get_term_meta($term->term_id,'order',true),'image'=>self::image((int)get_term_meta($term->term_id,'thumbnail_id',true))];
            }
            $result[$taxonomy]??=[];
        }
        return $result;
    }
    private static function image(int $id): array {
        if (!$id) { return []; }
        $file=get_attached_file($id);
        if (!wp_attachment_is_image($id)||!$file||!is_readable($file)) { return []; }
        return ['key'=>get_current_blog_id().':'.$id.':'.md5($file.':'.filemtime($file)),'path'=>$file,'title'=>get_the_title($id),'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true)];
    }
    private static function copy_image(array $image): int {
        if (!$image) { return 0; }
        $existing=get_posts(['post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','numberposts'=>1,'meta_key'=>'_gsm_shared_media','meta_value'=>$image['key']]);
        if ($existing) { return (int)$existing[0]; }
        require_once ABSPATH.'wp-admin/includes/image.php';
        $file=wp_upload_bits(basename($image['path']),null,file_get_contents($image['path']));
        if ($file['error']) { throw new \RuntimeException('分类图片复制失败。'); }
        $id=wp_insert_attachment(['post_title'=>$image['title'],'post_mime_type'=>wp_check_filetype($file['file'])['type'],'post_status'=>'inherit'],$file['file'],0,true);
        if (is_wp_error($id)) { throw new \RuntimeException($id->get_error_message()); }
        wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file['file']));
        update_post_meta($id,'_wp_attachment_image_alt',$image['alt']); update_post_meta($id,'_gsm_shared_media',$image['key']);
        return (int)$id;
    }
    private static function consolidate(int $store): void {
        $snapshot=Stores::within($store,static fn()=>self::snapshot());
        foreach ($snapshot as $taxonomy=>$terms) {
            $mapped=[]; $visiting=[];
            $import=function (int $local) use (&$import,&$mapped,&$visiting,$terms,$taxonomy,$store): int {
                if (isset($mapped[$local])) { return $mapped[$local]; }
                if (!isset($terms[$local])||isset($visiting[$local])) { return 0; }
                $visiting[$local]=true; $term=$terms[$local]; $parent=$import($term['parent']);
                $source=Stores::within($store,static fn()=>(int)get_term_meta($local,'_gsm_shared_source',true));
                if ($taxonomy==='product_cat' && (int)get_blog_option($store,'default_product_cat')===$local) { $source=(int)get_blog_option(Stores::main_id(),'default_product_cat'); }
                $source=Stores::within(Stores::main_id(),static function () use ($source,$term,$taxonomy,$parent): int {
                    if ($source&&term_exists($source,$taxonomy)) { return $source; }
                    $existing=get_term_by('slug',$term['slug'],$taxonomy);
                    if ($existing && !get_term_meta($existing->term_id,'_gsm_collection',true)) { return (int)$existing->term_id; }
                    if ($existing) { throw new \RuntimeException('主站商品集合与共享分类网址冲突：'.$term['slug']); }
                    $created=wp_insert_term($term['name'],$taxonomy,['slug'=>$term['slug'],'description'=>$term['description'],'parent'=>$parent]);
                    if (is_wp_error($created)) { throw new \RuntimeException($created->get_error_message()); }
                    $id=(int)$created['term_id']; update_term_meta($id,'order',$term['order']);
                    if ($taxonomy==='product_cat') { update_term_meta($id,'thumbnail_id',self::copy_image($term['image'])); }
                    return $id;
                });
                Stores::within($store,static function () use ($local,$source): void { update_term_meta($local,'_gsm_shared_source',$source); });
                unset($visiting[$local]); return $mapped[$local]=$source;
            };
            foreach (array_keys($terms) as $local) { $import((int)$local); }
        }
    }
    private static function project(array $snapshot): void {
        foreach ($snapshot as $taxonomy=>$terms) {
            $map=[];
            $existing=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false]);
            if (is_wp_error($existing)) { throw new \RuntimeException($existing->get_error_message()); }
            foreach ($existing as $term) {
                $source=(int)get_term_meta($term->term_id,'_gsm_shared_source',true);
                if (!$source) { continue; }
                if (!isset($terms[$source])) {
                    self::invalidate_rules((int)$term->term_id,$taxonomy);
                    if ($taxonomy==='product_cat'&&(int)get_option('default_product_cat')===$term->term_id) { delete_option('default_product_cat'); }
                    $deleted=wp_delete_term($term->term_id,$taxonomy);
                    if (is_wp_error($deleted)||!$deleted) { throw new \RuntimeException('无法移除已删除的统一分类。'); }
                } else { $map[$source]=(int)$term->term_id; }
            }
            $done=[];
            $ensure=function (int $source) use (&$ensure,&$map,&$done,$terms,$taxonomy): int {
                if (isset($done[$source])) { return $map[$source]; }
                if (!$source||!isset($terms[$source])) { return 0; }
                $value=$terms[$source]; $parent=$value['parent']?$ensure($value['parent']):0;
                $local=$map[$source]??0;
                if (!$local) {
                    $found=get_term_by('slug',$value['slug'],$taxonomy);
                    if ($found&&get_term_meta($found->term_id,'_gsm_collection',true)) { throw new \RuntimeException('店铺商品集合与共享分类网址冲突：'.$value['slug']); }
                    $local=$found?(int)$found->term_id:0;
                }
                $args=['name'=>$value['name'],'slug'=>$value['slug'],'description'=>$value['description'],'parent'=>$parent];
                $result=$local?wp_update_term($local,$taxonomy,$args):wp_insert_term($value['name'],$taxonomy,$args);
                if (is_wp_error($result)) { throw new \RuntimeException($result->get_error_message()); }
                $local=(int)$result['term_id']; update_term_meta($local,'_gsm_shared_source',$source); update_term_meta($local,'order',$value['order']);
                if ($taxonomy==='product_cat') { update_term_meta($local,'thumbnail_id',self::copy_image($value['image'])); }
                $done[$source]=true; return $map[$source]=$local;
            };
            foreach (array_keys($terms) as $source) { $ensure((int)$source); }
            if ($taxonomy==='product_cat') {
                $default=(int)get_blog_option(Stores::main_id(),'default_product_cat');
                if (isset($map[$default])) { update_option('default_product_cat',$map[$default]); }
            }
        }
    }
    private static function invalidate_rules(int $id,string $taxonomy): void {
        $rules=Collections::collections(); $changed=false;
        foreach ($rules as &$rule) {
            $field=$taxonomy==='product_cat'?'categories':'tags';
            if (!in_array($id,Collections::ids($rule[$field]??[]),true)) { continue; }
            $rule[$field]=array_values(array_diff(Collections::ids($rule[$field]),[$id]));
            if (($rule['mode']??'rule')==='rule') { $rule['enabled']=false; $rule['needs_review']=true; }
            $changed=true;
        }
        unset($rule);
        if ($changed) { update_option('gsm_collections',$rules,false); update_option('gsm_collections_review',count(array_filter($rules,static fn($rule)=>!empty($rule['needs_review']))),false); }
    }
    public static function sync_product(int $source,int $store,int $product): void {
        if ($store===Stores::main_id()) { return; }
        $terms=Stores::within(Stores::main_id(),static function () use ($source): ?array {
            if (get_post_type($source)!=='product') { return null; }
            return ['product_cat'=>array_values(array_filter(wp_get_object_terms($source,'product_cat',['fields'=>'ids']),static fn($id)=>!get_term_meta($id,'_gsm_collection',true))),'product_tag'=>wp_get_object_terms($source,'product_tag',['fields'=>'ids'])];
        });
        if ($terms===null) { return; }
        self::local_collection(static function () use ($store,$product,$terms): void {
            Stores::within($store,static function () use ($product,$terms): void {
                $p=wc_get_product($product);
                if (!$p || !$p->is_type(['simple','variable'])) { return; }
                foreach ($terms as $taxonomy=>$sources) {
                    $ids=[];
                    foreach ($sources as $source) {
                        $found=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'fields'=>'ids','meta_query'=>[['key'=>'_gsm_shared_source','value'=>$source]]]);
                        if (is_wp_error($found)||count($found)!==1) { throw new \RuntimeException('共享分类映射未完成，请刷新主站后台后重试。'); }
                        $ids[]=(int)$found[0];
                    }
                    if ($taxonomy==='product_cat') {
                        foreach (wp_get_object_terms($product,$taxonomy,['fields'=>'ids']) as $id) { if (get_term_meta($id,'_gsm_collection',true)) { $ids[]=(int)$id; } }
                    }
                    if ($taxonomy==='product_cat') { $p->set_category_ids($ids); } else { $p->set_tag_ids($ids); }
                }
                $p->save();
                clean_object_term_cache($product,'product'); wc_delete_product_transients($product); Stores::purge();
            });
        });
    }
    private static function source_product(int $product): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT master_product FROM '.Schema::table('catalog').' WHERE master_store=%d AND target_store=%d AND target_product=%d',Stores::main_id(),get_current_blog_id(),$product));
    }
    private static function restore_product(int $product): void {
        $source=self::source_product($product);
        if ($source) { self::sync_product($source,get_current_blog_id(),$product); }
    }
}
