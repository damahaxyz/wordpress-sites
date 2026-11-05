<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Shared native base products materialize into native brand sale products. */
final class ProductVersions {
    private static bool $busy=false;
    public const FIELDS=['name'=>'商品标题','description'=>'商品描述','short_description'=>'商品短描述','images'=>'主图与相册','price'=>'售价／促销价格与日程','presentation'=>'商品展示信息'];
    private const COMMON=['virtual','downloadable','downloads','download_limit','download_expiry','tax_status','tax_class','weight','length','width','height','shipping_class_id','attributes','default_attributes','sold_individually','purchase_note'];
    public static function boot(): void {
        add_filter('register_post_type_args',static function ($args,$type) { if ($type==='product') { $args['capabilities']['create_posts']='gsm_create_public_products'; } return $args; },30,2);
        add_filter('user_has_cap',static function ($caps,$required,$args,$user) { $caps['gsm_create_public_products']=Brands::root((int)$user->ID);return $caps; },100,4);
        add_action('woocommerce_before_product_object_save',[self::class,'validate'],5);
        add_action('admin_init',[self::class,'guard_native_form'],1);
        add_action('woocommerce_product_data_panels',[self::class,'panel']);
        add_filter('woocommerce_product_data_tabs',static fn($tabs)=>$tabs+['gsm_versions'=>['label'=>'公共资料／品牌版本','target'=>'gsm_versions_panel','priority'=>5]]);
        add_action('woocommerce_admin_process_product_object',[self::class,'before']);
        add_action('woocommerce_after_product_object_save',[self::class,'after'],40);
        add_action('set_object_terms',static function ($id,$terms,$tt,$tax): void { if (!self::$busy&&in_array($tax,['product_cat','product_tag'],true)&&get_post_meta($id,'_gsm_kind',true)==='base') { $p=wc_get_product($id); if ($p) { self::after($p); } } },150,4);
        add_action('woocommerce_product_after_variable_attributes',[self::class,'variation_controls'],10,3);
        add_action('woocommerce_admin_process_variation_object',[self::class,'before_variation'],50,2);
        add_action('woocommerce_save_product_variation',static function ($id): void { $p=wc_get_product(wp_get_post_parent_id($id)); if ($p&&$p->get_meta('_gsm_base_product')) { self::after($p); } },40);
        foreach (['woocommerce_add_variation','woocommerce_link_all_variations','woocommerce_remove_variations','woocommerce_save_attributes','woocommerce_bulk_edit_variations','woocommerce_save_variations','woocommerce_load_variations'] as $action) {
            add_action('wp_ajax_'.$action,static function () use ($action): void {
                $parent=absint($_POST['product_id']??$_POST['post_id']??0);
                $ids=wp_parse_id_list($_POST['variation_ids']??[]);
                foreach ($ids as $id) { $candidate=wp_get_post_parent_id($id); if ($candidate&&!current_user_can('edit_post',$candidate)) { wp_send_json_error(['message'=>'没有该品牌商品的编辑权限。'],403); } if (get_post_meta($candidate,'_gsm_base_product',true)) { $parent=$candidate; } }
                if ($parent&&!current_user_can('edit_post',$parent)) { wp_send_json_error(['message'=>'没有该品牌商品的编辑权限。'],403); }
                if (in_array($action,['woocommerce_save_variations','woocommerce_load_variations'],true)) { return; }
                if (!$parent||!get_post_meta($parent,'_gsm_base_product',true)) { return; }
                if ($action==='woocommerce_bulk_edit_variations'&&($_POST['bulk_action']??'')==='toggle_enabled') { return; }
                wp_send_json_error(['message'=>'规格和共用资料请在公共商品修改；品牌差异请逐个编辑变体。'],403);
            },0);
        }
    }
    /** WordPress writes the post title before Woo's object hooks: reject malformed ownership first. */
    public static function guard_native_form(): void {
        if (($_POST['action']??'')!=='editpost'||(!isset($_POST['gsm_product_nonce'])&&!isset($_POST['gsm_versions_complete']))) { return; }
        $id=absint($_POST['post_ID']??0);if (get_post_type($id)!=='product') { return; }
        $brand=get_post_status($id)==='auto-draft'?0:(int)get_post_meta($id,'_gsm_brand_id',true);
        $base=(int)get_post_meta($id,'_gsm_base_product',true);
        if (!current_user_can('edit_post',$id)||!wp_verify_nonce((string)($_POST['_wpnonce']??''),'update-post_'.$id)||!wp_verify_nonce((string)($_POST['gsm_product_nonce']??''),'gsm_product_versions')||($_POST['gsm_versions_complete']??'')!=='1') { wp_die('商品表单已过期或没有编辑权限，请刷新后重试。','商品未保存',['response'=>403]); }
        if (absint($_POST['gsm_product_brand']??$brand)!==$brand||absint($_POST['gsm_base_product']??$base)!==$base||($brand&&!$base)||($brand&&isset($_POST['product-type'])&&$_POST['product-type']!==wc_get_product($id)?->get_type())) { wp_die('商品归属和公共来源不能修改；请先创建公共商品，再为品牌建立继承版本。','商品未保存',['response'=>403]); }
    }
    /** All external writes keep the original public source; only our materializer creates editions. */
    public static function validate(\WC_Product $product): void {
        if (Brands::unrestricted()) { return; }
        $id=$product->get_id();$variation=$product->is_type('variation');$automation=$id>0&&get_current_user_id()===0&&wp_doing_cron();
        $brand=$variation?BrandScope::product_brand($product->get_parent_id()):(int)$product->get_meta('_gsm_brand_id');
        if (!$brand) {
            if (!Brands::root()&&!$automation) { throw new \WC_Data_Exception('gsm_public_owner','公共商品由商城总管理员维护；品牌商品请从公共商品继承。'); }
            if (!$variation) { $product->update_meta_data('_gsm_brand_id',0);$product->update_meta_data('_gsm_kind','base');$product->update_meta_data('_gsm_base_product',0); }
        }
        if (!$id&&$brand) { throw new \WC_Data_Exception('gsm_source_required','品牌商品必须通过公共商品建立继承版本。'); }
        if ($id&&get_post_status($id)!=='auto-draft') {
            $old=$variation?BrandScope::product_brand($product->get_parent_id()):(int)get_post_meta($id,'_gsm_brand_id',true);
            $key=$variation?'_gsm_base_variation':'_gsm_base_product';
            if ($old!==$brand||(int)get_post_meta($id,$key,true)!==(int)$product->get_meta($key)) { throw new \WC_Data_Exception('gsm_source_locked','商品归属及公共来源不能更改。'); }
        }
        if ($brand) {
            Stores::assert_store($brand);
            if (!Brands::root()&&!$automation&&(!Identities::accessible(get_current_user_id(),$brand)||$brand!==Brands::id())) { throw new \WC_Data_Exception('gsm_product_scope','没有该品牌商品权限。'); }
            $source=wc_get_product((int)$product->get_meta($variation?'_gsm_base_variation':'_gsm_base_product'));
            $public=$variation&&$source?$source->get_parent_id():($source?->get_id()??0);
            if (!$source||$source->get_type()!==$product->get_type()||BrandScope::product_brand($public)!==0||get_post_meta($public,'_gsm_kind',true)!=='base'||($variation&&(int)get_post_meta($product->get_parent_id(),'_gsm_base_product',true)!==$public)) { throw new \WC_Data_Exception('gsm_source_required','品牌商品必须关联同类型的公共商品。'); }
        }
    }
    public static function variation_controls($loop,$data,$variation): void {
        $base=(int)get_post_meta($variation->ID,'_gsm_base_variation',true); if (!$base) { return; }
        $over=(array)get_post_meta($variation->ID,'_gsm_overrides',true);
        echo '<div class="form-row form-row-full gsm-variation-inheritance" data-gsm-variation="'.esc_attr(wp_json_encode(['base'=>$base,'overrides'=>$over])).'"><strong>品牌变体差异：</strong>';
        foreach (['price'=>'单独设置价格','images'=>'单独设置图片','description'=>'单独设置描述'] as $field=>$label) { echo '<label data-gsm-variation-toggle="'.esc_attr($field).'" style="margin-right:16px"><input type="checkbox" name="gsm_variation_overrides['.(int)$variation->ID.'][]" value="'.esc_attr($field).'" '.checked(in_array($field,$over,true),true,false).'> '.esc_html($label).'</label>'; }
        echo '<input type="hidden" name="gsm_variation_complete['.(int)$variation->ID.']" value="1"></div>';
    }
    public static function before_variation(\WC_Product_Variation $variation,$loop): void {
        $source=wc_get_product((int)$variation->get_meta('_gsm_base_variation')); if (!$source) { return; }
        $parent=wc_get_product($variation->get_parent_id()); if (!$parent?->get_meta('_gsm_base_product')) { return; }
        $id=$variation->get_id();
        if (isset($_POST['gsm_variation_complete'][$id])) { $variation->update_meta_data('_gsm_overrides',array_values(array_intersect((array)($_POST['gsm_variation_overrides'][$id]??[]),['price','images','description']))); }
        self::variation_fields($variation,$source,(array)$parent->get_meta('_gsm_overrides'));
    }
    private static function variation_fields(\WC_Product_Variation $variation,\WC_Product $source,array $parent_over): void {
        $variation->set_attributes($source->get_attributes());
        foreach (['weight','length','width','height','virtual','downloadable','downloads','download_limit','download_expiry','tax_class','tax_status','shipping_class_id'] as $prop) { $variation->{'set_'.$prop}($source->{'get_'.$prop}('edit')); }
        $over=(array)$variation->get_meta('_gsm_overrides');
        foreach (['price'=>['regular_price','sale_price','date_on_sale_from','date_on_sale_to'],'images'=>['image_id'],'description'=>['description']] as $field=>$props) { if (!in_array($field,$over,true)&&!in_array($field,$parent_over,true)) { foreach ($props as $prop) { $variation->{'set_'.$prop}($source->{'get_'.$prop}('edit')); } } }
    }

    public static function panel(): void {
        global $product_object; if (!$product_object) { return; }
        $id=$product_object->get_id(); $brand=$product_object->get_status()==='auto-draft'?0:(int)get_post_meta($id,'_gsm_brand_id',true); $base=(int)get_post_meta($id,'_gsm_base_product',true); $over=(array)get_post_meta($id,'_gsm_overrides',true);
        echo '<div id="gsm_versions_panel" class="panel woocommerce_options_panel hidden"><div class="options_group"><p class="form-field"><label>商品归属</label>';
        echo '<span>'.esc_html($brand?Brands::name($brand):'公共商品（不直接销售）').'</span><input type="hidden" name="gsm_product_brand" value="'.$brand.'">';
        echo '</p><p class="form-field gsm-base-selector"><label>公共商品</label>';
        if ($base&&Brands::root()) { echo '<a href="'.esc_url(NativeProducts::edit_url($base)).'">'.esc_html(get_the_title($base).' (#'.$base.')').'</a>'; }
        elseif ($base) { echo '<span>'.esc_html(get_the_title($base).' (#'.$base.')').'</span>'; }
        echo '<input type="hidden" name="gsm_base_product" value="'.$base.'">';
        echo '</p><p class="form-field">品牌版本未勾选的字段使用公共值；勾选后使用此页原生字段独立设置。标题、描述、图片及成交价格均来自该品牌原生商品。</p>';
        echo '<div class="gsm-override-options" role="group" aria-label="品牌独立设置"><p class="form-field"><strong>品牌独立设置</strong></p>';
        foreach (self::FIELDS as $field=>$label) { if($field==='presentation'){continue;} echo '<p class="form-field"><label>'.esc_html($label).'</label><label style="float:none;width:auto;margin:0;display:inline-flex;align-items:center;gap:6px"><input type="checkbox" style="float:none;margin:0" name="gsm_product_overrides[]" value="'.esc_attr($field).'" '.checked(in_array($field,$over,true),true,false).'> 单独设置（不随公共资料更新）</label></p>'; }
        echo '</div>';
        if (Brands::root()) {
            echo '<div class="gsm-target-brands">';
            echo '<p class="form-field"><strong>公共商品用于以下品牌：</strong>保存公共商品后建立或更新对应原生销售版本；每品牌保留自己的上架状态和差异。</p>';
            foreach (Stores::all() as $b=>$name) {
                $exists=Brands::unscoped(static fn()=>get_posts(['post_type'=>'product','post_status'=>'any','fields'=>'ids','numberposts'=>1,'meta_query'=>[['key'=>'_gsm_base_product','value'=>$id],['key'=>'_gsm_brand_id','value'=>$b]]]));
                echo '<p class="form-field"><label>'.esc_html($name).'</label><input type="checkbox" name="gsm_product_brands[]" value="'.(int)$b.'" '.checked((bool)$exists,true,false).'>'.($exists?' <a href="'.esc_url(Brands::admin_url((int)$b,'post.php?post='.$exists[0].'&action=edit')).'">编辑品牌版本</a>':'').'</p>';
            }
        }
        if (Brands::root()) { echo '</div>'; }
        wp_nonce_field('gsm_product_versions','gsm_product_nonce'); echo '<input type="hidden" name="gsm_versions_complete" value="1"></div></div>';
    }
    public static function before(\WC_Product $product): void {
        if (empty($_POST['gsm_product_nonce'])) { return; }
        if (!wp_verify_nonce((string)$_POST['gsm_product_nonce'],'gsm_product_versions')||($_POST['gsm_versions_complete']??'')!=='1') { throw new \WC_Data_Exception('gsm_versions_form','品牌商品表单不完整或已过期。'); }
        $old=$product->get_status()==='auto-draft'?0:(int)get_post_meta($product->get_id(),'_gsm_brand_id',true); $brand=absint($_POST['gsm_product_brand']??$old);
        if (!Brands::root()&&($brand!==$old||!Identities::accessible(get_current_user_id(),$old))) { throw new \WC_Data_Exception('gsm_versions_scope','没有该品牌商品权限。'); }
        if ($brand!==$old) { throw new \WC_Data_Exception('gsm_versions_owner','商品归属不能变更。请先创建公共商品，再为品牌建立继承版本。'); }
        if ($brand) { Stores::assert_store($brand); }
        $base=absint($_POST['gsm_base_product']??$product->get_meta('_gsm_base_product'));
        if ($base!==(int)get_post_meta($product->get_id(),'_gsm_base_product',true)) { throw new \WC_Data_Exception('gsm_versions_source','品牌商品必须保留原公共来源，不能解除或更换关联。'); }
        if ($brand&&!$base) { throw new \WC_Data_Exception('gsm_versions_required','品牌商品必须继承公共商品。'); }
        if ($base&&($base===$product->get_id()||get_post_meta($base,'_gsm_kind',true)!=='base'||wc_get_product($base)?->get_type()!==$product->get_type())) { throw new \WC_Data_Exception('gsm_versions_base','请选择相同商品类型的公共商品。'); }
        $product->update_meta_data('_gsm_brand_id',$brand); $product->update_meta_data('_gsm_kind',$brand?'edition':'base');
        $product->update_meta_data('_gsm_base_product',$brand?$base:0);
        $product->update_meta_data('_gsm_overrides',$brand&&$base?array_values(array_intersect((array)($_POST['gsm_product_overrides']??[]),array_keys(self::FIELDS))):[]);
        if ($brand&&$base) { self::parent_fields($product,wc_get_product($base)); }
        if (!$brand&&Brands::root()) { $product->update_meta_data('_gsm_requested_brands',array_map('absint',(array)($_POST['gsm_product_brands']??[]))); }
    }
    public static function after(\WC_Product $product): void {
        if (self::$busy||Brands::unrestricted()) { return; }
        self::$busy=true;
        try { Brands::unscoped(static function () use ($product): void {
            if (!$product->meta_exists('_gsm_brand_id')) { $owner=$product->is_type('variation')?(int)get_post_meta($product->get_parent_id(),'_gsm_brand_id',true):0; $product->update_meta_data('_gsm_brand_id',$owner); $product->update_meta_data('_gsm_kind',$owner?'edition':'base'); }
            if ((!$product->get_meta('_gsm_public_slug')||$product->get_meta('_gsm_public_slug')==='auto-draft')&&$product->get_type()!=='variation'&&$product->get_status()!=='auto-draft') { $product->update_meta_data('_gsm_public_slug',$product->get_slug()?:((string)get_post_field('post_name',$product->get_id())?:sanitize_title($product->get_name()))); }
            $product->save_meta_data();
            if ($product->get_meta('_gsm_kind')==='base') {
                if ($product->is_type('variable')) { $product=wc_get_product($product->get_id()); }
                $requested=(array)$product->get_meta('_gsm_requested_brands');
                foreach ($requested as $brand) { if (Brands::root()&&isset(Stores::all()[$brand])) { self::ensure_edition($product->get_id(),(int)$brand); } }
                $editions=get_posts(['post_type'=>'product','post_status'=>['publish','draft','private','pending','future'],'numberposts'=>-1,'fields'=>'ids','meta_key'=>'_gsm_base_product','meta_value'=>$product->get_id()]);
                foreach ($editions as $id) { $edition=wc_get_product($id); if ($edition&&!$edition->get_meta('_gsm_build_state')) { self::inherit($edition,$product); } }
            } elseif ($base=(int)$product->get_meta('_gsm_base_product')) { $source=wc_get_product($base); if ($source) { self::inherit($product,$source); if ($product->get_meta('_gsm_build_state')) { $product->delete_meta_data('_gsm_build_state');$product->save_meta_data(); } } }
        }); } finally { self::$busy=false; }
        if ($product->get_meta('_gsm_kind')==='base') { foreach (Stores::all() as $brand=>$_) { if (self::edition($product->get_id(),(int)$brand)) { Collections::refresh_brand((int)$brand); } } }
    }
    public static function edition(int $base,int $brand): int {
        $ids=Brands::unscoped(static fn()=>get_posts(['post_type'=>'product','post_status'=>'any','fields'=>'ids','numberposts'=>1,'meta_query'=>[['key'=>'_gsm_base_product','value'=>$base],['key'=>'_gsm_brand_id','value'=>$brand]]]));
        return (int)($ids[0]??0);
    }
    /** Serializes every native/bulk request for the same public-product/brand pair. */
    public static function ensure_edition(int $base,int $brand): array {
        Stores::authorize();Stores::assert_store($brand);if (!$brand) { throw new \WC_Data_Exception('gsm_edition_brand','请选择品牌。'); }
        $source=wc_get_product($base);if (!$source||$source->get_meta('_gsm_kind')!=='base'||BrandScope::product_brand($base)!==0||!$source->is_type(['simple','variable'])||in_array($source->get_status(),['trash','auto-draft'],true)) { throw new \WC_Data_Exception('gsm_edition_source','请选择有效公共商品。'); }
        global $wpdb;$key='gsm_edition_'.md5($wpdb->prefix.':'.$base.':'.$brand);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$key))!==1) { throw new \WC_Data_Exception('gsm_edition_busy','品牌版本正在处理。'); }
        try {
            $ids=Brands::unscoped(static fn()=>get_posts(['post_type'=>'product','post_status'=>['publish','draft','pending','private','future','trash','auto-draft'],'fields'=>'ids','numberposts'=>1,'meta_query'=>[['key'=>'_gsm_base_product','value'=>$base],['key'=>'_gsm_brand_id','value'=>$brand]]]));
            if ($ids) { return ['id'=>(int)$ids[0],'created'=>false,'incomplete'=>(bool)get_post_meta((int)$ids[0],'_gsm_build_state',true)]; }
            $product=self::create($source,$brand,$base);return ['id'=>$product->get_id(),'created'=>true,'incomplete'=>false];
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
    }
    public static function create(\WC_Product $source,int $brand,int $base=0): \WC_Product {
        if ($brand&&(!$base||$base!==$source->get_id()||$source->get_meta('_gsm_kind')!=='base'||BrandScope::product_brand($base)!==0)) { throw new \WC_Data_Exception('gsm_source_required','品牌商品必须从公共商品继承。'); }
        return Brands::unscoped(static function () use ($source,$brand,$base): \WC_Product {
            $created=[];$p=null;$v=null;
            try {
                $class=get_class($source);$p=new $class();$data=$source->get_data();
                foreach (['id','parent_id','sku','slug','meta_data','variations','children','rating_counts','average_rating','review_count','total_sales','date_created','date_modified'] as $key) { unset($data[$key]); }
                $data['category_ids']=array_values(array_filter($data['category_ids'],static fn($id)=>!get_term_meta($id,'_gsm_collection',true)));
                $p->set_props($data);$p->set_status('draft');$p->set_sku('GSM-'.($brand?'B'.$brand:'BASE').'-'.substr(str_replace('-','',wp_generate_uuid4()),0,14));
                $p->update_meta_data('_gsm_brand_id',$brand);$p->update_meta_data('_gsm_kind',$brand?'edition':'base');$p->update_meta_data('_gsm_base_product',$base);$p->update_meta_data('_gsm_build_state','creating');
                $p->update_meta_data('_gsm_business_sku',$source->get_meta('_gsm_business_sku')?:$source->get_sku());$p->update_meta_data('_gsm_public_slug',$source->get_meta('_gsm_public_slug')?:$source->get_slug());$p->update_meta_data('_gsm_overrides',[]);
                if (!$p->save()) { throw new \RuntimeException('Native create failed'); }$created[]=$p->get_id();
                if ($source->is_type('variable')) {
                    foreach ($source->get_children() as $child) {
                        $s=wc_get_product($child);if (!$s) { continue; }$v=new \WC_Product_Variation();$props=$s->get_data();
                        foreach (['id','sku','slug','meta_data','parent_id','date_created','date_modified'] as $key) { unset($props[$key]); }
                        $v->set_props($props);$v->set_parent_id($p->get_id());$v->set_sku('GSM-V'.$p->get_id().'-'.$child);$v->update_meta_data('_gsm_brand_id',$brand);$v->update_meta_data('_gsm_base_variation',$child);$v->update_meta_data('_gsm_overrides',[]);
                        if (!$v->save()) { throw new \RuntimeException('Native variation create failed'); }$created[]=$v->get_id();
                    }
                    \WC_Product_Variable::sync($p->get_id());
                }
                $p->delete_meta_data('_gsm_build_state');$p->save_meta_data();wc_delete_product_transients($p->get_id());return wc_get_product($p->get_id());
            } catch (\Throwable $e) {
                foreach (array_reverse(array_unique(array_filter(array_merge($created,[$p?->get_id(),$v?->get_id()])))) as $id) { try { $fresh=wc_get_product($id);if ($fresh) { $fresh->delete(true); } } catch (\Throwable $cleanup) {} }
                throw $e;
            }
        });
    }
    private static function parent_fields(\WC_Product $edition,\WC_Product $base): void {
        $over=(array)$edition->get_meta('_gsm_overrides');
        foreach (self::COMMON as $field) { $get='get_'.$field; $set='set_'.$field; $edition->$set($base->$get('edit')); }
        $ordinary=array_values(array_filter($base->get_category_ids('edit'),static fn($id)=>!get_term_meta($id,'_gsm_collection',true)));
        $owned=array_values(array_filter($edition->get_category_ids('edit'),static fn($id)=>get_term_meta($id,'_gsm_collection',true)&&(int)get_term_meta($id,'_gsm_brand_id',true)===(int)$edition->get_meta('_gsm_brand_id')));
        $edition->set_category_ids(array_values(array_unique(array_merge($ordinary,$owned)))); $edition->set_tag_ids($base->get_tag_ids('edit'));
        foreach (self::FIELDS as $field=>$_) {
            if ($field==='presentation') { continue; }
            if (in_array($field,$over,true)) { continue; }
            $props=match($field) { 'images'=>['image_id','gallery_image_ids'],'price'=>['regular_price','sale_price','date_on_sale_from','date_on_sale_to'],default=>[$field] };
            foreach ($props as $prop) { if ($edition->is_type('variable')&&in_array($prop,['regular_price','sale_price'],true)) { continue; } $edition->{'set_'.$prop}($base->{'get_'.$prop}('edit')); }
        }
    }
    public static function inherit(\WC_Product $edition,\WC_Product $base): void {
        $over=(array)$edition->get_meta('_gsm_overrides');
        self::parent_fields($edition,$base);
        $edition->save();
        if ($base->is_type('variable')&&$edition->is_type('variable')) {
            $base_children=$base->get_children(); $mapped=[];
            foreach ($edition->get_children() as $id) { $v=wc_get_product($id); $source=(int)$v->get_meta('_gsm_base_variation'); if ($source) { $mapped[$source]=$v; } }
            foreach ($base_children as $id) {
                $s=wc_get_product($id); if (!$s) { continue; }
                $v=$mapped[$id]??new \WC_Product_Variation(); $new=!$v->get_id();
                if ($new) { $v->set_parent_id($edition->get_id()); $v->set_sku('GSM-V'.$edition->get_id().'-'.$id); $v->update_meta_data('_gsm_base_variation',$id); $v->update_meta_data('_gsm_brand_id',(int)$edition->get_meta('_gsm_brand_id')); }
                self::variation_fields($v,$s,$over);
                if ($new) { $v->set_status($s->get_status()); } $v->save();
            }
            foreach ($mapped as $id=>$v) { if (!in_array($id,$base_children,true)) { $v->set_status('private'); $v->save(); } }
            \WC_Product_Variable::sync($edition->get_id());
        }
        wc_delete_product_transients($edition->get_id());
    }
}
