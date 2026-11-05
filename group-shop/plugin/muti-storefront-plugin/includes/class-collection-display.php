<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Homepage/topic modules and Shop filtering all read the same store-local materialized membership. */
final class CollectionDisplay {
    public static function boot(): void {
        add_action('init',[self::class,'register_block'],20);
        add_shortcode('group_shop_collection',[self::class,'shortcode']);
        add_shortcode('group_shop_collection_filter',[self::class,'filter_form']);
        add_action('woocommerce_product_query',[self::class,'filter_shop'],20);
        add_action('woocommerce_before_shop_loop',static function (): void { if (is_shop()) { echo self::filter_form(); } },15);
        add_action('woocommerce_no_products_found',static function (): void { if (is_shop()) { echo self::filter_form(); } },5);
        add_action('template_redirect',static function (): void {
            $term=get_queried_object();
            if ($term instanceof \WP_Term && $term->taxonomy==='product_cat' && !empty(Collections::collections()[$term->term_id]['deleted_at'])) {
                $GLOBALS['wp_query']->set_404(); status_header(404); nocache_headers();
            }
        },1);
        add_action('wp_enqueue_scripts',static function (): void {
            wp_enqueue_style('gsm-collections',plugins_url('assets/collections.css',GSM_FILE),[],GSM_VERSION);
        });
    }
    public static function register_block(): void {
        wp_register_style('gsm-collections',plugins_url('assets/collections.css',GSM_FILE),[],GSM_VERSION);
        wp_register_script('gsm-collection-block',plugins_url('assets/collection-block.js',GSM_FILE),['wp-blocks','wp-element','wp-block-editor','wp-components','wp-server-side-render'],GSM_VERSION,true);
        $items=[];
        foreach (Collections::tree(array_filter(Collections::collections(),static fn($rule)=>!empty($rule['enabled'])&&empty($rule['deleted_at']))) as $row) { $data=self::data($row['id']); if ($data) { $items[]=['value'=>$row['id'],'label'=>str_repeat('— ',$row['depth']).$data['title']]; } }
        wp_localize_script('gsm-collection-block','gsmCollectionBlocks',['items'=>$items]);
        register_block_type('group-shop/collection',[
            'api_version'=>3,'editor_script'=>'gsm-collection-block','style'=>'gsm-collections','supports'=>['html'=>false],
            'attributes'=>[
                'collectionId'=>['type'=>'integer','default'=>0],'perPage'=>['type'=>'integer','default'=>8],'columns'=>['type'=>'integer','default'=>4],
                'paginate'=>['type'=>'boolean','default'=>false],'showTitle'=>['type'=>'boolean','default'=>true],'showDescription'=>['type'=>'boolean','default'=>true],'showImage'=>['type'=>'boolean','default'=>false],
            ],
            'render_callback'=>static fn($attrs)=>self::shortcode(['id'=>$attrs['collectionId'],'limit'=>$attrs['perPage'],'columns'=>$attrs['columns'],'paginate'=>$attrs['paginate']?'yes':'no','title'=>$attrs['showTitle']?'yes':'no','description'=>$attrs['showDescription']?'yes':'no','image'=>$attrs['showImage']?'yes':'no']),
        ]);
    }
    public static function data(int $id): ?array {
        $rule=Collections::collections()[$id]??null;
        if (!$rule || ($rule['taxonomy_scope']??'')!=='local' || empty($rule['enabled']) || !empty($rule['deleted_at'])) { return null; }
        $term=get_term($id,'product_cat');
        if (!$term || is_wp_error($term) || !get_term_meta($id,'_gsm_collection',true)) { return null; }
        $image=(int)get_term_meta($id,'thumbnail_id',true); $url=get_term_link($term);
        return ['id'=>$id,'parent'=>(int)$term->parent,'title'=>$term->name,'description'=>$term->description,'image_id'=>$image,'image_url'=>$image?(wp_get_attachment_image_url($image,'large')?:''):'','url'=>is_wp_error($url)?'':$url,'sort'=>$rule['sort'],'shop_filter'=>!empty($rule['shop_filter']),'order'=>(int)get_term_meta($id,'order',true)];
    }
    public static function query_args(int $id,array $options=[]): array {
        $size=max(1,min(48,absint($options['posts_per_page']??8)));
        $args=['post_type'=>'product','post_status'=>'publish','ignore_sticky_posts'=>true,'posts_per_page'=>$size,'paged'=>max(1,absint($options['paged']??1)),'orderby'=>'menu_order title','order'=>'ASC','tax_query'=>['relation'=>'AND',WC()->query->get_tax_query([],false),['taxonomy'=>'product_cat','field'=>'term_id','terms'=>[$id],'include_children'=>false]],'meta_query'=>WC()->query->get_meta_query([],false)];
        $data=self::data($id);
        if (!$data) { $args['post__in']=[0]; return $args; }
        $sort=$data['sort'];
        if ($sort==='manual') {
            $members=get_posts(array_merge($args,['posts_per_page'=>Collections::MAX_PRODUCTS,'paged'=>1,'fields'=>'ids','no_found_rows'=>true]));
            $priority=Collections::collections()[$id]['priority']??[];
            $args['post__in']=array_merge(array_values(array_intersect($priority,$members)),array_values(array_diff($members,$priority)))?:[0];
            $args['orderby']='post__in';
        } elseif ($sort==='date') { $args['orderby']='date'; $args['order']='DESC'; }
        elseif ($sort==='title') { $args['orderby']='title'; }
        return $args;
    }
    public static function shortcode(array|string $attributes=[]): string {
        $attributes=shortcode_atts(['id'=>0,'limit'=>8,'columns'=>4,'paginate'=>'no','title'=>'yes','description'=>'yes','image'=>'no'],(array)$attributes,'group_shop_collection');
        $id=absint($attributes['id']); $data=self::data($id); if (!$data) { return ''; }
        $paginate=in_array((string)$attributes['paginate'],['1','true','yes'],true);
        $page_key='gsm_page_'.$id; $page=$paginate?max(1,absint(is_scalar($_GET[$page_key]??null)?$_GET[$page_key]:1)):1;
        $query=new \WP_Query(self::query_args($id,['posts_per_page'=>$attributes['limit'],'paged'=>$page]));
        $previous_loop=$GLOBALS['woocommerce_loop']??null; $previous_product=$GLOBALS['product']??null; $previous_post=$GLOBALS['post']??null;
        ob_start();
        try {
            echo '<section class="gsm-collection-module" data-collection="'.$id.'">';
            if ($attributes['image']==='yes' && $data['image_id']) { echo wp_get_attachment_image($data['image_id'],'large',false,['class'=>'gsm-collection-cover']); }
            if ($attributes['title']!=='no') { echo '<h2>'.esc_html($data['title']).'</h2>'; }
            if ($attributes['description']!=='no' && $data['description']) { echo '<div class="gsm-collection-description">'.wp_kses_post(wpautop($data['description'])).'</div>'; }
            wc_setup_loop(['name'=>'gsm_collection','is_shortcode'=>true,'is_paginated'=>$paginate,'columns'=>max(1,min(6,absint($attributes['columns']))),'total'=>(int)$query->found_posts,'total_pages'=>(int)$query->max_num_pages,'per_page'=>(int)$query->get('posts_per_page'),'current_page'=>$page]);
            if ($query->have_posts()) {
                woocommerce_product_loop_start();
                while ($query->have_posts()) { $query->the_post(); $GLOBALS['product']=wc_get_product(get_the_ID()); wc_get_template_part('content','product'); }
                woocommerce_product_loop_end();
                if ($paginate && $query->max_num_pages>1) {
                    echo '<nav class="woocommerce-pagination" aria-label="集合商品分页">'.wp_kses_post(paginate_links(['base'=>add_query_arg($page_key,'%#%'),'format'=>'','current'=>$page,'total'=>$query->max_num_pages,'type'=>'list'])).'</nav>';
                }
            } else { echo '<p class="gsm-collection-empty">此集合暂无可展示的商品。</p>'; }
            echo '</section>';
            return (string)ob_get_contents();
        } finally {
            ob_end_clean(); wp_reset_postdata();
            if ($previous_post instanceof \WP_Post) { $GLOBALS['post']=$previous_post; setup_postdata($previous_post); }
            else { unset($GLOBALS['post']); }
            if ($previous_loop===null) { unset($GLOBALS['woocommerce_loop']); } else { $GLOBALS['woocommerce_loop']=$previous_loop; }
            $GLOBALS['product']=$previous_product;
        }
    }
    private static function selected(): int {
        $value=$_GET['gsm_collection']??'';
        return is_scalar($value)&&preg_match('/^[1-9][0-9]*$/D',(string)$value)?(int)$value:0;
    }
    public static function filter_shop(\WP_Query $query): void {
        if (is_admin() || !$query->is_main_query() || !isset($_GET['gsm_collection']) || $_GET['gsm_collection']==='') { return; }
        $id=self::selected(); $data=self::data($id);
        if (!$data || !$data['shop_filter']) { $query->set('post__in',[0]); return; }
        $tax=(array)$query->get('tax_query');
        $query->set('tax_query',['relation'=>'AND',$tax,['taxonomy'=>'product_cat','field'=>'term_id','terms'=>[$id],'include_children'=>false]]);
        if (empty($_GET['orderby'])) {
            $args=self::query_args($id);
            if (isset($args['post__in'])) {
                $existing=$query->get('post__in');
                $query->set('post__in',$existing?array_values(array_intersect($args['post__in'],(array)$existing))?:[0]:$args['post__in']);
            }
            $query->set('orderby',$args['orderby']); $query->set('order',$args['order']);
        }
    }
    public static function filter_form(array|string $attributes=[]): string {
        $items=[];
        $rules=array_filter(Collections::collections(),static fn($rule)=>!empty($rule['enabled'])&&!empty($rule['shop_filter'])&&empty($rule['deleted_at']));
        foreach (Collections::tree($rules) as $row) { $data=self::data($row['id']); if ($data) { $data['title']=str_repeat('— ',$row['depth']).$data['title']; $items[]=$data; } }
        if (!$items) { return ''; }
        $html='<form method="get" action="'.esc_url(wc_get_page_permalink('shop')).'" class="gsm-shop-collection-filter"><label>商品集合 <select name="gsm_collection"><option value="">全部商品</option>';
        foreach ($items as $item) { $html.='<option value="'.$item['id'].'" '.selected(self::selected(),$item['id'],false).'>'.esc_html($item['title']).'</option>'; }
        $html.='</select></label>';
        // Preserve only existing native catalog filters, never credentials, arbitrary actions or old pagination.
        foreach ($_GET as $key=>$value) {
            if (!is_scalar($value) || !preg_match('/^(?:filter_[a-z0-9_-]+|query_type_[a-z0-9_-]+|min_price|max_price|rating_filter|orderby|s|post_type|lang)$/D',(string)$key)) { continue; }
            $html.='<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr(wp_unslash((string)$value)).'">';
        }
        return $html.'<button type="submit">筛选商品</button></form>';
    }
}
