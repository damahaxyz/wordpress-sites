<?php
/** New-host deployment checks. Does not authenticate, send mail or create orders. */
if (!defined('WP_CLI') || !WP_CLI || home_url('/') !== 'https://www.animecats.online/') { throw new RuntimeException('Wrong site'); }
global $wpdb;
$options=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%ppcp%' OR option_name LIKE '%paypal%'");
$report=['paypal_option_names'=>$options];
foreach (WC()->payment_gateways()->payment_gateways() as $id=>$gateway) {
    if (str_contains($id,'paypal') || str_contains($id,'ppcp') || str_contains($id,'stripe')) {
        $report['gateways'][$id]=['enabled'=>$gateway->enabled, 'available'=>$gateway->is_available()];
    }
}
$report['stripe_callback']=home_url('/?wc-api=wc_stripe');
$report['products']=$wpdb->get_results("SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID",ARRAY_A);
$report['mail_encryption_ok']=fluentMailEncryptDecrypt((string)(get_option('fluentmail-settings')['test']??''),'d')==='test';
$report['name']=get_bloginfo('name');
$report['url']=home_url('/');
echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
