<?php
use GroupShop\Manager\{Brands,Stores,Identities,SingleMigration};
if (!defined('WP_CLI')||!WP_CLI||is_multisite()||wp_get_environment_type()!=='local'||!Brands::active()) { throw new RuntimeException('Migrated local QA only'); }
$b=unserialize(file_get_contents('/gsm-private/multisite-export.bin')); $map=get_option('gsm_single_migration_map'); $checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$assert(!is_multisite()&&count(Stores::all())===7,'One native WordPress site retains seven independent brand registrations');
Brands::unscoped(static function () use ($b,$map,$assert): void {
 foreach ($b['brands'] as $brand=>$source) {
    $assert(Brands::url((int)$brand)===untrailingslashit($source['options']['home']),'Brand domain is retained: '.$brand);
    foreach ($source['products'] as $old=>$row) {
        $id=(int)$map['posts'][$brand][$old];$p=wc_get_product($id);$d=$row['data'];
        $ok=$p&&$p->get_type()===$row['type']&&(int)$p->get_meta('_gsm_brand_id')===(int)$brand;
        foreach (['name','description','short_description','status','regular_price','sale_price','menu_order'] as $prop) { if ($p->{'get_'.$prop}('edit')!=$d[$prop]) { throw new RuntimeException('FAIL product '.$brand.':'.$old.' '.$prop); } }
        $expected=(int)($map['posts'][$brand][$d['image_id']]??0);
        $assert($ok&&(int)$p->get_image_id('edit')===$expected,'Native product type, brand, title, descriptions, state, price and image retained: '.$brand.':'.$old);
        if ($row['type']==='variation') { $assert($p->get_attributes()===$d['attributes']&&(int)$p->get_parent_id()===(int)$map['posts'][$brand][$d['parent_id']],'Variation specs and parent retained: '.$brand.':'.$old); }
        else { $assert((int)$p->get_meta('_gsm_base_product')>0,'Edition has a native shared product base: '.$brand.':'.$old); }
    }
    foreach ($source['posts'] as $old=>$row) {
        if ($row['data']['post_type']!=='attachment') { continue; }
        $id=(int)$map['posts'][$brand][$old];$assert(is_file(get_attached_file($id)),'Shared attachment keeps its existing file: '.$brand.':'.$old);
    }
    foreach ($source['posts'] as $old=>$row) {
        if (!in_array($row['data']['post_type'],['page','post','nav_menu_item'],true)) { continue; }
        $id=(int)$map['posts'][$brand][$old];$post=get_post($id);
        $assert($post&&$post->post_title===$row['data']['post_title']&&$post->post_status===$row['data']['post_status']&&(int)get_post_meta($id,'_gsm_brand_id',true)===(int)$brand,'Native page/article/menu title, status and ownership retained: '.$brand.':'.$old);
        if ($post->post_type==='nav_menu_item') { $type=$row['meta']['_menu_item_type'][0]??'';$object=$row['meta']['_menu_item_object'][0]??'';$oid=(int)($row['meta']['_menu_item_object_id'][0]??0);$expected=$type==='taxonomy'?($map['terms'][$brand][$object][$oid]??$oid):($map['posts'][$brand][$oid]??$oid);$assert((int)get_post_meta($id,'_menu_item_object_id',true)===(int)$expected,'Native menu target follows exact content or taxonomy mapping: '.$brand.':'.$old); }
    }
    foreach ($source['comments'] as $old=>$row) {
        $data=$row['data'];$rows=get_comments(['post_id'=>(int)$map['posts'][$brand][$data['comment_post_ID']],'status'=>'all','number'=>0]);$match=false;
        foreach ($rows as $comment) { if ($comment->comment_content===$data['comment_content']&&(int)$comment->user_id===(int)$data['user_id']&&$comment->comment_approved===$data['comment_approved']&&$comment->comment_author===$data['comment_author']) { $match=true;break; } }
        $assert($match,'Original native comment author, content and moderation retained: '.$brand.':'.$old);
    }
    foreach ($source['orders'] as $old=>$row) {
        $o=wc_get_order((int)$map['orders'][$brand][$old]);$d=$row['data'];
        $ok=$o&&(int)$o->get_meta('_gsm_store_id')===(int)$brand;
        foreach (['status','total','currency','customer_id','billing','shipping','customer_note'] as $prop) { $actual=match($prop){'billing','shipping'=>$o->get_address($prop),default=>$o->{'get_'.$prop}('edit')};$ok=$ok&&$actual==$d[$prop]; }
        $assert($ok,'Historical order state, amount, customer and address snapshot retained: '.$brand.':'.$old);
        $expected=array_values(array_filter($row['items'],static fn($i)=>$i['type']==='line_item')); $actual=array_values($o->get_items());
        $assert(count($expected)===count($actual),'Historical line count retained: '.$brand.':'.$old);
        foreach ($expected as $k=>$i) { $data=$i['data'];$line=$actual[$k];$assert($line->get_name()===$data['name']&&$line->get_total()===$data['total']&&$line->get_quantity()===$data['quantity']&&(int)$line->get_product_id()===(int)($map['posts'][$brand][$data['product_id']]??0),'Historical order line identity and sale price retained: '.$brand.':'.$old.':'.$k); }
    }
    $rules=Brands::raw_options((int)$brand)['gsm_collections']??[];
    $assert(count($rules)===count($source['options']['gsm_collections']??[]),'Brand collection rules retained: '.$brand);
    foreach ($source['terms']['product_cat']??[] as $old=>$row) { if (!empty($row['meta']['_gsm_collection'])) { continue; } $slug=$row['data']['slug']; $found=get_term_by('slug',$slug,'product_cat'); $assert($found&&(int)$found->term_id===(int)$map['terms'][$brand]['product_cat'][$old],'Ordinary category uses one global term: '.$brand.':'.$old); }
 }
});
$assert(SingleMigration::run($b)===get_option('gsm_single_migration_report'),'Completed source migration is idempotent');
$assert(get_current_blog_id()===1&&Brands::id()===1,'Brand operations never switch the native WordPress site');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
