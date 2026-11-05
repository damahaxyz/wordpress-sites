<?php
/** Isolated QA ONLY: never mount this file into the production stack. */
if (!defined('ABSPATH')) { exit; }
add_filter('pre_wp_mail', static function ($result, $mail) {
    if (!isset($GLOBALS['gsm_qa_mail'])) { $GLOBALS['gsm_qa_mail'] = []; }
    $GLOBALS['gsm_qa_mail'][] = ['store' => get_current_blog_id(), 'subject' => $mail['subject'] ?? '', 'headers' => $mail['headers'] ?? []];
    return $result === false ? false : true;
}, PHP_INT_MAX, 2);
add_filter('woocommerce_available_payment_gateways', '__return_empty_array', PHP_INT_MAX);
// Media must not change the site while unrelated admin initialization is running.
add_action('admin_init',static function (): void {
    if (wp_doing_ajax()&&isset($_REQUEST['gsm_media_store'])&&get_current_blog_id()!==1) {
        wp_send_json_error(['message'=>'QA: media changed shop before its native handler'],500);
    }
},50);
