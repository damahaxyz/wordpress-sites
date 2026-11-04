<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Store-specific editing, using WordPress's native rich text and media controls. */
final class ProductAdmin {
    public const VARIATIONS_PER_PAGE=20;
    public static function render(int $store): void {
        if (!$store) { return; }
        Stores::within($store, static function () use ($store): void {
            $id=absint($_GET['product']??0);
            if ($id) { self::editor($store,$id); return; }
            $page=max(1,absint($_GET['paged']??1));
            $query=wc_get_products(['limit'=>25,'page'=>$page,'paginate'=>true,'status'=>['publish','draft','private','pending','future'],'orderby'=>'menu_order','order'=>'ASC']);
            echo '<p>上架和禁用只影响当前店铺。禁用设为草稿，商品资料与其他店铺版本保留。</p><table class="widefat striped gsm-products"><thead><tr><th>主图</th><th>商品</th><th>状态／类型</th><th>价格</th><th>排序</th><th>操作</th></tr></thead><tbody>';
            foreach ($query->products as $p) {
                $edit=Admin::url('products',['store'=>$store,'product'=>$p->get_id(),'paged'=>$page]);
                echo '<tr data-product="'.$p->get_id().'"><td><a href="'.esc_url($edit).'">'.wp_kses_post($p->get_image('thumbnail',['class'=>'gsm-product-thumb'])).'</a></td><td><a href="'.esc_url($edit).'"><strong>'.esc_html($p->get_name()).'</strong></a><br><span class="description">#'.$p->get_id().'</span></td><td><span class="gsm-status '.($p->get_status()==='publish'?'is-published':'').'">'.esc_html(self::statuses()[$p->get_status()]??$p->get_status()).'</span><br>'.esc_html(wc_get_product_types()[$p->get_type()]??$p->get_type()).'</td><td>'.wp_kses_post($p->get_price_html()).'</td><td>'.$p->get_menu_order().'</td><td><div class="gsm-product-actions"><a class="button" href="'.esc_url($edit).'">编辑商品</a>';
                if ($p->is_type(['simple','variable'])) {
                    Admin::form('edition_status',$store,'products');
                    foreach (['product'=>$p->get_id(),'paged'=>$page,'expected_status'=>$p->get_status(),'status'=>$p->get_status()==='publish'?'draft':'publish'] as $key=>$value) { self::hidden($key,(string)$value); }
                    echo '<button class="button" type="submit">'.($p->get_status()==='publish'?'禁用商品':'上架商品').'</button></form>';
                }
                echo '</div></td></tr>';
            }
            if (!$query->products) { echo '<tr><td colspan="6">当前店铺暂无商品。</td></tr>'; }
            echo '</tbody></table>';
            self::pages($store,0,$page,(int)$query->total,25);
            self::native_links($store);
        });
    }
    private static function statuses(): array { return ['publish'=>'已上架','draft'=>'已禁用／草稿','private'=>'私有','pending'=>'待审核','future'=>'定时发布']; }
    private static function hidden(string $name,string $value): void { echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'">'; }
    private static function native_links(int $store): void {
        echo '<p><a class="button" href="'.esc_url(get_admin_url(Stores::main_id(),'edit-tags.php?taxonomy=product_cat&post_type=product')).'">统一产品分类</a> <a class="button" href="'.esc_url(get_admin_url(Stores::main_id(),'edit-tags.php?taxonomy=product_tag&post_type=product')).'">统一商品标签</a> <a class="button" href="'.esc_url(Admin::url('collections',['store'=>$store])).'">商品集合</a></p>';
    }
    private static function editor(int $store,int $id): void {
        $p=wc_get_product($id);
        if (!$p || !$p->is_type(['simple','variable','variation']) || in_array($p->get_status(),['trash','auto-draft'],true)) { throw new \RuntimeException('商品不存在或类型不受支持。'); }
        $retry=get_site_transient('gsm_product_retry_'.get_current_user_id());
        $fields=is_array($retry)&&$retry['store']===$store&&$retry['product']===$id?$retry['fields']:[];
        $value=static fn($key,$fallback)=>isset($fields[$key])&&is_scalar($fields[$key])?(string)$fields[$key]:(string)$fallback;
        $page=max(1,absint($_GET['paged']??1));
        echo '<p><a href="'.esc_url(Admin::url('products',['store'=>$store,'paged'=>$page])).'">← 返回商品列表</a></p><section class="gsm-card gsm-product-editor"><h2>'.esc_html($p->get_name()).' <span class="description">#'.$id.' · 仅编辑当前店铺</span></h2>';
        if ($fields) { echo '<p class="notice notice-warning inline">已保留上次提交的内容，请修正后重新保存。</p>'; }
        Admin::form('edition_save',$store,'products');
        self::hidden('product',(string)$id); self::hidden('paged',(string)$page);
        echo '<div class="gsm-product-layout"><div class="gsm-product-content">';
        if (!$p->is_type('variation')) { Admin::input('商品名称','name',$value('name',$p->get_name('edit'))); }
        if ($p->is_type('variable')) { self::variations($store,$p,$fields); }
        self::rich_text('商品详情','description',$value('description',$p->get_description('edit')),260);
        if (!$p->is_type('variation')) { self::rich_text('商品短描述','short_description',$value('short_description',$p->get_short_description('edit')),130); }
        echo '<p class="description">分类与标签在主站原生产品后台统一维护，此处不编辑。</p>';
        echo '</div><aside class="gsm-product-sidebar"><div class="gsm-product-panel"><h3>发布与价格</h3>';
        echo '<label class="gsm-field"><span>当前店铺发布状态</span><select name="status">';
        $statuses=$p->is_type('variation')?['publish'=>'启用变体','private'=>'禁用变体']:self::statuses();
        foreach ($statuses as $key=>$label) {
            if (in_array($key,['pending','future'],true)&&$p->get_status()!==$key) { continue; }
            echo '<option value="'.esc_attr($key).'" '.selected($value('status',$p->get_status()),$key,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label><p class="description">草稿／私有商品不向普通顾客展示，其他店铺不受影响。</p>';
        if (!$p->is_type('variable')) { self::price('普通售价','regular_price',$value('regular_price',$p->get_regular_price('edit'))); self::price('促销售价（留空取消促销）','sale_price',$value('sale_price',$p->get_sale_price('edit'))); }
        Admin::input('排序值（越小越靠前）','menu_order',$value('menu_order',$p->get_menu_order()),'number');
        echo '<button type="submit" class="button button-primary button-large">保存该店商品</button></div><div class="gsm-product-panel"><h3>商品图片</h3>';
        $images=array_filter(array_merge([$p->get_image_id('edit')],$p->is_type('variation')?[]:$p->get_gallery_image_ids('edit')));
        self::images('image_ids',$value('image_ids',implode(',',$images)),!$p->is_type('variation'));
        echo '</div><p><a href="'.esc_url(get_admin_url($store,'post.php?post='.($p->is_type('variation')?$p->get_parent_id():$id).'&action=edit')).'">在所属店原生产品编辑器管理属性、变体结构及促销日程 ↗</a></p></aside></div>';
        // Placed last to detect PHP max_input_vars truncation before any product is saved.
        self::hidden('gsm_product_complete','1');
        echo '</form></section>';
    }
    private static function rich_text(string $label,string $name,string $content,int $height): void {
        $editor='gsm_'.$name;
        echo '<div class="gsm-field"><label for="'.esc_attr($editor).'"><strong>'.esc_html($label).'</strong></label>';
        wp_editor($content,$editor,['textarea_name'=>$name,'editor_height'=>$height,'media_buttons'=>true,'teeny'=>false,'tinymce'=>['wp_autoresize_on'=>false],'quicktags'=>true]);
        echo '</div>';
    }
    private static function price(string $label,string $name,string $value): void {
        Admin::input($label.' ('.get_woocommerce_currency().')',$name,$value,'text','inputmode="decimal" autocomplete="off"');
    }
    private static function images(string $name,string $ids,bool $multiple): void {
        $images=[];
        foreach (array_slice(array_unique(array_filter(array_map('absint',explode(',',$ids)))),0,$multiple?100:1) as $id) {
            if (wp_attachment_is_image($id)) { $images[]=['id'=>$id,'url'=>wp_get_attachment_image_url($id,'thumbnail')?:wp_get_attachment_url($id),'alt'=>get_the_title($id)]; }
        }
        echo '<div class="gsm-image-picker" data-multiple="'.($multiple?'1':'0').'">';
        self::hidden($name,implode(',',array_column($images,'id')));
        echo '<div class="gsm-image-previews" data-images="'.esc_attr(wp_json_encode($images)).'"></div><button class="button gsm-images-select" type="button">'.($multiple?'选择／添加图片':'选择图片').'</button> <button class="button gsm-images-clear" type="button">移除全部</button>';
        echo '<p class="description">'.($multiple?'第一张为主图，其余为相册。可逐张移除或调整顺序。':'使用当前店铺媒体库；留空时变体沿用商品主图。').'</p></div>';
    }
    private static function variations(int $store,\WC_Product $p,array $retry): void {
        $ids=$p->get_children(); $total=count($ids); $pages=max(1,(int)ceil($total/self::VARIATIONS_PER_PAGE));
        $page=min($pages,max(1,absint($_GET['variation_page']??1)));
        self::hidden('variation_page',(string)$page);
        echo '<section class="gsm-variation-editor"><h3>变体规格与售价 <span class="description">共 '.$total.' 个</span></h3><p>按规格直接编辑，一次保存当前页。属性、增删变体和促销日程使用所属店原生产品编辑器。</p>';
        echo '<div class="gsm-variation-bulk"><label>普通售价 <input type="text" inputmode="decimal" data-gsm-bulk="regular_price"></label><label>促销售价 <input type="text" inputmode="decimal" data-gsm-bulk="sale_price"></label><button type="button" class="button gsm-apply-prices">将已填价格应用到本页</button><button type="button" class="button gsm-clear-sales">清空本页促销价</button><span class="gsm-bulk-feedback" role="status"></span></div>';
        echo '<div class="gsm-variation-scroll"><table class="widefat striped gsm-variations"><thead><tr><th>规格</th><th>普通售价 ('.esc_html(get_woocommerce_currency()).')</th><th>促销售价</th><th>启用</th><th>图片／描述</th></tr></thead><tbody>';
        foreach (array_slice($ids,($page-1)*self::VARIATIONS_PER_PAGE,self::VARIATIONS_PER_PAGE) as $id) {
            $v=wc_get_product($id); if (!$v || !$v->is_type('variation')) { continue; }
            $fields=is_array($retry['variations'][$id]??null)?$retry['variations'][$id]:[];
            $value=static fn($key,$fallback)=>isset($fields[$key])&&is_scalar($fields[$key])?(string)$fields[$key]:(string)$fallback;
            $prefix='variations['.$id.']';
            echo '<tr><td><strong>'.esc_html(wc_get_formatted_variation($v,true,true,false)?:$v->get_name()).'</strong><br><span class="description">#'.$id.($v->get_sku()?' · '.$v->get_sku():'').'</span></td>';
            foreach (['regular_price'=>'普通售价','sale_price'=>'促销售价'] as $key=>$label) {
                echo '<td><input type="text" inputmode="decimal" data-price="'.$key.'" name="'.esc_attr($prefix.'['.$key.']').'" value="'.esc_attr($value($key,$v->{'get_'.$key}('edit'))).'" aria-label="'.esc_attr($label.' #'.$id).'"></td>';
            }
            echo '<td><select name="'.esc_attr($prefix.'[status]').'" aria-label="启用变体 #'.$id.'"><option value="publish" '.selected($value('status',$v->get_status()),'publish',false).'>启用</option><option value="private" '.selected($value('status',$v->get_status()),'private',false).'>禁用</option></select></td><td><details><summary>图片与描述</summary>';
            self::images($prefix.'[image_ids]',$value('image_ids',(string)$v->get_image_id('edit')),false);
            echo '<label>变体描述<textarea name="'.esc_attr($prefix.'[description]').'" rows="3">'.esc_textarea($value('description',$v->get_description('edit'))).'</textarea></label></details></td></tr>';
        }
        if (!$total) { echo '<tr><td colspan="5">暂无变体，请先在原生产品编辑器创建属性和变体。</td></tr>'; }
        echo '</tbody></table></div><p><button type="submit" class="button button-primary">保存商品与本页变体</button></p>';
        self::pages($store,$p->get_id(),$page,$total,self::VARIATIONS_PER_PAGE);
        echo '</section>';
    }
    private static function pages(int $store,int $product,int $page,int $total,int $size): void {
        $pages=max(1,(int)ceil($total/$size));
        echo '<p class="gsm-pagination">共 '.$total.' 项，第 '.$page.' / '.$pages.' 页　';
        foreach ([-1=>'上一页',1=>'下一页'] as $offset=>$label) {
            $next=$page+$offset; if ($next<1||$next>$pages) { continue; }
            $args=['store'=>$store,'paged'=>max(1,absint($_GET['paged']??1))];
            if ($product) { $args['product']=$product; $args['variation_page']=$next; } else { $args['paged']=$next; }
            echo '<a class="button" href="'.esc_url(Admin::url('products',$args)).'">'.$label.'</a> ';
        }
        echo '</p>';
    }
}
