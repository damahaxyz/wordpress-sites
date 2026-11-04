<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Native privacy tools must use the same store-scoped resolver as login. */
final class IdentityPrivacy {
    public static function boot(): void {
        add_filter('wp_privacy_personal_data_exporters',static function ($items) {
            foreach ($items as &$item) { if (($item['callback']??null)==='wp_user_personal_data_exporter') { $item['callback']=[self::class,'profile']; } } unset($item);
            foreach (['woocommerce-customer-data'=>'customer','woocommerce-customer-orders'=>'orders','woocommerce-customer-downloads'=>'downloads','woocommerce-customer-tokens'=>'tokens'] as $key=>$method) { if (isset($items[$key])) { $items[$key]['callback']=[self::class,$method]; } }
            return $items;
        },PHP_INT_MAX);
        add_filter('wp_privacy_personal_data_erasers',static function ($items) {
            foreach (['woocommerce-customer-data'=>'erase_customer','woocommerce-customer-orders'=>'erase_orders','woocommerce-customer-downloads'=>'erase_downloads','woocommerce-customer-tokens'=>'erase_tokens'] as $key=>$method) { if (isset($items[$key])) { $items[$key]['callback']=[self::class,$method]; } }
            return $items;
        },PHP_INT_MAX);
    }
    private static function id(string $email): int { return (int)(Identities::find(get_current_blog_id(),$email,true)['user_id']??0); }
    private static function reader(): object {
        return new class extends \WC_Privacy_Exporters {
            public static function customer_fields($user): array { return parent::get_customer_personal_data($user); }
            public static function order_fields($order): array { return parent::get_order_personal_data($order); }
            public static function download_fields($download): array { return parent::get_download_personal_data($download); }
        };
    }
    private static function exported(array $data,bool $done=true): array { return ['data'=>$data,'done'=>$done]; }
    private static function item(string $group,string $id,array $data): array { return ['group_id'=>$group,'group_label'=>$group,'item_id'=>$id,'data'=>$data]; }
    private static function response(): array { return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true]; }
    public static function profile(string $email,int $page=1): array {
        $id=self::id($email); if (!$id) { return self::exported([]); }
        $user=get_userdata($id); $fields=[];
        foreach (['ID','user_email','user_url','user_registered','display_name','first_name','last_name','nickname','description'] as $key) { if ($user->$key!=='') { $fields[]=['name'=>$key,'value'=>$user->$key]; } }
        $fields[]=['name'=>'Username','value'=>Identities::alias($id)];
        return self::exported([self::item('user','user-'.$id,$fields)]);
    }
    public static function customer(string $email,int $page=1): array {
        $id=self::id($email); return self::exported($id?[self::item('woocommerce_customer','user-'.$id,self::reader()::customer_fields(get_userdata($id)))]:[]);
    }
    private static function order_rows(string $email,int $page): array {
        $customers=[$email]; $id=self::id($email); if ($id) { $customers[]=$id; }
        return wc_get_orders(['limit'=>10,'page'=>max(1,$page),'customer'=>$customers]);
    }
    public static function orders(string $email,int $page=1): array {
        $rows=self::order_rows($email,$page); $data=[];
        foreach ($rows as $row) { $data[]=self::item('woocommerce_orders','order-'.$row->get_id(),self::reader()::order_fields($row)); }
        return self::exported($data,count($rows)<10);
    }
    public static function downloads(string $email,int $page=1): array {
        $id=self::id($email); $args=['limit'=>10,'page'=>max(1,$page)]+($id?['user_id'=>$id]:['user_email'=>$email]);
        $rows=\WC_Data_Store::load('customer-download')->get_downloads($args); $data=[];
        foreach ($rows as $row) {
            $data[]=self::item('woocommerce_downloads','download-'.$row->get_id(),self::reader()::download_fields($row));
            foreach (\WC_Data_Store::load('customer-download-log')->get_download_logs_for_permission($row->get_id()) as $log) { $data[]=self::item('woocommerce_download_logs','download-log-'.$log->get_id(),[['name'=>'Download ID','value'=>$log->get_permission_id()],['name'=>'Timestamp','value'=>$log->get_timestamp()],['name'=>'IP Address','value'=>$log->get_user_ip_address()]]); }
        }
        return self::exported($data,count($rows)<10);
    }
    public static function tokens(string $email,int $page=1): array {
        $id=self::id($email); if (!$id) { return self::exported([]); }
        $rows=Identities::local_tokens($id,$page,10); $data=[];
        foreach ($rows as $row) { $data[]=self::item('woocommerce_tokens','token-'.$row->get_id(),[['name'=>'Token','value'=>$row->get_display_name()]]); }
        return self::exported($data,count($rows)<10);
    }
    public static function erase_customer(string $email,int $page=1): array {
        $out=self::response(); $id=self::id($email); if (!$id) { return $out; }
        $customer=new \WC_Customer($id);
        foreach (['billing','shipping'] as $type) { foreach ($customer->get_data()[$type] as $key=>$value) { $setter='set_'.$type.'_'.$key; if ($value!==''&&is_callable([$customer,$setter])) { $customer->$setter(''); $out['items_removed']=true; } } }
        $customer->save();
        return apply_filters('woocommerce_privacy_erase_personal_data_customer',$out,$customer);
    }
    public static function erase_orders(string $email,int $page=1): array {
        $out=self::response(); $rows=self::order_rows($email,$page); $enabled=wc_string_to_bool(get_option('woocommerce_erasure_request_removes_order_data','no'));
        foreach ($rows as $row) {
            if (apply_filters('woocommerce_privacy_erase_order_personal_data',$enabled,$row)) { \WC_Privacy_Erasers::remove_order_personal_data($row); $out['items_removed']=true; }
            else { $out['items_retained']=true; }
        }
        $out['done']=count($rows)<10; return $out;
    }
    public static function erase_downloads(string $email,int $page=1): array {
        $out=self::response(); $enabled=wc_string_to_bool(get_option('woocommerce_erasure_request_removes_download_data','no'));
        if (apply_filters('woocommerce_privacy_erase_download_personal_data',$enabled,$email)) {
            $store=\WC_Data_Store::load('customer-download'); $id=self::id($email);
            $out['items_removed']=(bool)($id?$store->delete_by_user_id($id):$store->delete_by_user_email($email));
        } else { $out['items_retained']=true; }
        return $out;
    }
    public static function erase_tokens(string $email,int $page=1): array {
        $out=self::response(); $id=self::id($email); if (!$id) { return $out; }
        $tokens=Identities::local_tokens($id,1,10);
        foreach ($tokens as $token) { \WC_Payment_Tokens::delete($token->get_id()); $out['items_removed']=true; }
        $out['done']=count($tokens)<10;
        return apply_filters('woocommerce_privacy_erase_personal_data_tokens',$out,$tokens);
    }
}
