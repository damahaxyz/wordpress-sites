<?php
use GroupShop\Manager\{Stores,Collections,CollectionDisplay,CollectionAdmin,SharedTaxonomies};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_HOST)!=='localhost'||(int)wp_parse_url(get_home_url(Stores::main_id()),PHP_URL_PORT)!==18087) { throw new RuntimeException('Local isolated QA only'); }
wp_set_current_user(get_user_by('login','gsm-admin')->ID);
$checks=[]; $assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$throws=static function ($fn,$name) use ($assert): void { $caught=false; try { $fn(); } catch(Throwable $e) { $caught=true; } $assert($caught,$name); };
$main=Stores::main_id(); $a=(int)get_site_by_path(get_network()->domain,'/brand-a/')->blog_id; $b=(int)get_site_by_path(get_network()->domain,'/brand-b/')->blog_id;
$suffix=bin2hex(random_bytes(3));
Stores::within($a,static function (): void { update_option('woocommerce_coming_soon','no'); });
$parent=(int)wp_insert_term('QA Collection Parent '.$suffix,'product_cat')['term_id']; $leaf=(int)wp_insert_term('QA Collection Leaf '.$suffix,'product_cat',['parent'=>$parent])['term_id'];
$tag1=(int)wp_insert_term('QA Collection Tag A '.$suffix,'product_tag')['term_id']; $tag2=(int)wp_insert_term('QA Collection Tag B '.$suffix,'product_tag')['term_id']; SharedTaxonomies::reconcile();
$local=static fn($source,$tax)=>Stores::within($a,static function () use ($source,$tax): int { $ids=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'fields'=>'ids','meta_query'=>[['key'=>'_gsm_shared_source','value'=>$source]]]); return (int)$ids[0]; });
$cat=$local($parent,'product_cat'); $child=$local($leaf,'product_cat'); $t1=$local($tag1,'product_tag'); $t2=$local($tag2,'product_tag');
$products=Stores::within($a,static function () use ($cat,$child,$t1,$t2,$suffix): array {
    $items=[];
    foreach ([['Red',[$child],[$t1,$t2],'publish','visible'],['Blue',[$cat],[$t1],'publish','visible'],['Extra',[],[],'publish','visible'],['Draft',[$child],[$t1,$t2],'draft','visible'],['Hidden',[$child],[$t1,$t2],'publish','hidden']] as [$name,$cats,$tags,$status,$visibility]) {
        $p=new WC_Product_Simple(); $p->set_name('QA Collection '.$name.' '.$suffix); $p->set_regular_price('20'); $p->set_status($status); $p->set_catalog_visibility($visibility); $p->set_category_ids($cats); $p->set_tag_ids($tags); $items[strtolower($name)]=$p->save();
    }
    // A real Woo global attribute allows a real Shop layered-navigation HTTP regression.
    $attribute=wc_create_attribute(['name'=>'QA Collection Color','slug'=>'gsm-qa-color','type'=>'select','order_by'=>'menu_order']);
    if (is_wp_error($attribute)) { $attribute=wc_attribute_taxonomy_id_by_name('pa_gsm-qa-color'); }
    if (!taxonomy_exists('pa_gsm-qa-color')) { register_taxonomy('pa_gsm-qa-color',['product'],['hierarchical'=>true,'public'=>false]); }
    foreach (['red','blue'] as $name) {
        $term=term_exists($name,'pa_gsm-qa-color')?:wp_insert_term(ucfirst($name),'pa_gsm-qa-color',['slug'=>$name]);
        $attr=new WC_Product_Attribute(); $attr->set_id((int)$attribute); $attr->set_name('pa_gsm-qa-color'); $attr->set_options([(int)$term['term_id']]); $attr->set_visible(true);
        $p=wc_get_product($items[$name]); $p->set_attributes([$attr]); $p->save();
    }
    $images=get_posts(['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','numberposts'=>1,'fields'=>'ids']); $items['image']=(int)($images[0]??0);
    return $items;
});
$spec=['name'=>'QA Homepage Collection '.$suffix,'description'=>'<p>Reusable homepage and topic selection.</p>','image'=>$products['image'],'mode'=>'rule','categories'=>[$cat],'tags'=>[$t1,$t2],'tag_match'=>'all','include'=>[$products['extra']],'enabled'=>1,'shop_filter'=>1,'sort'=>'manual','priority'=>[$products['extra'],$products['red']]];
$id=Collections::save_collection($a,0,$spec); $values=Stores::within($a,fn()=>Collections::values($id));
$assert(str_starts_with($values['slug'],'gsm-collection-'),'New collection gets a stable automatic internal slug');
$assert($values['name']===$spec['name']&&$values['image']===$products['image'],'Title, description and local cover persist');
$members=Stores::within($a,fn()=>array_map('intval',get_objects_in_term($id,'product_cat')));
$assert(in_array($products['red'],$members,true)&&in_array($products['draft'],$members,true)&&in_array($products['hidden'],$members,true)&&in_array($products['extra'],$members,true)&&!in_array($products['blue'],$members,true),'Category descendants AND all tags plus manual inclusion determine membership');
$public=Stores::within($a,fn()=>get_posts(CollectionDisplay::query_args($id,['posts_per_page'=>48])));
$assert(array_map(static fn($p)=>$p->ID,$public)===[$products['extra'],$products['red']],'Public modules exclude draft/hidden products and respect manual priority');
$before_loop=['name'=>'prior-loop','columns'=>2]; $GLOBALS['woocommerce_loop']=$before_loop;
$html=Stores::within($a,fn()=>do_shortcode('[group_shop_collection id="'.$id.'" limit="1" paginate="yes" image="yes"]'));
$assert(str_contains($html,$spec['name'])&&str_contains($html,'Reusable homepage')&&str_contains($html,'gsm-collection-cover'),'Page module renders shared title, description and cover');
$assert(str_contains($html,'QA Collection Extra')&&!str_contains($html,'QA Collection Draft')&&!str_contains($html,'QA Collection Hidden'),'Page module uses the saved collection membership and public visibility');
$assert(str_contains($html,'gsm_page_'.$id),'Topic pagination is scoped to its collection');
$assert(WP_Block_Type_Registry::get_instance()->is_registered('group-shop/collection'),'Native page collection block is registered');
$block=Stores::within($a,fn()=>do_blocks('<!-- wp:group-shop/collection '.wp_json_encode(['collectionId'=>$id,'perPage'=>2,'columns'=>2,'showTitle'=>true,'showDescription'=>true,'showImage'=>true,'paginate'=>false]).' /-->'));
$assert(str_contains($block,$spec['name'])&&str_contains($block,'QA Collection Extra')&&str_contains($block,'QA Collection Red'),'Native dynamic block renders the same saved collection with selected display options');
$assert($GLOBALS['woocommerce_loop']===$before_loop&&get_current_blog_id()===$main,'Module restores Woo loop and store context');
$assert(Stores::within($a,fn()=>CollectionDisplay::data($id)['id'])===$id&&function_exists('group_shop_collection_query_args'),'Theme helpers expose the same current-store collection');
$assert(Stores::within($b,fn()=>CollectionDisplay::data($id))===null,'Numeric collection IDs cannot read another store collection');
$spec['name']='QA Topic Collection '.$suffix; $spec['slug']='should-not-change'; $spec['parent']=$cat; Collections::save_collection($a,$id,$spec);
$after=Stores::within($a,fn()=>Collections::values($id));
$assert($after['slug']===$values['slug']&&$after['parent']===0,'Title editing and forged URL/parent inputs cannot change archive identity');
$tag_collection=Collections::save_collection($a,0,['name'=>'QA Tags Only '.$suffix,'mode'=>'rule','tags'=>[$t2],'enabled'=>1]);
$assert(Stores::within($a,fn()=>has_term($tag_collection,'product_cat',$products['red']))&&!Stores::within($a,fn()=>has_term($tag_collection,'product_cat',$products['blue'])),'Tags-only collection selects the expected products');
$manual=Collections::save_collection($a,0,['name'=>'QA Handpicked '.$suffix,'mode'=>'manual','include'=>[$products['red'],$products['blue']],'exclude'=>[$products['red']],'enabled'=>1]);
$assert(Stores::within($a,fn()=>array_map('intval',get_objects_in_term($manual,'product_cat')))===[$products['blue']],'Manual selection with exclusion uses local products only');
$throws(fn()=>Collections::save_collection($a,0,['name'=>'Invalid','mode'=>'rule','enabled'=>1]),'Empty rule conditions are rejected');
$throws(fn()=>Collections::save_collection($a,$id,array_merge($spec,['image'=>9999999])),'Invalid local collection cover rejected');
$throws(fn()=>Collections::save_collection($a,$id,array_merge($spec,['include'=>[9999999]])),'Foreign or missing product rejected');
$term_count=count(Stores::within($a,fn()=>get_terms(['taxonomy'=>'product_cat','hide_empty'=>false])));
Collections::trash($a,$id);
$assert(Stores::within($a,fn()=>!empty(Collections::collections()[$id]['deleted_at'])),'Delete moves only the collection to recoverable trash');
$assert(Stores::within($a,fn()=>CollectionDisplay::data($id))===null&&Stores::within($a,fn()=>CollectionDisplay::shortcode(['id'=>$id]))==='','Trashed collection is not exposed to modules or theme helpers');
$assert(Stores::within($a,fn()=>get_objects_in_term($id,'product_cat'))===[],'Trashing removes derived membership');
$assert(Stores::within($a,fn()=>get_post_type($products['red']))==='product'&&Stores::within($a,fn()=>term_exists($cat,'product_cat')),'Delete preserves products and shared classification');
$throws(fn()=>Collections::save_collection($a,$id,$spec),'Deleted collection cannot be edited until restored');
Collections::trash($a,$id,true);
$assert(Stores::within($a,fn()=>CollectionDisplay::data($id))!==null&&Stores::within($a,fn()=>has_term($id,'product_cat',$products['red'])),'Restoring rebuilds membership and presentation state');
$assert(count(Stores::within($a,fn()=>get_terms(['taxonomy'=>'product_cat','hide_empty'=>false])))===$term_count,'Trash and restore never duplicate or delete underlying vocabulary');
$throws(fn()=>Collections::trash($a,$cat),'Shared category cannot be deleted via collection action');
$spec['enabled']=0; Collections::save_collection($a,$id,$spec); $assert(Stores::within($a,fn()=>CollectionDisplay::shortcode(['id'=>$id]))==='','Disabled collection does not appear in page modules'); $spec['enabled']=1; Collections::save_collection($a,$id,$spec);
$_GET=['filter_gsm-qa-color'=>'red','query_type_gsm-qa-color'=>'and','min_price'=>'5','orderby'=>'price','paged'=>4,'secret_token'=>'never-copy'];
$form=Stores::within($a,fn()=>CollectionDisplay::filter_form());
$assert(str_contains($form,'name="filter_gsm-qa-color"')&&str_contains($form,'name="min_price"')&&str_contains($form,'name="orderby"'),'Shop filter form preserves native attribute, price and sorting selections');
$assert(!str_contains($form,'secret_token')&&!str_contains($form,'name="paged"'),'Shop filter resets pagination and excludes unrelated query data');
$_GET=['page'=>'muti-storefront-plugin','tab'=>'collections','store'=>$a]; ob_start(); CollectionAdmin::collections($a); $list=ob_get_clean();
$assert(str_contains($list,'创建集合')&&str_contains($list,'collection_trash')&&!str_contains($list,'name="mode"'),'Collection default is a list with CRUD actions rather than an always-open editor');
$_GET['collection']='new'; ob_start(); CollectionAdmin::collections($a); $editor=ob_get_clean();
$assert(str_contains($editor,'集合标题')&&str_contains($editor,'gsm_collection_description')&&str_contains($editor,'gsm-image-picker'),'Dedicated editor provides title, rich description and native image picker');
$assert(!str_contains($editor,'name="slug"')&&!str_contains($editor,'name="parent"')&&!str_contains($editor,'网址别名'),'Editor has no URL slug or category parent inputs');
$assert(str_contains($editor,'name="categories[]"')&&str_contains($editor,'name="tags[]"')&&str_contains($editor,'gsm-product-picker'),'Editor supports categories, tags and searchable direct product selection');
$assert(strpos($editor,'gsm_collection_complete')>strpos($editor,'name="shop_filter"'),'Completeness marker follows the whole collection form');
$customer=get_users(['role'=>'customer','number'=>1,'blog_id'=>$a]); if ($customer) { wp_set_current_user($customer[0]->ID); $throws(fn()=>Collections::trash($a,$id),'Customer cannot delete collections'); $throws(fn()=>Collections::save_collection($a,$id,$spec),'Customer cannot edit collections'); wp_set_current_user(get_user_by('login','gsm-admin')->ID); }
$page=Stores::within($a,fn()=>wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'QA Collection Topic '.$suffix,'post_name'=>'qa-collection-topic-'.$suffix,'post_content'=>'[group_shop_collection id="'.$id.'" limit="1" paginate="yes" image="yes"]'],true));
$fixture=['main'=>$main,'a'=>$a,'b'=>$b,'collection'=>$id,'manual'=>$manual,'tags'=>$tag_collection,'products'=>$products,'category'=>$cat,'tag'=>$t1,'topic'=>Stores::within($a,fn()=>get_permalink($page)),'archive'=>Stores::within($a,fn()=>get_term_link($id,'product_cat')),'title'=>$spec['name']];
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'fixtures'=>$fixture],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
