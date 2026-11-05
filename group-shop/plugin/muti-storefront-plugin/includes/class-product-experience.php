<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Integrates Trovesia's presentation and independent add-ons with native brand products. */
final class ProductExperience {
    public const META=['_trovesia_eyebrow','_trovesia_benefits','_trovesia_guarantee','_trovesia_shipping','_trovesia_order_activity','_trovesia_delivery_min_days','_trovesia_delivery_max_days'];
    public static function source(int $id): int {
        if(get_post_type($id)!=='product'||get_post_meta($id,'_gsm_kind',true)!=='edition'){return 0;}
        $source=(int)get_post_meta($id,'_gsm_base_product',true);
        return $source&&get_post_type($source)==='product'&&get_post_meta($source,'_gsm_kind',true)==='base'&&BrandScope::product_brand($source)===0?$source:0;
    }
    public static function custom(int $id): bool { return in_array('presentation',(array)get_post_meta($id,'_gsm_overrides',true),true); }
    private static function actor(int $id): bool { return Brands::root()||(BrandScope::product_brand($id)===Brands::id()&&current_user_can('edit_post',$id)); }
    private static function form(int $id): bool {
        return self::actor($id)&&($_POST['trovesia_product_meta_complete']??'')==='1'&&($_POST['gsm_versions_complete']??'')==='1'
            &&wp_verify_nonce((string)($_POST['trovesia_product_meta_nonce']??''),'trovesia_product_meta')&&wp_verify_nonce((string)($_POST['gsm_product_nonce']??''),'gsm_product_versions');
    }
    private static function form_custom(int $id): bool { return self::form($id)&&in_array('presentation',(array)($_POST['gsm_product_overrides']??[]),true); }
    public static function boot(): void {
        add_filter('trovesia_order_activity_cache_key',static fn($key)=>$key.'_brand_'.Brands::id());
        add_filter('trovesia_order_activity_query_args',static function($args){$args['meta_query'][]=['key'=>'_gsm_store_id','value'=>Brands::id(),'type'=>'NUMERIC'];return $args;});
        add_filter('get_post_metadata',static function($pre,$id,$key,$single){
            if(!in_array($key,self::META,true)||self::custom((int)$id)||!($source=self::source((int)$id))){return $pre;}
            $value=get_post_meta($source,$key,true);return $single?$value:($value===''?[]:[$value]);
        },50,4);
        foreach(self::META as $key){add_filter('woocommerce_product_get_'.$key,static fn($value,$product)=>self::source($product->get_id())&&!self::custom($product->get_id())?get_post_meta(self::source($product->get_id()),$key,true):$value,50,2);}
        add_action('trovesia_product_presentation_controls',[self::class,'controls']);
        add_filter('trovesia_product_presentation_save_allowed',static function($ok,$id){return $ok&&self::form((int)$id)&&(!self::source((int)$id)||self::form_custom((int)$id));},20,2);
        foreach(['add_post_metadata','update_post_metadata','delete_post_metadata'] as $hook){add_filter($hook,[self::class,'protect_meta'],60,5);}
        add_action('admin_init',[self::class,'guard_form'],0);
        add_action('trovesia_addon_controls',static function($post){echo '<p>本店独立加购规则：仅关联本店简单商品或变体，不随公共商品继承。最多20条规则。</p>';});
        add_filter('trovesia_addon_parent_allowed',static fn($ok,$id)=>$ok&&self::addon_parent((int)$id),20,2);
        add_filter('trovesia_addon_link_allowed',static function($ok,$parent,$linked){return $ok&&self::addon_parent($parent)&&self::addon_parent($linked->is_type('variation')?$linked->get_parent_id():$linked->get_id())&&BrandScope::product_brand($parent)===BrandScope::product_brand($linked->get_id());},20,3);
        add_filter('trovesia_addon_product_query_args',static function($args,$id){$args['meta_query'][]=BrandScope::clause(BrandScope::product_brand((int)$id));$args['meta_query'][]=['key'=>'_gsm_kind','value'=>'edition'];return $args;},20,2);
    }
    public static function addon_parent(int $id): bool { return get_post_type($id)==='product'&&BrandScope::product_brand($id)>0&&get_post_meta($id,'_gsm_kind',true)==='edition'&&self::source($id)>0; }
    public static function protect_meta($pre,int $id,string $key,$value,$extra=null) {
        if(Brands::unrestricted()){return $pre;}
        if($key==='_trovesia_addon_rules'){
            if(!class_exists('Trovesia\\Plugin\\Addon_Pricing')||!self::actor($id)||!self::addon_parent($id)||!is_array($value)){return false;}
            foreach($value as $rule){if(!is_array($rule)||!\Trovesia\Plugin\Addon_Pricing::valid_link($id,wc_get_product(absint($rule['product_id']??0)))){return false;}}return $pre;
        }
        if(!in_array($key,self::META,true)||get_post_type($id)!=='product'){return $pre;}
        if(!self::actor($id)){return false;}
        if(self::source($id)&&!self::custom($id)&&!self::form_custom($id)){return false;}
        return $pre;
    }
    public static function guard_form(): void {
        if(($_POST['action']??'')!=='editpost'){return;}$id=absint($_POST['post_ID']??0);if(get_post_type($id)!=='product'){return;}
        if(isset($_POST['trovesia_product_meta_nonce'])||isset($_POST['trovesia_product_meta_complete'])){
            if(!self::form($id)||!current_user_can('edit_post',$id)||!wp_verify_nonce((string)($_POST['_wpnonce']??''),'update-post_'.$id)){wp_die('商品展示表单不完整或已过期，请刷新重试。','商品未保存',['response'=>403]);}
            if(!self::source($id)||self::form_custom($id)){foreach(array_slice(self::META,0,5) as $key){if(!array_key_exists(substr($key,1),$_POST)||!is_string($_POST[substr($key,1)])){wp_die('商品展示字段不完整，请刷新重试。','商品未保存',['response'=>403]);}}}
        }
        if(isset($_POST['trovesia_addon_rules_nonce'])||isset($_POST['trovesia_addon_rules_complete'])){
            if(!self::actor($id)||!wp_verify_nonce((string)($_POST['_wpnonce']??''),'update-post_'.$id)||!wp_verify_nonce((string)($_POST['trovesia_addon_rules_nonce']??''),'trovesia_addon_rules')){wp_die('加购规则表单授权无效。','商品未保存',['response'=>403]);}
            $error=\Trovesia\Plugin\Addon_Pricing::validate_form($id,$_POST);if(is_wp_error($error)){wp_die(esc_html($error->get_error_message()),'商品未保存',['response'=>403]);}
        }
    }
    public static function controls(\WP_Post $post): void {
        $source=self::source($post->ID);if(!$source){echo '<p>公共商品的展示信息可由品牌版本继承。</p>';return;}
        echo '<div class="gsm-presentation-policy"><p>展示信息来源：'.esc_html(get_the_title($source)).'。默认继承公共商品的展示文案；订单数量仍按当前店铺计算。</p><label><input type="checkbox" name="gsm_product_overrides[]" value="presentation" '.checked(self::custom($post->ID),true,false).'> 单独设置本店展示信息</label><p class="description">取消勾选并保存即恢复继承。单独设置时留空，会使用原有默认文案或本店自动订单统计。</p></div>';
    }
}
