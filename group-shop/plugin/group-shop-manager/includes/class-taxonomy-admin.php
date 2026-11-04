<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Explain ownership on native taxonomy screens without changing terms or permissions. */
final class TaxonomyAdmin {
    public static function boot(): void {
        foreach (['product_cat','product_tag'] as $taxonomy) {
            add_filter('manage_edit-'.$taxonomy.'_columns',[self::class,'columns'],30);
            add_filter('manage_'.$taxonomy.'_custom_column',static fn($html,$column,$id)=>self::column((string)$html,(string)$column,(int)$id,$taxonomy),10,3);
            add_filter($taxonomy.'_row_actions',static function ($actions,$term) use ($taxonomy): array {
                if ($taxonomy==='product_cat' && get_term_meta($term->term_id,'_gsm_collection',true) && Stores::can_manage()) {
                    $actions['gsm_collection']='<a href="'.esc_url(self::collection_url((int)$term->term_id)).'">管理商品集合</a>';
                }
                return $actions;
            },20,2);
            add_action($taxonomy.'_edit_form_fields',[self::class,'edit_fields'],20,2);
        }
        add_action('admin_notices',[self::class,'notice']);
        add_action('admin_enqueue_scripts',static function (): void {
            $screen=get_current_screen();
            if (!$screen || !in_array($screen->taxonomy,['product_cat','product_tag'],true)) { return; }
            wp_enqueue_style('gsm-taxonomy-admin',plugins_url('assets/taxonomy-admin.css',GSM_FILE),[],GSM_VERSION.'.'.filemtime(GSM_DIR.'assets/taxonomy-admin.css'));
        });
    }
    public static function columns(array $columns): array {
        $result=[];
        foreach ($columns as $key=>$label) {
            $result[$key]=$label;
            if ($key==='name') { $result['gsm_scope']='类型'; $result['gsm_store']='所属店铺／范围'; }
        }
        return $result;
    }
    private static function is_collection(int $id,string $taxonomy): bool { return $taxonomy==='product_cat' && (bool)get_term_meta($id,'_gsm_collection',true); }
    private static function collection_url(int $id): string {
        $rule=Collections::collections()[$id]??null;
        $args=['store'=>get_current_blog_id()];
        if ($rule && !empty($rule['deleted_at'])) { $args['view']='trash'; }
        elseif ($rule) { $args['collection']=$id; }
        return Admin::url('collections',$args);
    }
    public static function column(string $html,string $column,int $id,string $taxonomy): string {
        if (!in_array($column,['gsm_scope','gsm_store'],true) || !term_exists($id,$taxonomy)) { return $html; }
        $collection=self::is_collection($id,$taxonomy);
        if ($column==='gsm_scope') {
            if (!$collection) { return '<span class="gsm-taxonomy-kind">共享'.($taxonomy==='product_tag'?'标签':'分类').'</span>'; }
            $rule=Collections::collections()[$id]??null;
            $state=!$rule?'规则缺失':(!empty($rule['deleted_at'])?'回收站':(!empty($rule['needs_review'])?'需修正规则':(!empty($rule['enabled'])?'已启用':'已停用')));
            return '<span class="gsm-taxonomy-kind is-collection">商品集合</span><br><span class="description">'.esc_html($state).'</span>';
        }
        if (!$collection) { return '<strong>全站共享</strong><br><span class="description">由主站统一维护</span>'; }
        $store=get_current_blog_id();
        $label=get_bloginfo('name').'（'.($store===Stores::main_id()?'主店 ':'店铺 ').'#'.$store.'）';
        $label=esc_html($label);
        if (Stores::can_manage()) { $label='<a href="'.esc_url(self::collection_url($id)).'">'.$label.'</a>'; }
        return $label.'<br><span class="description gsm-taxonomy-store-url">'.esc_html(untrailingslashit(home_url('/'))).'</span>';
    }
    public static function edit_fields(\WP_Term $term,string $taxonomy): void {
        echo '<tr class="form-field"><th scope="row">类型</th><td>'.self::column('','gsm_scope',(int)$term->term_id,$taxonomy).'</td></tr><tr class="form-field"><th scope="row">所属店铺／范围</th><td>'.self::column('','gsm_store',(int)$term->term_id,$taxonomy);
        if (self::is_collection((int)$term->term_id,$taxonomy) && Stores::can_manage()) {
            echo '<p><a class="button" href="'.esc_url(self::collection_url((int)$term->term_id)).'">管理商品集合</a> <span class="description">选品条件、启用状态及删除恢复请在集合管理中操作。</span></p>';
        }
        echo '</td></tr>';
    }
    public static function notice(): void {
        if (!function_exists('get_current_screen')) { return; }
        $screen=get_current_screen();
        if (!$screen || !in_array($screen->base,['edit-tags','term'],true) || !in_array($screen->taxonomy,['product_cat','product_tag'],true)) { return; }
        echo '<div class="notice notice-info gsm-taxonomy-notice"><p>普通产品分类和标签为<strong>全站共享</strong>；<strong>商品集合仅属于对应店铺</strong>。当前原生页面显示「'.esc_html(get_bloginfo('name')).'（#'.get_current_blog_id().'）」的记录，不是所有店铺集合的汇总。';
        if (Stores::can_manage()) { echo ' <a href="'.esc_url(Admin::url('collections',['store'=>get_current_blog_id()])).'">前往商品集合，切换店铺查看其他店的集合</a>。'; }
        echo '</p></div>';
    }
}
