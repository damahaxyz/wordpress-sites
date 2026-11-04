<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Remove retired template metadata without deleting native terms, media or relationships. */
final class CategoryMigration {
    public const VERSION = 1;
    private const KEYS = ['_gsm_template_id','_gsm_template_store','_gsm_overrides'];
    public static function boot(): void {
        add_action('admin_init',static function (): void {
            if (wp_doing_ajax()||!Stores::can_manage()) { return; }
            try { self::run(); }
            catch (\Throwable $error) {
                add_action('admin_notices',static function () use ($error): void {
                    echo '<div class="notice notice-error"><p>原生分类升级尚未完成：'.esc_html($error->getMessage()).'</p></div>';
                });
            }
        });
    }
    public static function run(): array {
        Stores::authorize(); $results=[];
        foreach (array_keys(Stores::all()) as $store) {
            if (Stores::within($store,static fn()=>(int)get_option('gsm_native_categories_version',0))<self::VERSION) { $results[$store]=self::store($store); }
        }
        return $results;
    }
    private static function local_id(int $source, string $taxonomy): int {
        if (get_current_blog_id()===Stores::main_id()) {
            return term_exists($source,$taxonomy)&&!get_term_meta($source,'_gsm_collection',true)?$source:0;
        }
        $ids=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'fields'=>'ids','meta_query'=>[
            ['key'=>'_gsm_template_id','value'=>$source],['key'=>'_gsm_template_store','value'=>Stores::main_id()],
        ]]);
        if (is_wp_error($ids)) { throw new \RuntimeException($ids->get_error_message()); }
        return count($ids)===1&&!get_term_meta((int)$ids[0],'_gsm_collection',true)?(int)$ids[0]:0;
    }
    public static function store(int $store): array {
        Stores::authorize();
        return Stores::within($store,static function () use ($store): array {
            global $wpdb;
            $lock='gsm_category_cleanup_'.$store;
            if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1) { throw new \RuntimeException('分类升级正在执行，请稍后刷新。'); }
            try {
                $all=Collections::collections(); $converted=0;
                foreach ($all as &$spec) {
                    if (($spec['taxonomy_scope']??'')==='local') { continue; }
                    $missing=false;
                    foreach (['categories'=>'product_cat','tags'=>'product_tag'] as $field=>$taxonomy) {
                        $ids=[];
                        foreach (Collections::ids($spec[$field]??[]) as $source) {
                            $local=self::local_id($source,$taxonomy);
                            if ($local) { $ids[]=$local; } else { $missing=true; }
                        }
                        $spec[$field]=array_values(array_unique($ids));
                    }
                    $spec['taxonomy_scope']='local';
                    if (($spec['mode']??'rule')==='rule'&&($missing||(!$spec['categories']&&!$spec['tags']))) {
                        $spec['enabled']=false; $spec['needs_review']=true;
                    }
                    $converted++;
                }
                unset($spec);
                // Persist converted rule IDs before removing the only legacy mapping information.
                if ($converted) { update_option('gsm_collections',$all,false); }
                $review=count(array_filter($all,static fn($spec)=>!empty($spec['needs_review'])));
                if ($review) { update_option('gsm_collections_review',$review,false); } else { delete_option('gsm_collections_review'); }
                $removed=0;
                foreach (self::KEYS as $key) {
                    $removed+=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key=%s",$key));
                    delete_metadata('term',0,$key,'',true);
                    if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key=%s",$key))) { throw new \RuntimeException('旧分类元数据清理失败，稍后可安全重试。'); }
                }
                Collections::rebuild($store);
                update_option('gsm_native_categories_version',self::VERSION,false);
                if ($removed||$converted) { Schema::audit('classification_templates_removed',$store,0,['after'=>['metadata'=>$removed,'collections'=>$converted,'review'=>$review]]); }
                return ['metadata_removed'=>$removed,'collections_converted'=>$converted,'needs_review'=>$review];
            } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
        });
    }
}
