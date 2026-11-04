<?php
use GroupShop\Manager\{Stores,Admin,ReviewAdmin};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || get_home_url(Stores::main_id()) !== 'http://www.main.local') { throw new RuntimeException('Mapped local synthetic QA only'); }
wp_set_current_user(get_user_by('login','gsm-admin')->ID);
$checks=[];
$assert=static function ($ok,$name) use (&$checks): void { if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $checks[]=$name; };
$main=Stores::main_id(); $fixtures=[];
foreach ([$main,6,7] as $store) {
    $fixtures[$store]=Stores::within($store,static function () use ($store,$assert): array {
        $products=wc_get_products(['limit'=>1,'status'=>'publish','return'=>'ids']);
        if (!$products) { throw new RuntimeException('Published synthetic fixture product required'); }
        $product=(int)$products[0];
        $review=wp_insert_comment(['comment_post_ID'=>$product,'comment_author'=>'QA Native Reviews','comment_content'=>'GSM native review store '.$store,'comment_type'=>'review','comment_approved'=>1]);
        update_comment_meta($review,'rating',4);
        $reply=wp_insert_comment(['comment_post_ID'=>$product,'comment_parent'=>$review,'comment_author'=>'QA Store Reply','comment_content'=>'GSM native reply store '.$store,'comment_type'=>'comment','comment_approved'=>1]);
        $label=get_bloginfo('name').' (#'.$store.')';
        foreach ([$review,$reply] as $id) {
            ob_start(); do_action('woocommerce_product_reviews_table_column_gsm_store',get_comment($id)); $output=ob_get_clean();
            $assert($output===esc_html($label),'Native review/reply column uses current store: '.$store.' comment '.$id);
        }
        $assert(get_comment($review)->comment_approved==='1' && (int)get_comment_meta($review,'rating',true)===4,'Display column leaves approval and rating unchanged: '.$store);
        return ['review'=>$review,'reply'=>$reply,'product'=>$product,'name'=>get_bloginfo('name'),'host'=>wp_parse_url(home_url('/'),PHP_URL_HOST),'url'=>admin_url('edit.php?post_type=product&page=product-reviews'),'label'=>$label];
    });
}
$assert(get_current_blog_id()===$main,'Rendering columns across stores restores original blog');
$columns=['cb'=>'Select','author'=>'Author','rating'=>'Rating','comment'=>'Review'];
$extended=apply_filters('woocommerce_product_reviews_table_columns',$columns);
$assert(array_diff_assoc($columns,$extended)===[] && $extended['gsm_store']==='所属店铺','Native columns remain intact with one store column');
$assert(!has_filter('manage_edit-comments_columns',[ReviewAdmin::class,'columns']),'Ordinary WordPress comments table is unaffected');
ob_start(); ReviewAdmin::column(null); $invalid=ob_get_clean();
$assert($invalid==='','Invalid row is ignored');
// Same local comment/product numbers in different blogs must still use current context.
$object=get_comment($fixtures[$main]['review']);
$foreign=Stores::within(6,static function () use ($object): string { ob_start(); ReviewAdmin::column($object); return ob_get_clean(); });
$assert(!str_contains($foreign,'(#'.$main.')'),'Column never infers main-store ownership from a numeric comment ID');
$_GET=['page'=>'group-shop-manager','tab'=>'reviews','store'=>6];
ob_start(); Admin::render(); $html=ob_get_clean();
$assert(!str_contains($html,'独立评论') && !str_contains($html,'tab=reviews') && str_contains($html,'<h2>店铺入口</h2>'),'Retired review tab falls back to overview without old links/forms');
$assert(!str_contains($html,'review_status'),'Retired central review form is absent');
$assert(substr_count($html,'class="nav-tab ')===8,'Eight current manager tabs remain');
$assert(get_current_blog_id()===$main,'All checks preserve original blog');
echo wp_json_encode(['version'=>GSM_VERSION,'passed'=>count($checks),'checks'=>$checks,'fixtures'=>$fixtures],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
