<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
/** Local-store product editing; cross-store publication has been retired. */
final class Catalog {
    /** Change the native publication status in exactly one store, rejecting stale row actions. */
    public static function set_status(int $store, int $id, string $status, string $expected): void {
        Stores::authorize();
        if (!in_array($status, ['draft', 'publish'], true)) { throw new \RuntimeException('只能上架或禁用当前店铺商品。'); }
        Stores::within($store, static function () use ($id, $status, $expected): void {
            $p=wc_get_product($id);
            if (!$p || !$p->is_type(['simple','variable']) || !in_array($p->get_status(), ['publish','draft','private','pending','future'], true)) { throw new \RuntimeException('当前店铺商品不存在或不支持快捷操作。'); }
            if ($p->get_status()!==$expected) { throw new \RuntimeException('商品状态已变化，请刷新列表后重试。'); }
            if ($status==='publish' && $p->get_date_created() && $p->get_date_created()->getTimestamp()>time()) { $p->set_date_created(time()); }
            $p->set_status($status); $p->save(); wc_delete_product_transients($id); Stores::purge();
        });
        Schema::audit('edition_status_changed', $store, $id, ['status'=>$status]);
    }
    /** Prepare every submitted row before saving so invalid input never partly edits a batch. */
    public static function save_edition(int $store, int $id, array $data): void {
        Stores::authorize();
        Stores::within($store, static function () use ($id, $data): void {
            $p=wc_get_product($id);
            if (!$p || !$p->is_type(['simple','variable','variation']) || in_array($p->get_status(), ['trash','auto-draft'], true)) { throw new \RuntimeException('商品不存在或类型不受支持。'); }
            self::prepare_edition($p, $data);
            $children=[];
            if (isset($data['variations'])) {
                if (!$p->is_type('variable') || !is_array($data['variations']) || count($data['variations'])>20) { throw new \RuntimeException('变体表单无效，每次最多保存 20 个变体。'); }
                foreach ($data['variations'] as $child_id=>$fields) {
                    $child=wc_get_product(absint($child_id));
                    if (!$child || !$child->is_type('variation') || $child->get_parent_id()!==$id || !is_array($fields) || !in_array($child->get_status(), ['publish','private'], true)) { throw new \RuntimeException('变体不属于当前店铺的此商品，请刷新后重试。'); }
                    $fields=array_intersect_key($fields, array_flip(['regular_price','sale_price','status','image_ids','description']));
                    try { self::prepare_edition($child, $fields); }
                    catch (\Throwable $error) { throw new \RuntimeException('变体 #'.$child->get_id().'：'.$error->getMessage()); }
                    $children[]=$child;
                }
            }
            // No writes until the parent, every child, prices and attachments have passed validation.
            $p->save();
            foreach ($children as $child) { $child->save(); }
            if ($children || $p->is_type('variation')) { \WC_Product_Variable::sync($p->is_type('variation') ? $p->get_parent_id() : $id); }
            wc_delete_product_transients($id); Stores::purge();
        });
        Schema::audit('edition_updated', $store, $id);
    }
    private static function prepare_edition(\WC_Product $p, array $data): void {
        if (isset($data['category_ids']) || isset($data['tag_ids'])) { throw new \RuntimeException('分类和标签只能在原生产品后台管理。'); }
        foreach (['name','description','short_description','regular_price','sale_price','menu_order','status','image_ids'] as $field) {
            if (isset($data[$field]) && !is_scalar($data[$field])) { throw new \RuntimeException('商品字段格式无效。'); }
        }
        if (isset($data['name']) && !$p->is_type('variation')) {
            $name=sanitize_text_field((string)$data['name']);
            if ($name==='') { throw new \RuntimeException('商品名称不能为空。'); }
            $p->set_name($name);
        }
        foreach (['description','short_description'] as $field) { if (isset($data[$field])) { $p->{'set_'.$field}(wp_kses_post($data[$field])); } }
        if (!$p->is_type('variable') && (isset($data['regular_price']) || isset($data['sale_price']))) {
            $prices=[];
            foreach (['regular_price','sale_price'] as $field) {
                $raw=trim((string)($data[$field]??$p->{'get_'.$field}('edit')));
                $value=wc_format_decimal($raw);
                if ($raw!=='' && (!preg_match('/^(?:\d+(?:[.,]\d*)?|[.,]\d+)$/D',$raw) || $value==='' || !is_numeric($value) || (float)$value<0)) { throw new \RuntimeException('售价必须是非负数字。'); }
                $prices[$field]=$value;
            }
            if ($prices['sale_price']!=='' && ($prices['regular_price']==='' || (float)$prices['sale_price']>=(float)$prices['regular_price'])) { throw new \RuntimeException('促销价必须低于普通售价。'); }
            $p->set_regular_price($prices['regular_price']); $p->set_sale_price($prices['sale_price']);
        }
        if (isset($data['menu_order'])) { $p->set_menu_order((int)$data['menu_order']); }
        if (isset($data['status'])) {
            $allowed=$p->is_type('variation')?['publish','private']:['draft','publish','private','pending','future'];
            if (!in_array($data['status'], $allowed, true)) { throw new \RuntimeException('商品状态无效。'); }
            if ($data['status']==='publish' && !$p->is_type('variation') && $p->get_date_created() && $p->get_date_created()->getTimestamp()>time()) { $p->set_date_created(time()); }
            $p->set_status($data['status']);
        }
        if (isset($data['image_ids'])) {
            $ids=array_values(array_unique(array_filter(array_map('absint', explode(',', (string)$data['image_ids'])))));
            if (count($ids)>100 || ($p->is_type('variation') && count($ids)>1)) { throw new \RuntimeException('图片数量超出限制。'); }
            foreach ($ids as $image) { if (!wp_attachment_is_image($image) || get_post_field('post_status',$image)!=='inherit') { throw new \RuntimeException('请选择当前店铺媒体库内有效的图片。'); } }
            $p->set_image_id($ids[0]??0);
            if (!$p->is_type('variation')) { $p->set_gallery_image_ids(array_slice($ids, 1)); }
        }
    }
}
