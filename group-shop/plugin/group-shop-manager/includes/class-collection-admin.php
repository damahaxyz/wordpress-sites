<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

final class CollectionAdmin {
    public static function boot(): void { add_action('wp_ajax_gsm_collection_products',[self::class,'search']); }
    public static function search(): void {
        try {
            Stores::authorize(); $store=absint($_POST['store']??0);
            if (!wp_verify_nonce((string)($_POST['nonce']??''),'gsm_collection_search_'.$store)) { throw new \RuntimeException('选品授权已过期，请刷新编辑页。'); }
            $search=sanitize_text_field(wp_unslash($_POST['search']??''));
            $items=Stores::within($store,static function () use ($search): array {
                $args=['post_type'=>'product','post_status'=>['publish','draft','private','pending','future'],'posts_per_page'=>25,'orderby'=>'title','order'=>'ASC','no_found_rows'=>true,'s'=>$search,'search_columns'=>['post_title']];
                if (ctype_digit($search)&&get_post_type((int)$search)==='product') { unset($args['s']); $args['post__in']=[(int)$search]; }
                return array_map(static fn($post)=>self::product((int)$post->ID),get_posts($args));
            });
            wp_send_json_success($items);
        } catch (\Throwable $error) { wp_send_json_error(['message'=>$error->getMessage()],403); }
    }
    private static function product(int $id): array {
        $post=get_post($id); $status=get_post_status_object($post->post_status);
        return ['id'=>$id,'name'=>$post->post_title,'status'=>$status?$status->label:$post->post_status,'image'=>get_the_post_thumbnail_url($id,'thumbnail')?:wc_placeholder_img_src('thumbnail')];
    }
    public static function checks(string $taxonomy,string $name,array $selected,int $store,string $label): void {
        $terms=Stores::within($store,static fn()=>Collections::terms($taxonomy));
        echo '<fieldset class="gsm-picker"><legend>'.esc_html($label).'</legend><input type="search" class="gsm-filter" placeholder="按名称搜索" aria-label="'.esc_attr($label).'搜索"><input type="hidden" name="'.esc_attr($name).'[]" value=""><div class="gsm-picker-list">';
        foreach ($terms as $term) {
            $depth=$taxonomy==='product_cat'?count(get_ancestors($term->term_id,$taxonomy,'taxonomy')):0;
            echo '<label class="gsm-choice" style="padding-left:'.(12+min(8,$depth)*18).'px"><input type="checkbox" name="'.esc_attr($name).'[]" value="'.(int)$term->term_id.'" '.checked(in_array((int)$term->term_id,$selected,true),true,false).'> '.esc_html($term->name).'</label>';
        }
        if (!$terms) { echo '<p>暂无可选项，请在主站原生产品分类／标签中添加。</p>'; }
        echo '</div></fieldset>';
    }
    private static function select(string $label,string $name,array $choices,mixed $value): void {
        echo '<label class="gsm-field"><span>'.esc_html($label).'</span><select name="'.esc_attr($name).'">';
        foreach ($choices as $key=>$text) { echo '<option value="'.esc_attr((string)$key).'" '.selected((string)$value,(string)$key,false).'>'.esc_html($text).'</option>'; }
        echo '</select></label>';
    }
    private static function picker(string $label,string $name,array $selected): void {
        echo '<fieldset class="gsm-picker gsm-product-picker" data-name="'.esc_attr($name).'" data-priority="'.($name==='priority'?'1':'0').'"><legend>'.esc_html($label).' <span class="gsm-selection-count">'.count($selected).'</span></legend><input type="hidden" name="'.esc_attr($name).'[]" value=""><ul class="gsm-selected-products">';
        foreach ($selected as $id) {
            if (get_post_type($id)!=='product'||get_post_status($id)==='trash') { continue; }
            $item=self::product($id);
            echo '<li data-product="'.$id.'"><label><input type="checkbox" name="'.esc_attr($name).'[]" value="'.$id.'" checked><img src="'.esc_url($item['image']).'" alt=""><span>'.esc_html($item['name']).'<small>#'.$id.' · '.esc_html($item['status']).'</small></span></label>';
            if ($name==='priority') { echo '<button type="button" class="button gsm-move" data-direction="up" aria-label="上移">↑</button><button type="button" class="button gsm-move" data-direction="down" aria-label="下移">↓</button>'; }
            echo '</li>';
        }
        echo '</ul><div class="gsm-product-search"><input type="search" placeholder="商品名称或编号" aria-label="'.esc_attr($label).'搜索"><button type="button" class="button gsm-browse-products">查找商品</button></div><p class="gsm-search-status" role="status">在当前店铺搜索并选入，取消勾选即可移除。</p><div class="gsm-product-results"></div></fieldset>';
    }
    public static function collections(int $store): void {
        Stores::authorize(); if (!$store) { return; }
        Stores::within($store,static function () use ($store): void {
            if (isset($_GET['collection'])) { self::editor($store,absint($_GET['collection'])); }
            else { self::listing($store); }
        });
    }
    private static function listing(int $store): void {
        $trash=($_GET['view']??'')==='trash'; $all=Collections::collections();
        $active=count(array_filter($all,static fn($rule)=>empty($rule['deleted_at']))); $deleted=count($all)-$active;
        echo '<div class="gsm-stores-heading"><div><h2>商品集合</h2><p>为首页模块、专题页面和 Shop 筛选统一组织当前店铺的商品。</p></div><a class="button button-primary" href="'.esc_url(Admin::url('collections',['store'=>$store,'collection'=>'new'])).'">创建集合</a></div>';
        echo '<p class="gsm-collection-views"><a href="'.esc_url(Admin::url('collections',['store'=>$store])).'" '.(!$trash?'aria-current="page"':'').'>集合列表 ('.$active.')</a> · <a href="'.esc_url(Admin::url('collections',['store'=>$store,'view'=>'trash'])).'" '.($trash?'aria-current="page"':'').'>回收站 ('.$deleted.')</a></p>';
        if (get_option('gsm_collections_dirty')) { echo '<div class="notice notice-warning inline"><p>有变更等待重建，请重建后检查展示结果。</p></div>'; }
        if (get_option('gsm_collections_review')) { echo '<div class="notice notice-warning inline"><p>部分集合需要重新选择分类／标签条件，修正后保存即可。</p></div>'; }
        echo '<table class="widefat striped gsm-collection-table"><thead><tr><th>图片</th><th>标题／描述</th><th>选品方式</th><th>已发布商品</th><th>展示位置／状态</th><th>操作</th></tr></thead><tbody>';
        $shown=0;
        foreach (Collections::tree(array_filter($all,static fn($rule)=>$trash===!empty($rule['deleted_at']))) as $row) {
            $id=$row['id']; $term=$row['term']; $rule=$all[$id]; $shown++;
            $image=(int)get_term_meta((int)$id,'thumbnail_id',true); $url=get_term_link($term);
            $parent=$term->parent?get_term((int)$term->parent,'product_cat'):null;
            echo '<tr data-collection="'.(int)$id.'"><td>'.($image?wp_get_attachment_image($image,'thumbnail',false,['class'=>'gsm-collection-thumb']):'<span class="gsm-collection-no-image">暂无图片</span>').'</td><td><strong>'.esc_html(str_repeat('— ',$row['depth']).$term->name).'</strong>'.($parent&&!is_wp_error($parent)?'<br><span class="description">父级：'.esc_html($parent->name).'</span>':'').'<p>'.esc_html(wp_trim_words(wp_strip_all_tags($term->description),24,'…')).'</p></td><td>'.esc_html(($rule['mode']??'rule')==='manual'?'手动选品':'分类／标签规则').'</td><td>'.(int)$term->count.'</td><td>'.(!empty($rule['shop_filter'])?'页面模块 · Shop 筛选':'页面模块').'<br>'.esc_html($trash?'已删除':(!empty($rule['needs_review'])?'需要修正规则':(!empty($rule['enabled'])?'启用':'停用'))).'</td><td><div class="gsm-product-actions">';
            if (!$trash) {
                echo '<a class="button" href="'.esc_url(Admin::url('collections',['store'=>$store,'collection'=>$id])).'">编辑</a>';
                if (!empty($rule['enabled'])&&!is_wp_error($url)) { echo '<a class="button" href="'.esc_url($url).'" target="_blank" rel="noopener">查看商品</a>'; }
            }
            Admin::form($trash?'collection_restore':'collection_trash',$store,'collections');
            echo '<input type="hidden" name="collection" value="'.(int)$id.'"><button type="submit" class="button">'.($trash?'恢复集合':'删除').'</button></form></div></td></tr>';
        }
        if (!$shown) { echo '<tr><td colspan="6">'.($trash?'回收站为空。':'还没有集合，点击“创建集合”开始组织商品。').'</td></tr>'; }
        echo '</tbody></table><p class="description">删除会移入回收站，并从页面模块和 Shop 筛选移除；商品、分类与标签保留，可恢复集合。</p>';
        if (!$trash) { echo '<details class="gsm-collection-maintenance"><summary>集合维护</summary>'; Admin::form('collections_rebuild',$store,'collections'); submit_button('重建当前店铺集合','secondary'); echo '</form><p>每店最多 50 个未删除集合；规则重建支持 2000 件父商品。</p></details>'; }
    }
    private static function editor(int $store,int $id): void {
        $all=Collections::collections();
        if ($id&&(!isset($all[$id])||!empty($all[$id]['deleted_at']))) { throw new \RuntimeException('集合不存在或已删除。'); }
        $spec=$all[$id]??['mode'=>'rule','categories'=>[],'tags'=>[],'tag_match'=>'any','include'=>[],'exclude'=>[],'priority'=>[],'sort'=>'menu_order','enabled'=>true,'navigation'=>false,'shop_filter'=>true];
        $value=$id?Collections::values($id):['name'=>'','description'=>'','image'=>0,'order'=>0,'parent'=>0];
        $retry=get_site_transient('gsm_collection_retry_'.get_current_user_id());
        if (is_array($retry)&&$retry['store']===$store&&$retry['collection']===$id) {
            $data=$retry['fields'];
            foreach (['name','description','image','order','parent'] as $key) { if (isset($data[$key])&&is_scalar($data[$key])) { $value[$key]=$data[$key]; } }
            foreach (['mode','tag_match','sort'] as $key) { if (isset($data[$key])&&is_scalar($data[$key])) { $spec[$key]=$data[$key]; } }
            foreach (['categories','tags','include','exclude','priority'] as $key) { if (isset($data[$key])) { $spec[$key]=Collections::ids($data[$key]); } }
            foreach (['enabled','navigation','shop_filter'] as $key) { $spec[$key]=!empty($data[$key]); }
            echo '<p class="notice notice-warning inline">上次提交的内容已保留，请修正后保存。</p>';
        }
        echo '<p><a href="'.esc_url(Admin::url('collections',['store'=>$store])).'">← 返回集合列表</a></p><section class="gsm-card gsm-collection-editor"><h2>'.($id?'编辑集合':'创建集合').'</h2>';
        Admin::form('collection_save',$store,'collections'); echo '<input type="hidden" name="collection" value="'.$id.'"><input type="hidden" name="collection_editor" value="1">';
        echo '<div class="gsm-product-layout"><div class="gsm-product-content">';
        Admin::input('集合标题','name',$value['name'],'text','required');
        $parents=[0=>'无父级（顶层集合）'];
        foreach (Collections::tree(array_filter($all,static fn($rule)=>empty($rule['deleted_at']))) as $row) {
            if ($row['id']===$id||($id&&term_is_ancestor_of($id,$row['id'],'product_cat'))) { continue; }
            $parents[$row['id']]=str_repeat('— ',$row['depth']).$row['term']->name;
        }
        if ((int)$value['parent']&&!isset($parents[(int)$value['parent']])) { $parents[(int)$value['parent']]='原父级不可用，请重新选择'; }
        self::select('父级集合','parent',$parents,$value['parent']);
        echo '<p class="description">只可选择同店铺未删除的集合。层级用于组织集合，各集合仍按自己的选品规则展示商品。</p>';
        echo '<div class="gsm-field"><label for="gsm_collection_description"><strong>集合描述</strong></label>';
        wp_editor((string)$value['description'],'gsm_collection_description',['textarea_name'=>'description','editor_height'=>140,'media_buttons'=>false,'teeny'=>true,'tinymce'=>['wp_autoresize_on'=>false],'quicktags'=>true]); echo '</div>';
        self::select('选品方式','mode',['rule'=>'根据分类／标签筛选','manual'=>'直接选择商品'],$spec['mode']);
        echo '<div class="gsm-collection-rule-fields"><p>可只选分类、只选标签或同时选择。多个分类匹配任一（含子分类）；同时设置分类和标签时，两组条件都需满足。</p><div class="gsm-grid">';
        self::checks('product_cat','categories',$spec['categories'],$store,'产品分类'); self::checks('product_tag','tags',$spec['tags'],$store,'商品标签'); echo '</div>';
        self::select('标签匹配','tag_match',['any'=>'匹配任一标签','all'=>'匹配全部标签'],$spec['tag_match']); echo '</div>';
        self::picker('选入商品','include',$spec['include']);
        echo '<p class="description gsm-collection-include-help">规则模式下作为额外选入，手动模式下直接组成集合。草稿和禁用商品不会在前台展示。</p><details class="gsm-collection-advanced"><summary>排除商品与展示排序</summary>';
        self::picker('排除商品','exclude',$spec['exclude']);
        self::select('商品展示排序','sort',['menu_order'=>'店铺商品排序','title'=>'名称 A–Z','date'=>'最新商品优先','manual'=>'手动置顶顺序'],$spec['sort']);
        self::picker('置顶商品顺序','priority',$spec['priority']); echo '<p>置顶商品需要已符合集合条件或选入集合；用上下按钮调整顺序，其余匹配商品继续显示。</p></details>';
        echo '</div><aside class="gsm-product-sidebar"><div class="gsm-product-panel"><h3>集合图片</h3>';
        $images=[]; $image=absint($value['image']); if ($image&&wp_attachment_is_image($image)) { $images[]=['id'=>$image,'url'=>wp_get_attachment_image_url($image,'thumbnail')?:wp_get_attachment_url($image),'alt'=>get_the_title($image)]; }
        echo '<div class="gsm-image-picker" data-multiple="0" data-image-label="集合图片"><input type="hidden" name="image" value="'.$image.'"><div class="gsm-image-previews" data-images="'.esc_attr(wp_json_encode($images)).'"></div><button class="button gsm-images-select" type="button">选择图片</button> <button class="button gsm-images-clear" type="button">移除图片</button><p class="description">从当前店铺媒体库选择或上传封面。</p></div></div><div class="gsm-product-panel"><h3>展示设置</h3>';
        foreach (['enabled'=>'启用集合','shop_filter'=>'在 Shop 商品集合筛选中显示','navigation'=>'显示在集合导航中'] as $key=>$label) { echo '<p><label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(!empty($spec[$key]),true,false).'> '.esc_html($label).'</label></p>'; }
        Admin::input('集合排序（越小越靠前）','order',$value['order'],'number');
        echo '<p>集合只组织当前店铺商品，不改变商品发布状态。</p><button type="submit" class="button button-primary button-large">保存集合</button></div>';
        if ($id) {
            echo '<details class="gsm-product-panel"><summary>用于首页或专题页面</summary><p>在所属店铺页面编辑器添加“商品集合”区块，直接选择此集合；也可在短代码区块插入：</p><code>[group_shop_collection id="'.$id.'" limit="8" columns="4"]</code><p>专题分页可增加 <code>paginate="yes"</code>。图片、标题及描述均从本集合读取，主题也可直接选用集合数据。</p></details>';
        }
        echo '</aside></div><input type="hidden" name="gsm_collection_complete" value="1"></form></section>';
    }
}
