<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Explicit local migration of a trusted private native-API export. No deletion of legacy tables/files. */
final class SingleMigration {
    private static array $posts=[];
    private static array $terms=[];
    private static array $orders=[];
    public static function run(array $bundle): array {
        if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local') { throw new \RuntimeException('此迁移仅用于已授权本地单站。'); }
        Stores::authorize();
        if (get_option('gsm_single_brand_ready')) { return (array)get_option('gsm_single_migration_report'); }
        if (($bundle['version']??null)!==1||count($bundle['brands']??[])<1||($bundle['brands'][1]['domain']??'')!=='www.main.local') { throw new \RuntimeException('迁移来源无效。'); }
        foreach ($bundle['brands'] as $b) { if (!str_ends_with($b['domain'],'.local')||!str_starts_with($b['options']['home'],'http://')) { throw new \RuntimeException('来源必须为本地品牌。'); } }
        global $wpdb;
        $lock='gsm_single_migration'; if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1) { throw new \RuntimeException('迁移正在运行。'); }
        $no_mail=static fn()=>false; add_filter('pre_wp_mail',$no_mail,PHP_INT_MAX);
        $wpdb->query('START TRANSACTION');
        try {
            $report=Identities::internal(static fn()=>Brands::unscoped(static fn()=>self::import($bundle)));
            update_option('gsm_single_migration_report',$report,false); update_option('gsm_single_brand_ready',true,false);
            $wpdb->query('COMMIT'); wp_cache_flush();
            return $report;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); wp_cache_flush(); throw $e; }
        finally { remove_filter('pre_wp_mail',$no_mail,PHP_INT_MAX); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function import(array $bundle): array {
        $registry=[]; $report=['version'=>GSM_VERSION,'brands'=>count($bundle['brands']),'products'=>0,'variations'=>0,'bases'=>0,'orders'=>0,'reviews'=>0,'comments'=>0,'attachments'=>0,'pages'=>0,'collections'=>0,'downloads'=>0,'tokens'=>0];
        foreach ($bundle['brands'] as $id=>$b) { $registry[$id]=['name'=>$b['name'],'domain'=>$b['domain'],'url'=>untrailingslashit($b['options']['home']),'uuid'=>$b['options']['gsm_store_settings']['uuid']??wp_generate_uuid4()]; }
        update_option('gsm_brands',$registry,false); update_option('gsm_brand_sequence',max(array_keys($registry)),false);
        foreach ($bundle['network'] as $key=>$value) { update_option($key,$value,false); }
        foreach ($bundle['brands'] as $b) { self::taxonomies($b); }
        foreach ($bundle['brands'] as $brand=>$b) {
            foreach ($b['posts'] as $old=>$row) {
                $data=$row['data']; $data['post_parent']=0; unset($data['ID'],$data['filter'],$data['ancestors']);
                if ((int)$brand===1) { $id=(int)$old; }
                else {
                    $data['post_name']='b'.$brand.'-'.$data['post_name']; $id=wp_insert_post(wp_slash($data),true); if (is_wp_error($id)) { throw new \RuntimeException('原生内容导入失败：'.$brand.':'.$old); }
                }
                self::$posts[$brand][$old]=(int)$id;
                if ($data['post_type']==='attachment') { ++$report['attachments']; }
                elseif ($data['post_type']==='page') { ++$report['pages']; }
            }
        }
        foreach ($bundle['brands'] as $brand=>$b) {
            foreach ($b['posts'] as $old=>$row) {
                $id=self::$posts[$brand][$old]; $type=$row['data']['post_type'];
                foreach ($row['meta'] as $key=>$values) {
                    if (in_array($key,['_edit_lock','_edit_last'],true)) { continue; }
                    $value=maybe_unserialize($values[0]);
                    if ($key==='_wp_attached_file'&&(int)$brand!==1) { $value='sites/'.$brand.'/'.ltrim((string)$value,'/'); }
                    if ($key==='_wp_attachment_metadata'&&(int)$brand!==1&&is_array($value)&&isset($value['file'])) { $value['file']='sites/'.$brand.'/'.ltrim($value['file'],'/'); }
                    if (in_array($key,['_thumbnail_id','_menu_item_menu_item_parent'],true)&&is_numeric($value)) { $value=self::$posts[$brand][(int)$value]??$value; }
                    if ($key==='_menu_item_object_id'&&is_numeric($value)) { $kind=$row['meta']['_menu_item_type'][0]??''; $tax=$row['meta']['_menu_item_object'][0]??''; $value=$kind==='taxonomy'?(self::$terms[$brand][$tax][(int)$value]??$value):(self::$posts[$brand][(int)$value]??$value); }
                    update_post_meta($id,$key,$value);
                }
                if ($type!=='attachment') { update_post_meta($id,'_gsm_brand_id',(int)$brand); update_post_meta($id,'_gsm_public_slug',$row['data']['post_name']); }
                $parent=(int)$row['data']['post_parent']; $newparent=$parent?(self::$posts[$brand][$parent]??0):0;
                $content=self::content((string)$row['data']['post_content'],(int)$brand);
                if ($newparent||$content!==$row['data']['post_content']) { wp_update_post(wp_slash(['ID'=>$id,'post_parent'=>$newparent,'post_content'=>$content])); }
                foreach ($row['terms'] as $tax=>$ids) { wp_set_object_terms($id,array_values(array_filter(array_map(static fn($term)=>self::$terms[$brand][$tax][$term]??0,(array)$ids))),$tax); }
                if (isset($b['products'][$old])) { self::product((int)$brand,(int)$old,$b['products'][$old]); $type==='product_variation'?++$report['variations']:++$report['products']; }
            }
            self::options((int)$brand,$b['options']); $report['collections']+=count($b['options']['gsm_collections']??[]);
            foreach ($b['terms'] as $tax=>$rows) { foreach ($rows as $old=>$row) {
                $id=self::$terms[$brand][$tax][$old]??0; if (!$id) { continue; }
                $parent=(int)$row['data']['parent']; if ($parent) { wp_update_term($id,$tax,['parent'=>self::$terms[$brand][$tax][$parent]??0]); }
                if (isset($row['meta']['thumbnail_id'])) { update_term_meta($id,'thumbnail_id',self::$posts[$brand][(int)$row['meta']['thumbnail_id'][0]]??0); }
            } }
        }
        foreach ($bundle['staff'] as $id=>$row) {
            $identity=Identities::get((int)$id); if (!$identity) { throw new \RuntimeException('用户身份索引缺失：'.$id); }
            if ($identity['kind']==='staff') { update_user_meta($id,'_gsm_brand_roles',$row['grants']); (new \WP_User((int)$id))->set_role($row['root']?'administrator':($row['grants']?'shop_manager':'subscriber')); }
            else { (new \WP_User((int)$id))->set_role('customer'); update_user_meta($id,'_gsm_brand_id',(int)$identity['store_id']); }
            \WP_Session_Tokens::get_instance((int)$id)->destroy_all();
        }
        foreach ($bundle['brands'] as $brand=>$b) {
            $comments=[];
            foreach ($b['comments'] as $old=>$row) {
                $data=$row['data']; unset($data['comment_ID']); $data['comment_post_ID']=self::$posts[$brand][$data['comment_post_ID']]??0; $data['comment_parent']=0;
                if (!$data['comment_post_ID']) { throw new \RuntimeException('评论缺少原生内容映射。'); }
                $id=(int)$brand===1?(int)$old:(int)wp_insert_comment(wp_slash($data)); if (!$id) { throw new \RuntimeException('评论导入失败。'); }
                $comments[$old]=$id; foreach ($row['meta'] as $key=>$values) { update_comment_meta($id,$key,maybe_unserialize($values[0])); }
                update_comment_meta($id,'_gsm_brand_id',(int)$brand); ++$report['comments']; if (get_post_type($data['comment_post_ID'])==='product') { ++$report['reviews']; }
            }
            foreach ($b['comments'] as $old=>$row) { $parent=(int)$row['data']['comment_parent']; if ($parent) { wp_update_comment(['comment_ID'=>$comments[$old],'comment_parent'=>$comments[$parent]??0]); } }
            $orders=$b['orders']; uasort($orders,static fn($a,$c)=>($a['type']==='shop_order_refund')<=>($c['type']==='shop_order_refund'));
            foreach ($orders as $old=>$row) { self::order((int)$brand,(int)$old,$row); ++$report['orders']; }
            foreach ($b['downloads'] as $row) {
                if ((int)$brand===1) { continue; }
                $d=new \WC_Customer_Download(); unset($row['id']); $row['product_id']=self::$posts[$brand][$row['product_id']]??0; $row['order_id']=self::$orders[$brand][$row['order_id']]??0; $d->set_props($row); $d->save(); ++$report['downloads'];
            }
            foreach ($b['tokens'] as $row) {
                if ((int)$brand===1) { continue; }
                $class=apply_filters('woocommerce_payment_token_class','WC_Payment_Token_'.$row['type'],$row['type']); if (!is_string($class)||!is_subclass_of($class,\WC_Payment_Token::class)) { throw new \RuntimeException('存在未支持的付款令牌类型。'); }
                $t=new $class(); $meta=$row['meta_data']??[]; unset($row['id'],$row['meta_data']); $errors=$t->set_props($row); if (is_wp_error($errors)) { throw new \RuntimeException('付款令牌字段无法迁移。'); } foreach ($meta as $item) { $t->update_meta_data($item['key'],$item['value']); } if (!$t->save()) { throw new \RuntimeException('付款令牌无法保存。'); } ++$report['tokens'];
            }
        }
        $groups=[]; $sources=[];
        foreach ($bundle['catalog'] as $row) { $sources[$row['target_store'].':'.$row['target_product']]=$row['master_store'].':'.$row['master_product']; }
        foreach ($bundle['brands'] as $brand=>$b) { foreach ($b['products'] as $old=>$row) {
            if ($row['type']==='variation') { continue; }
            $key=$sources[$brand.':'.$old]??$brand.':'.$old;
            // A historical brand may have added its own specs. Keep that catalog independent.
            $candidate=wc_get_product(self::$posts[$brand][$old]); $shape=[];
            foreach ($candidate->is_type('variable')?$candidate->get_children():[] as $child) { $attrs=wc_get_product($child)->get_attributes(); ksort($attrs); $shape[]=wp_json_encode($attrs); }
            sort($shape); $groups[$key.':'.md5(wp_json_encode($shape))][]=['brand'=>(int)$brand,'id'=>self::$posts[$brand][$old]];
        } }
        foreach ($groups as $key=>$editions) {
            [$source_brand,$source_old]=array_map('intval',explode(':',$key)); $mapped_source=self::$posts[$source_brand][$source_old]??0; $source_id=in_array($mapped_source,array_column($editions,'id'),true)?$mapped_source:$editions[0]['id']; $source=wc_get_product($source_id);
            if (!$source) { throw new \RuntimeException('公共商品源缺失。'); }
            $base=ProductVersions::create($source,0); ++$report['bases']; $variant_map=[];
            foreach ($base->is_type('variable')?$base->get_children():[] as $id) { $v=wc_get_product($id); $attrs=$v->get_attributes(); ksort($attrs); $variant_map[wp_json_encode($attrs)][]=$id; $v->delete_meta_data('_gsm_base_variation'); $v->save_meta_data(); }
            foreach ($editions as $edition) {
                $p=wc_get_product($edition['id']); $p->update_meta_data('_gsm_base_product',$base->get_id()); $available=$variant_map; $over=self::differences($p,$base); $p->update_meta_data('_gsm_overrides',$over); $p->save_meta_data();
                foreach ($p->is_type('variable')?$p->get_children():[] as $id) { $v=wc_get_product($id); $attrs=$v->get_attributes(); ksort($attrs); $attr_key=wp_json_encode($attrs); $basevar=empty($available[$attr_key])?0:array_shift($available[$attr_key]); if (!$basevar) { throw new \RuntimeException('品牌变体无法匹配公共规格：'.wp_json_encode(['variation'=>$id,'attributes'=>$attrs,'base'=>$base->get_id(),'mapped'=>array_keys($variant_map)])); } $v->update_meta_data('_gsm_base_variation',$basevar); $v->update_meta_data('_gsm_overrides',self::differences($v,wc_get_product($basevar))); $v->save_meta_data(); }
            }
        }
        foreach ($bundle['brands'] as $brand=>$b) { foreach ($b['products'] as $old=>$row) { $p=wc_get_product(self::$posts[$brand][$old]); if ($p->is_type('variable')) { \WC_Product_Variable::sync($p->get_id()); } \WC_Comments::clear_transients($p->get_id()); } }
        update_option('gsm_single_migration_map',['posts'=>self::$posts,'terms'=>self::$terms,'orders'=>self::$orders],false);
        update_option('gsm_schema_version',GSM_VERSION,false); update_option('gsm_identity_ready',true,false);
        return $report;
    }
    private static function taxonomies(array $b): void {
        $brand=(int)$b['id'];
        foreach ($b['attributes'] as $attr) {
            $name=$attr->attribute_name; if (!wc_attribute_taxonomy_id_by_name($name)) { wc_create_attribute(['name'=>$attr->attribute_label,'slug'=>$name,'type'=>$attr->attribute_type,'order_by'=>$attr->attribute_orderby,'has_archives'=>(bool)$attr->attribute_public]); }
            if (!taxonomy_exists('pa_'.$name)) { register_taxonomy('pa_'.$name,['product'],['hierarchical'=>false,'public'=>false]); }
        }
        foreach ($b['terms'] as $tax=>$terms) {
            if (!taxonomy_exists($tax)) { register_taxonomy($tax,['product'],['public'=>false]); }
            foreach ($terms as $old=>$row) {
                $data=$row['data']; $collection=!empty($row['meta']['_gsm_collection']); $private=$collection||$tax==='nav_menu';
                $slug=$private&&$brand!==1?'b'.$brand.'-'.$data['slug']:$data['slug'];
                $found=term_exists($slug,$tax); $id=is_array($found)?(int)$found['term_id']:(int)$found;
                if (!$id) { $created=wp_insert_term($data['name'],$tax,['slug'=>$slug,'description'=>$data['description']]); if (is_wp_error($created)) { throw new \RuntimeException('词条迁移失败：'.$tax); } $id=(int)$created['term_id']; }
                self::$terms[$brand][$tax][$old]=$id;
                if ($private||$brand===1) { foreach ($row['meta'] as $key=>$values) { update_term_meta($id,$key,maybe_unserialize($values[0])); } }
                if ($private) { update_term_meta($id,'_gsm_brand_id',$brand); }
            }
        }
    }
    private static function product(int $brand,int $old,array $row): void {
        $id=self::$posts[$brand][$old]; $class=match($row['type']) { 'variation'=>\WC_Product_Variation::class,'variable'=>\WC_Product_Variable::class,'grouped'=>\WC_Product_Grouped::class,'external'=>\WC_Product_External::class,default=>\WC_Product_Simple::class };
        $p=$brand===1?wc_get_product($id):new $class($id); $data=$row['data']; $business=$data['sku'];
        foreach (['id','meta_data','variations','children','rating_counts','average_rating','review_count','total_sales'] as $key) { unset($data[$key]); }
        $data['sku']='GSM-B'.$brand.'-'.$old; $data['parent_id']=self::$posts[$brand][$data['parent_id']]??0;
        foreach (['image_id'] as $key) { $data[$key]=self::$posts[$brand][$data[$key]]??0; }
        foreach (['gallery_image_ids','upsell_ids','cross_sell_ids'] as $key) { $data[$key]=array_values(array_filter(array_map(static fn($v)=>self::$posts[$brand][$v]??0,(array)($data[$key]??[])))); }
        foreach (['category_ids'=>'product_cat','tag_ids'=>'product_tag'] as $key=>$tax) { $data[$key]=array_values(array_filter(array_map(static fn($v)=>self::$terms[$brand][$tax][$v]??0,(array)($data[$key]??[])))); }
        if ($row['type']!=='variation') {
            $attrs=[]; foreach ($data['attributes'] as $attr) { $a=new \WC_Product_Attribute(); $a->set_name($attr['name']); $a->set_id(!empty($attr['id'])?wc_attribute_taxonomy_id_by_name($attr['name']):0); $options=$attr['options']; if ($a->get_id()) { $options=array_map(static fn($v)=>self::$terms[$brand][$attr['name']][$v]??$v,$options); } $a->set_options($options); $a->set_position($attr['position']); $a->set_visible($attr['visible']); $a->set_variation($attr['variation']); $attrs[]=$a; } $data['attributes']=$attrs;
        }
        $downloads=[]; foreach ($data['downloads']??[] as $key=>$d) { $download=new \WC_Product_Download(); $download->set_id($d['id']??$key); $download->set_name($d['name']); $download->set_file($d['file']); $downloads[]=$download; } $data['downloads']=$downloads;
        $error=$p->set_props($data); if (is_wp_error($error)) { throw new \RuntimeException('商品原生字段迁移失败：'.$brand.':'.$old.' '.$error->get_error_code()); }
        $p->update_meta_data('_gsm_brand_id',$brand); $p->update_meta_data('_gsm_kind','edition'); $p->update_meta_data('_gsm_business_sku',$business); $p->update_meta_data('_gsm_legacy_product_id',$old); $p->update_meta_data('_gsm_public_slug',get_post_meta($id,'_gsm_public_slug',true)); $p->save();
    }
    private static function options(int $brand,array $options): void {
        $rules=[];
        foreach ((array)($options['gsm_collections']??[]) as $old=>$rule) {
            $new=self::$terms[$brand]['product_cat'][$old]??0; if (!$new) { throw new \RuntimeException('集合分类缺少映射。'); }
            foreach (['categories'=>'product_cat','tags'=>'product_tag'] as $key=>$tax) { $rule[$key]=array_values(array_filter(array_map(static fn($v)=>self::$terms[$brand][$tax][$v]??0,(array)($rule[$key]??[])))); }
            foreach (['include','exclude','priority'] as $key) { $rule[$key]=array_values(array_filter(array_map(static fn($v)=>self::$posts[$brand][$v]??0,(array)($rule[$key]??[])))); }
            foreach (['image','image_id'] as $key) { if (isset($rule[$key])) { $rule[$key]=self::$posts[$brand][$rule[$key]]??0; } }
            $rules[$new]=$rule;
        }
        $options['gsm_collections']=$rules;
        foreach ($options as $key=>$value) {
            if (preg_match('/^woocommerce_.*_page_id$/',$key)||in_array($key,['page_on_front','page_for_posts','wp_page_for_privacy_policy','site_icon','gsm_logo_id'],true)) { $value=self::$posts[$brand][(int)$value]??0; }
            if (str_starts_with($key,'theme_mods_')&&is_array($value)) { if (isset($value['custom_logo'])) { $value['custom_logo']=self::$posts[$brand][(int)$value['custom_logo']]??0; } foreach ($value['nav_menu_locations']??[] as $location=>$term) { $value['nav_menu_locations'][$location]=self::$terms[$brand]['nav_menu'][$term]??0; } }
            Brands::set_option($brand,$key,$value);
        }
    }
    private static function content(string $text,int $brand): string {
        $text=preg_replace_callback('/wp-image-(\d+)/',static fn($m)=>'wp-image-'.(self::$posts[$brand][(int)$m[1]]??$m[1]),$text);
        $blocks=parse_blocks($text); $walk=static function (array $blocks) use (&$walk,$brand): array { foreach ($blocks as &$block) { if (in_array($block['blockName'],['core/image','core/gallery','core/cover','core/media-text'],true)) { foreach (['id','mediaId'] as $key) { if (isset($block['attrs'][$key])) { $block['attrs'][$key]=self::$posts[$brand][(int)$block['attrs'][$key]]??$block['attrs'][$key]; } } if (isset($block['attrs']['ids'])) { $block['attrs']['ids']=array_map(static fn($id)=>self::$posts[$brand][$id]??$id,$block['attrs']['ids']); } } if ($block['blockName']==='group-shop/collection'&&isset($block['attrs']['collectionId'])) { $block['attrs']['collectionId']=self::$terms[$brand]['product_cat'][$block['attrs']['collectionId']]??0; } $block['innerBlocks']=$walk($block['innerBlocks']); } return $blocks; };
        return serialize_blocks($walk($blocks));
    }
    private static function order(int $brand,int $old,array $row): void {
        if ($brand===1) { $o=wc_get_order($old); if (!$o) { throw new \RuntimeException('主店订单缺失。'); } }
        else {
            $o=$row['type']==='shop_order_refund'?new \WC_Order_Refund():new \WC_Order(); $data=$row['data'];
            foreach (['id','meta_data','line_items','tax_lines','shipping_lines','fee_lines','coupon_lines','refunds','number','date_paid','date_completed'] as $key) { unset($data[$key]); }
            if (!empty($data['parent_id'])) { $data['parent_id']=self::$orders[$brand][$data['parent_id']]??0; }
            $error=$o->set_props($data); if (is_wp_error($error)) { throw new \RuntimeException('订单原生字段迁移失败。'); }
            $o->update_meta_data('_gsm_store_id',$brand); $o->save();
            foreach ($row['items'] as $item) {
                $class=match($item['type']) { 'shipping'=>\WC_Order_Item_Shipping::class,'fee'=>\WC_Order_Item_Fee::class,'tax'=>\WC_Order_Item_Tax::class,'coupon'=>\WC_Order_Item_Coupon::class,default=>\WC_Order_Item_Product::class };
                $i=new $class(); $props=$item['data']; $meta=$props['meta_data']; unset($props['id'],$props['order_id'],$props['meta_data']);
                foreach (['product_id','variation_id'] as $key) { if (!empty($props[$key])) { $props[$key]=self::$posts[$brand][$props[$key]]??0; } }
                $i->set_props($props); foreach ($meta as $m) { $i->add_meta_data($m['key'],$m['value']); } $o->add_item($i);
            }
            foreach ($row['data']['meta_data'] as $m) { $o->update_meta_data($m['key'],$m['value']); }
            foreach (['date_paid','date_completed'] as $prop) { if (!empty($row['data'][$prop])) { $o->{'set_'.$prop}($row['data'][$prop]); } }
            $o->save(); foreach (wc_get_order_notes(['order_id'=>$o->get_id(),'limit'=>-1]) as $note) { wc_delete_order_note($note->id); }
            foreach ($row['notes'] as $note) { $o->add_order_note($note['content'],!empty($note['customer_note'])); }
        }
        $o->update_meta_data('_gsm_store_id',$brand); $o->update_meta_data('_gsm_legacy_order_id',$old); $o->save_meta_data(); self::$orders[$brand][$old]=$o->get_id();
    }
    private static function differences(\WC_Product $p,\WC_Product $base): array {
        $over=[]; foreach (ProductVersions::FIELDS as $field=>$_) {
            $props=match($field) { 'images'=>$p->is_type('variation')?['image_id']:['image_id','gallery_image_ids'],'price'=>['regular_price','sale_price','date_on_sale_from','date_on_sale_to'],default=>[$field] };
            foreach ($props as $prop) { if ($p->{'get_'.$prop}('edit')!==$base->{'get_'.$prop}('edit')) { $over[]=$field; break; } }
        } return $over;
    }
}
