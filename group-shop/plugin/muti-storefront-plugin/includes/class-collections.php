<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Store-local rules materialized as native WooCommerce category relationships. */
final class Collections {
    public const MAX_PRODUCTS = 2000;
    private static int $busy = 0;
    private static array $compiled = [];
    private static bool $terms_all=false;
    private static array $deleting = [];
    private static array $deleting_terms = [];

    public static function boot(): void {
        add_action('set_object_terms', static function ($id, $terms, $tt, $taxonomy): void {
            if (in_array($taxonomy, ['product_cat','product_tag'], true) && get_post_type($id) === 'product') { self::changed((int)$id); }
        }, 100, 4);
        add_action('woocommerce_after_product_object_save', static function ($product): void {
            if ($product instanceof \WC_Product && !$product->is_type('variation')) { self::changed($product->get_id()); }
        }, 100);
        add_action('before_delete_post', static function ($id): void { self::$deleting[(int)$id]=true; });
        add_action('deleted_post', static function ($id): void { unset(self::$deleting[(int)$id]); });
        add_action('pre_delete_term', static function ($term,$taxonomy): void { self::$deleting_terms[$taxonomy.':'.$term]=true; },10,2);
        add_action('delete_term', static function ($term,$tt,$taxonomy): void { unset(self::$deleting_terms[$taxonomy.':'.$term]); },10,3);
        add_action('saved_term',static function ($term,$tt,$taxonomy): void {
            if (in_array($taxonomy,['product_cat','product_tag'],true)) { self::terms_changed(); }
        },100,3);
        add_action('delete_term',static function ($term,$tt,$taxonomy): void {
            if (!in_array($taxonomy,['product_cat','product_tag'],true)) { return; }
            $all=self::collections();
            if ($taxonomy==='product_cat'&&isset($all[$term])) {
                unset($all[$term]); update_option('gsm_collections',$all,false);
                $review=count(array_filter($all,static fn($rule)=>!empty($rule['needs_review'])));
                if ($review) { update_option('gsm_collections_review',$review,false); } else { delete_option('gsm_collections_review'); }
            }
            self::terms_changed();
        },100,3);
        // wp_remove_object_terms does not fire set_object_terms. Its relationship hook
        // runs before cache invalidation, so clear that cache before reading rules.
        add_action('deleted_term_relationships', static function ($id,$tt,$taxonomy): void {
            if (self::$busy||self::$deleting_terms||isset(self::$deleting[(int)$id])||!in_array($taxonomy,['product_cat','product_tag'],true)||get_post_type($id)!=='product') { return; }
            clean_object_term_cache((int)$id,'product'); self::changed((int)$id);
        },100,3);
        add_action('gsm_rebuild_collections',static function ($brand=1): void { Stores::within((int)$brand,static fn()=>self::rebuild_job()); });
        add_action('delete_term',static function ($term,$tt,$tax): void {
            if (!Brands::active()||!in_array($tax,['product_cat','product_tag'],true)) { return; }
            foreach (Stores::all() as $brand=>$_) { Stores::within((int)$brand,static function () use ($term,$tax): void { $rules=self::collections(); $changed=false; foreach ($rules as &$rule) { if (in_array((int)$term,(array)($rule[$tax==='product_cat'?'categories':'tags']??[]),true)) { $rule['enabled']=false; $rule['needs_review']=true; $changed=true; } } unset($rule); if ($changed) { update_option('gsm_collections',$rules,false); } }); }
        },5,3);
        add_action('pre_get_posts', [self::class, 'archive_order'], 40);
        add_shortcode('group_shop_collections', [self::class, 'navigation']);
    }
    private static function locked(callable $callback, int $timeout = 10): mixed {
        global $wpdb;
        $key = 'gsm_collections_' . Brands::id();
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $key, $timeout)) !== 1) { throw new \RuntimeException('集合正在更新，请稍后重试。'); }
        self::$busy++;
        try { self::$compiled = []; return $callback(); }
        finally { self::$busy--; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    }
    public static function terms(string $taxonomy = 'product_cat'): array {
        if (!in_array($taxonomy, ['product_cat','product_tag'], true)) { throw new \InvalidArgumentException('Unknown taxonomy'); }
        $terms = get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'orderby'=>'name']);
        if (is_wp_error($terms)) { throw new \RuntimeException($terms->get_error_message()); }
        $terms = array_values(array_filter($terms, static fn($term) => !get_term_meta($term->term_id, '_gsm_collection', true)));
        if ($taxonomy==='product_cat') {
            $by_parent=[]; $known=array_fill_keys(array_map(static fn($t)=>(int)$t->term_id,$terms),true);
            foreach ($terms as $term) { $by_parent[isset($known[$term->parent])?(int)$term->parent:0][]=$term; }
            $ordered=[]; $visited=[];
            $walk=static function (int $parent) use (&$walk,&$ordered,&$visited,$by_parent): void {
                $children=$by_parent[$parent]??[];
                usort($children,static fn($a,$b)=>((int)get_term_meta($a->term_id,'order',true)<=>(int)get_term_meta($b->term_id,'order',true))?:strnatcasecmp($a->name,$b->name));
                foreach ($children as $term) { if (isset($visited[$term->term_id])) { continue; } $visited[$term->term_id]=true; $ordered[]=$term; $walk((int)$term->term_id); }
            };
            $walk(0); foreach ($terms as $term) { if (!isset($visited[$term->term_id])) { $ordered[]=$term; } }
            $terms=$ordered;
        }
        return $terms;
    }
    public static function values(int $id): array {
        $term=get_term($id,'product_cat');
        if (!$term||is_wp_error($term)) { throw new \RuntimeException('分类不存在。'); }
        return ['name'=>$term->name,'slug'=>$term->slug,'description'=>$term->description,'parent'=>(int)$term->parent,'image'=>(int)get_term_meta($id,'thumbnail_id',true),'order'=>(int)get_term_meta($id,'order',true)];
    }
    private static function validate_values(array $value, int $id = 0): void {
        if ($value['name']==='') { throw new \RuntimeException('名称不能为空。'); }
        if ($value['parent']) {
            $parent=$value['parent']; $rules=self::collections();
            if (!term_exists($parent,'product_cat') || !get_term_meta($parent,'_gsm_collection',true) || (int)get_term_meta($parent,'_gsm_brand_id',true)!==Brands::id() || !isset($rules[$parent]) || !empty($rules[$parent]['deleted_at']) || $parent===$id || ($id && term_is_ancestor_of($id,$parent,'product_cat'))) { throw new \RuntimeException('父级必须是同店铺未删除的商品集合，不能选择自身或子级。'); }
        }
        if ($value['image'] && (!wp_attachment_is_image($value['image']) || get_post_field('post_status',$value['image'])!=='inherit')) { throw new \RuntimeException('集合图片必须来自当前店铺媒体库。'); }
    }
    public static function ids(mixed $values): array {
        if (!is_array($values)) { $values=explode(',',(string)$values); }
        return array_values(array_unique(array_filter(array_map('absint',$values))));
    }
    public static function collections(): array { return (array)get_option('gsm_collections',[]); }
    /** A filtered tree keeps children visible when their parent is hidden or in trash. */
    public static function tree(?array $rules=null): array {
        $terms=[]; $children=[];
        foreach ($rules??self::collections() as $id=>$rule) {
            $term=get_term((int)$id,'product_cat');
            if ($term&&!is_wp_error($term)&&get_term_meta($id,'_gsm_collection',true)&&(int)get_term_meta($id,'_gsm_brand_id',true)===Brands::id()) { $terms[(int)$id]=$term; }
        }
        uasort($terms,static fn($a,$b)=>((int)get_term_meta($a->term_id,'order',true)<=>(int)get_term_meta($b->term_id,'order',true))?:strnatcasecmp($a->name,$b->name));
        foreach ($terms as $id=>$term) { $children[isset($terms[$term->parent])?(int)$term->parent:0][]=$id; }
        $rows=[]; $seen=[];
        $walk=static function (int $parent,int $depth) use (&$walk,&$rows,&$seen,$children,$terms): void {
            foreach ($children[$parent]??[] as $id) { if (isset($seen[$id])) { continue; } $seen[$id]=true; $rows[]=['id'=>$id,'term'=>$terms[$id],'depth'=>$depth]; $walk($id,$depth+1); }
        };
        $walk(0,0);
        foreach ($terms as $id=>$term) { if (!isset($seen[$id])) { $rows[]=['id'=>$id,'term'=>$term,'depth'=>0]; } }
        return $rows;
    }
    /** A caller performing a taxonomy batch must rebuild once after this callback. */
    public static function defer(callable $callback): mixed {
        self::$busy++;
        try { return $callback(); } finally { self::$busy--; self::$compiled=[]; }
    }
    public static function save_collection(int $store, int $id, array $data): int {
        Stores::authorize();
        return SharedTaxonomies::local_collection(static fn()=>Stores::within($store,static function () use ($store,$id,$data): int {
            return self::locked(static function () use ($store,$id,$data): int {
                $all=self::collections();
                if ($id && (!isset($all[$id]) || !empty($all[$id]['deleted_at']) || !get_term_meta($id,'_gsm_collection',true) || (int)get_term_meta($id,'_gsm_brand_id',true)!==$store)) { throw new \RuntimeException('集合不存在、已删除或不属于当前店铺。'); }
                if (!$id && count(array_filter($all,static fn($rule)=>empty($rule['deleted_at'])))>=50) { throw new \RuntimeException('本版每店最多 50 个集合。'); }
                self::product_ids(); // Fail before changing a definition if the synchronous limit is exceeded.
                $spec=['taxonomy_scope'=>'local','mode'=>sanitize_key($data['mode']??'rule'),'categories'=>self::ids($data['categories']??[]),'tags'=>self::ids($data['tags']??[]),'tag_match'=>($data['tag_match']??'any')==='all'?'all':'any','include'=>self::ids($data['include']??[]),'exclude'=>self::ids($data['exclude']??[]),'priority'=>self::ids($data['priority']??[]),'sort'=>sanitize_key($data['sort']??'menu_order'),'enabled'=>!empty($data['enabled']),'navigation'=>!empty($data['navigation']),'shop_filter'=>!empty($data['shop_filter'])];
                if (!in_array($spec['mode'],['rule','manual'],true)||!in_array($spec['sort'],['menu_order','title','date','manual'],true)) { throw new \RuntimeException('集合规则无效。'); }
                if ($spec['mode']==='rule'&&!$spec['categories']&&!$spec['tags']) { throw new \RuntimeException('规则集合至少选择一个本店分类或标签；手工选品请选择手动模式。'); }
                foreach (['categories'=>'product_cat','tags'=>'product_tag'] as $field=>$taxonomy) {
                    foreach ($spec[$field] as $term) {
                        if (!term_exists($term,$taxonomy)||get_term_meta($term,'_gsm_collection',true)) { throw new \RuntimeException('请选择当前店铺的原生分类或标签，不能使用商品集合作为规则条件。'); }
                    }
                }
                foreach (['include','exclude','priority'] as $field) {
                    if (count($spec[$field])>200) { throw new \RuntimeException('每个选品列表最多 200 件商品。'); }
                    foreach ($spec[$field] as $product) { if (get_post_type($product)!=='product'||get_post_status($product)==='trash'||BrandScope::product_brand($product)!==$store||get_post_meta($product,'_gsm_kind',true)!=='edition') { throw new \RuntimeException('选品必须属于当前店铺，不能使用公共商品、其他店铺商品、变体或已删除商品。'); } }
                }
                $previous=$id?self::values($id):[];
                $value=['name'=>sanitize_text_field($data['name']??''),'slug'=>$previous['slug']??('gsm-collection-'.wp_generate_uuid4()),'description'=>wp_kses_post($data['description']??''),'parent'=>absint($data['parent']??$previous['parent']??0),'image'=>absint($data['image']??0),'order'=>(int)($data['order']??0)];
                self::validate_values($value,$id);
                $args=array_intersect_key($value,array_flip(['slug','description','parent']));
                $result=$id?wp_update_term($id,'product_cat',array_merge($args,['name'=>$value['name']])):wp_insert_term($value['name'],'product_cat',$args);
                if (is_wp_error($result)) { throw new \RuntimeException($result->get_error_message()); }
                $id=(int)$result['term_id']; update_term_meta($id,'_gsm_collection',1);
                update_term_meta($id,'_gsm_brand_id',$store);
                update_term_meta($id,'thumbnail_id',$value['image']); update_term_meta($id,'order',$value['order']);
                $all[$id]=$spec; update_option('gsm_collections',$all,false);
                $review=count(array_filter($all,static fn($rule)=>!empty($rule['needs_review'])));
                if ($review) { update_option('gsm_collections_review',$review,false); } else { delete_option('gsm_collections_review'); }
                self::rebuild_local(); Stores::purge(); Schema::audit('collection_saved',$store,$id,['mode'=>$spec['mode'],'status'=>$spec['enabled']?'enabled':'disabled']); return $id;
            });
        }));
    }
    /** Delete only the collection's presentation/membership; products and shared vocabulary survive. */
    public static function trash(int $store,int $id,bool $restore=false): void {
        Stores::authorize();
        Stores::within($store,static function () use ($store,$id,$restore): void {
            self::locked(static function () use ($store,$id,$restore): void {
                $all=self::collections();
                if (!$id || !isset($all[$id]) || !get_term_meta($id,'_gsm_collection',true) || (int)get_term_meta($id,'_gsm_brand_id',true)!==$store) { throw new \RuntimeException('集合不存在或不属于当前店铺。'); }
                self::product_ids(); // Validate the rebuild limit before changing trash/restore state.
                $rule=&$all[$id];
                if ($restore) {
                    if (empty($rule['deleted_at'])) { throw new \RuntimeException('集合不在回收站，请刷新列表。'); }
                    if (count(array_filter($all,static fn($value)=>empty($value['deleted_at'])))>=50) { throw new \RuntimeException('每店最多 50 个未删除集合，请先整理列表。'); }
                    $rule['enabled']=!empty($rule['trash_state']['enabled'])&&empty($rule['needs_review']);
                    $rule['navigation']=!empty($rule['trash_state']['navigation']);
                    unset($rule['deleted_at'],$rule['trash_state']);
                } else {
                    if (!empty($rule['deleted_at'])) { throw new \RuntimeException('集合已在回收站，请刷新列表。'); }
                    $rule['trash_state']=['enabled'=>!empty($rule['enabled']),'navigation'=>!empty($rule['navigation'])];
                    $rule['deleted_at']=time(); $rule['enabled']=false; $rule['navigation']=false;
                }
                unset($rule); update_option('gsm_collections',$all,false);
                self::rebuild_local(); Stores::purge();
                Schema::audit($restore?'collection_restored':'collection_trashed',$store,$id);
            });
        });
    }
    private static function product_ids(): array {
        $ids=get_posts(['post_type'=>'product','post_status'=>['publish','draft','private','pending','future'],'posts_per_page'=>self::MAX_PRODUCTS+1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
        if (count($ids)>self::MAX_PRODUCTS) { throw new \RuntimeException('本版集合重建每店最多 2000 件父商品；超过时请先升级后台任务支持。'); }
        return $ids;
    }
    private static function compiled(int $id, array $spec): array {
        $key=Brands::id().':'.$id;
        if (isset(self::$compiled[$key])) { return self::$compiled[$key]; }
        $categories=[];
        foreach ($spec['categories'] as $category) {
            if (!term_exists($category,'product_cat')||get_term_meta($category,'_gsm_collection',true)) { continue; }
            $categories[]=$category;
            $children=get_term_children($category,'product_cat');
            if (!is_wp_error($children)) { $categories=array_merge($categories,$children); }
        }
        return self::$compiled[$key]=[
            'categories'=>array_values(array_filter(self::ids($categories),static fn($term)=>!get_term_meta($term,'_gsm_collection',true))),
            'tags'=>array_values(array_filter(self::ids($spec['tags']),static fn($term)=>term_exists($term,'product_tag')&&!get_term_meta($term,'_gsm_collection',true))),
        ];
    }

    private static function reconcile(int $product): bool {
        if (get_post_meta($product,'_gsm_build_state',true)) { return false; }
        $all=self::collections(); if (!$all) { return false; }
        $categories=wp_get_object_terms($product,'product_cat',['fields'=>'ids']); $tags=wp_get_object_terms($product,'product_tag',['fields'=>'ids']);
        if (is_wp_error($categories)||is_wp_error($tags)) { throw new \RuntimeException('读取商品分类失败。'); }
        $next=array_values(array_diff($categories,array_keys($all)));
        foreach ($all as $id=>$spec) {
            if (($spec['taxonomy_scope']??'')!=='local'||empty($spec['enabled'])||!term_exists($id,'product_cat')) { continue; }
            $match=false;
            if ($spec['mode']==='rule') {
                $compiled=self::compiled((int)$id,$spec);
                $category_match=!$spec['categories']||count(array_intersect($categories,$compiled['categories']))>0;
                $found=count(array_intersect($tags,$compiled['tags']));
                $tag_match=!$spec['tags']||($spec['tag_match']==='all'?$found===count($spec['tags']):$found>0);
                $match=$category_match&&$tag_match;
            }
            if (($match||in_array($product,$spec['include'],true))&&!in_array($product,$spec['exclude'],true)) { $next[]=(int)$id; }
        }
        sort($next); $before=array_map('intval',$categories); sort($before);
        if ($before===$next) { return false; }
        $p=wc_get_product($product);
        if (!$p) { return false; }
        $p->set_category_ids($next); $p->save();
        wc_delete_product_transients($product); return true;
    }
    private static function rebuild_local(): void {
        self::$compiled=[];
        if (self::collections()) { foreach (self::product_ids() as $id) { self::reconcile((int)$id); } }
        update_option('gsm_collections_built_at',time(),false); delete_option('gsm_collections_dirty');
    }
    public static function refresh_brand(int $brand): void { Stores::within($brand,static fn()=>self::locked(static fn()=>self::rebuild_local())); }
    public static function rebuild(int $store): void {
        Stores::authorize(); Stores::within($store,static function () use ($store): void {
            self::locked(static function () use ($store): void { self::rebuild_local(); Stores::purge(); Schema::audit('collections_rebuilt',$store); });
        });
    }
    private static function changed(int $product): void {
        if (get_post_meta($product,'_gsm_build_state',true)) { return; }
        if (Brands::unrestricted()) { return; }
        if (Brands::active()) { $brand=BrandScope::product_brand($product); if (!$brand) { return; } if ($brand!==Brands::id()) { Stores::within($brand,static fn()=>self::changed($product)); return; } }
        if (self::$busy||self::$deleting_terms||isset(self::$deleting[$product])||!in_array(get_post_status($product),['publish','draft','private','pending','future'],true)||!self::collections()) { return; }
        try { self::locked(static function () use ($product): void { if (self::reconcile($product)) { Stores::purge(); } },0); }
        catch (\Throwable $error) {
            update_option('gsm_collections_dirty',1,false);
            if (!wp_next_scheduled('gsm_rebuild_collections',[Brands::id()])) { wp_schedule_single_event(time()+30,'gsm_rebuild_collections',[Brands::id()]); }
            Schema::audit('collection_rebuild_deferred',Brands::id(),$product,['reason'=>get_class($error)]);
        }
    }
    private static function terms_changed(): void {
        if (Brands::unrestricted()) { return; }
        if (Brands::active()&&!self::$terms_all&&!self::$busy) { self::$terms_all=true; try { foreach (Stores::all() as $brand=>$_) { Stores::within((int)$brand,static fn()=>self::terms_changed()); } } finally { self::$terms_all=false; } return; }
        if (self::$busy||self::$deleting_terms||!self::collections()) { return; }
        try { self::locked(static function (): void { self::rebuild_local(); Stores::purge(); },0); }
        catch (\Throwable $error) {
            update_option('gsm_collections_dirty',1,false);
            if (!wp_next_scheduled('gsm_rebuild_collections',[Brands::id()])) { wp_schedule_single_event(time()+30,'gsm_rebuild_collections',[Brands::id()]); }
            Schema::audit('collection_rebuild_deferred',Brands::id(),0,['reason'=>get_class($error)]);
        }
    }
    public static function rebuild_job(): void {
        try { self::locked(static function (): void { self::rebuild_local(); Stores::purge(); }); }
        catch (\Throwable $error) {
            $attempt=(int)get_option('gsm_collections_dirty',0)+1; update_option('gsm_collections_dirty',$attempt,false);
            if ($attempt<3&&!wp_next_scheduled('gsm_rebuild_collections',[Brands::id()])) { wp_schedule_single_event(time()+60,'gsm_rebuild_collections',[Brands::id()]); }
            Schema::audit('collection_rebuild_failed',Brands::id(),0,['reason'=>get_class($error)]);
        }
    }
    public static function archive_order(\WP_Query $query): void {
        if (is_admin()||!$query->is_main_query()||!$query->is_tax('product_cat')) { return; }
        $term=$query->get_queried_object(); if (!$term instanceof \WP_Term) { return; }
        $spec=self::collections()[$term->term_id]??null; if (!$spec||($spec['taxonomy_scope']??'')!=='local'||!$spec['enabled']) { return; }
        // Hierarchy organizes collections; each archive keeps its own rule membership.
        $tax=(array)$query->get('tax_query');
        $query->set('tax_query',['relation'=>'AND',$tax,['taxonomy'=>'product_cat','field'=>'term_id','terms'=>[$term->term_id],'include_children'=>false]]);
        if (isset($_GET['orderby'])) { return; }
        if ($spec['sort']==='manual') {
            $members=get_posts(['post_type'=>'product','post_status'=>'publish','posts_per_page'=>self::MAX_PRODUCTS,'fields'=>'ids','orderby'=>'menu_order title','order'=>'ASC','tax_query'=>[['taxonomy'=>'product_cat','terms'=>[$term->term_id],'include_children'=>false]]]);
            $ids=array_merge(array_values(array_intersect($spec['priority'],$members)),array_values(array_diff($members,$spec['priority'])));
            if ($query->get('post__in')) { $ids=array_values(array_intersect($ids,$query->get('post__in'))); }
            $query->set('post__in',$ids?:[0]); $query->set('orderby','post__in'); $query->set('order','ASC');
        } else { $query->set('orderby',match($spec['sort']) { 'date'=>'date','title'=>'title',default=>'menu_order title' }); $query->set('order',$spec['sort']==='date'?'DESC':'ASC'); }
    }
    public static function navigation(): string {
        $rules=array_filter(self::collections(),static fn($spec)=>($spec['taxonomy_scope']??'')==='local'&&!empty($spec['enabled'])&&!empty($spec['navigation'])&&empty($spec['deleted_at']));
        $rows=self::tree($rules);
        if (!$rows) { return ''; }
        $index=0;
        $render=static function (int $depth) use (&$render,&$index,$rows): string {
            $html='<ul>';
            while (isset($rows[$index])&&$rows[$index]['depth']===$depth) {
                $row=$rows[$index++]; $url=get_term_link($row['term']);
                $html.='<li>'.(!is_wp_error($url)?'<a href="'.esc_url($url).'">'.esc_html($row['term']->name).'</a>':esc_html($row['term']->name));
                if (isset($rows[$index])&&$rows[$index]['depth']>$depth) { $html.=$render($depth+1); }
                $html.='</li>';
            }
            return $html.'</ul>';
        };
        return '<nav class="gsm-collections" aria-label="'.esc_attr__('Product collections','muti-storefront-plugin').'">'.$render(0).'</nav>';
    }
}
