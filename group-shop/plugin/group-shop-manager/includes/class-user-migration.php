<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Explicit, resumable identity migration. Never invoked by plugin activation or ordinary requests. */
final class UserMigration {
    public static function plan(): array {
        Stores::authorize(); $plan=[]; $blocked=[]; $network=get_current_network_id();
        foreach (get_users(['blog_id'=>0,'number'=>-1,'orderby'=>'ID']) as $user) {
            if (Identities::get($user->ID)) { continue; }
            $stores=[]; $staff=[]; $unknown=[]; $other_network=false;
            foreach (get_blogs_of_user($user->ID,true) as $site) {
                if ((int)$site->site_id!==$network) { $other_network=true; continue; }
                try { Stores::assert_store((int)$site->userblog_id); } catch (\Throwable $e) { $unknown[]=(int)$site->userblog_id; continue; }
                $roles=(new \WP_User($user->ID,'',(int)$site->userblog_id))->roles;
                if (array_intersect($roles,['administrator','shop_manager'])) { $staff[]=(int)$site->userblog_id; }
                elseif ($roles && !array_diff($roles,['customer','subscriber'])) { $stores[]=(int)$site->userblog_id; }
                elseif ($roles) { $unknown[]=(int)$site->userblog_id; }
            }
            if ($other_network||$unknown) { $blocked[]=['user'=>$user->ID,'reason'=>$other_network?'other_network_membership':'unsupported_or_inactive_membership','stores'=>$unknown]; continue; }
            $is_staff=is_super_admin($user->ID)||$staff;
            if (is_super_admin($user->ID)) { $stores=[]; }
            if (!$is_staff&&!$stores) { $blocked[]=['user'=>$user->ID,'reason'=>'no_customer_store']; continue; }
            $home=(int)get_user_meta($user->ID,'_gsm_first_store',true);
            if (!$is_staff&&!in_array($home,$stores,true)) { $home=$stores[0]; }
            $plan[]=['user'=>$user->ID,'kind'=>$is_staff?'staff':'customer','home'=>$is_staff?0:$home,'customer_stores'=>$stores,'staff_stores'=>$staff,'done'=>false];
        }
        return ['sources'=>$plan,'blocked'=>$blocked];
    }
    public static function run(): array {
        Stores::authorize(); global $wpdb;
        $lock='gsm_identity_migration_'.get_current_network_id();
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1) { throw new \RuntimeException('账户迁移正在运行。'); }
        $no_mail=static fn()=>false; add_filter('pre_wp_mail',$no_mail,PHP_INT_MAX);
        try {
            $saved=(array)get_site_option('gsm_identity_migration_sources',[]);
            $plan=self::plan();
            if ($plan['blocked']) { throw new \RuntimeException('有账户需要先确认身份／停用或跨网络归属：'.implode(',',array_column($plan['blocked'],'user'))); }
            foreach ($plan['sources'] as $source) { $saved[$source['user']]??=$source; }
            update_site_option('gsm_identity_migration_sources',$saved);
            $counts=['sources'=>0,'customer_accounts'=>0,'orders'=>0,'reviews'=>0];
            foreach ($saved as $old=>$source) {
                if (!empty($source['done'])) { continue; }
                $result=Identities::internal(static fn()=>self::migrate_source($source));
                foreach ($counts as $key=>$_) { $counts[$key]+=$result[$key]??0; }
                $saved[$old]['done']=true; update_site_option('gsm_identity_migration_sources',$saved);
            }
            update_site_option('gsm_identity_ready',true);
            update_site_option('gsm_identity_migration_version',GSM_VERSION);
            Schema::audit('identity_migration_completed',Stores::main_id(),0,['reason'=>'customer_store_isolation']);
            return $counts;
        } finally { remove_filter('pre_wp_mail',$no_mail,PHP_INT_MAX); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    private static function migrate_source(array $source): array {
        $old=(int)$source['user']; $user=get_userdata($old);
        if (!$user) { throw new \RuntimeException('迁移源账户不存在：'.$old); }
        $counts=['sources'=>1,'customer_accounts'=>0,'orders'=>0,'reviews'=>0];
        if ($source['kind']==='staff'&&!Identities::get($old)) { Identities::put($old,'staff',0,$user->user_login,$user->user_email); }
        foreach ($source['customer_stores'] as $store) {
            $store=(int)$store; $home=$source['kind']==='customer'&&(int)$source['home']===$store;
            $row=Identities::find($store,$user->user_login);
            if ($row) { $id=(int)$row['user_id']; }
            elseif ($home) { $id=$old; Identities::put($id,'customer',$store,$user->user_login,$user->user_email); }
            else {
                $id=Stores::within($store,static function () use ($user,$store): int {
                    // A migration-only bypass of the global email check; the scoped index owns uniqueness.
                    $allow=static fn()=>false; add_filter('email_exists',$allow,PHP_INT_MAX);
                    try {
                        $id=wp_insert_user(['user_login'=>'gsmc_'.$store.'_'.str_replace('-','',wp_generate_uuid4()),'user_pass'=>wp_generate_password(40),'user_email'=>$user->user_email,'display_name'=>$user->display_name,'first_name'=>$user->first_name,'last_name'=>$user->last_name,'role'=>'customer']);
                    } finally { remove_filter('email_exists',$allow,PHP_INT_MAX); }
                    if (is_wp_error($id)) { throw new \RuntimeException('迁移账户建立失败：'.$user->ID); }
                    global $wpdb;
                    // Preserve the existing hash once; no plaintext password is read or synchronized later.
                    $wpdb->update($wpdb->users,['user_pass'=>$user->user_pass],['ID'=>$id]); clean_user_cache($id);
                    Identities::put((int)$id,'customer',$store,$user->user_login,$user->user_email);
                    update_user_meta($id,'_gsm_legacy_user_id',$user->ID);
                    return (int)$id;
                });
            }
            Stores::within($store,static function () use ($old,$id,$store,$user,&$counts): void {
                $u=new \WP_User($id); $u->set_role('customer');
                update_user_meta($id,'primary_blog',$store);
                if ($id!==$old) {
                    foreach (get_user_meta($old) as $key=>$values) {
                        if (str_starts_with($key,'billing_')||str_starts_with($key,'shipping_')||in_array($key,['first_name','last_name','nickname'],true)||$key==='_woocommerce_persistent_cart_'.$store) {
                            update_user_meta($id,$key,maybe_unserialize($values[0]));
                        }
                    }
                    do {
                        $orders=wc_get_orders(['customer_id'=>$old,'limit'=>100,'return'=>'objects','type'=>'shop_order']);
                        foreach ($orders as $order) {
                            $order->set_customer_id($id); $order->save();
                            if (wc_get_order($order->get_id())->get_customer_id()!==$id) { throw new \RuntimeException('订单顾客迁移保存失败：'.$order->get_id()); }
                            ++$counts['orders'];
                        }
                    } while (count($orders)===100);
                    do {
                        $comments=get_comments(['user_id'=>$old,'post_type'=>'product','status'=>'all','number'=>100]);
                        foreach ($comments as $comment) { wp_update_comment(['comment_ID'=>$comment->comment_ID,'user_id'=>$id]); ++$counts['reviews']; }
                    } while (count($comments)===100);
                    foreach (\WC_Data_Store::load('customer-download')->get_downloads(['user_id'=>$old,'limit'=>-1]) as $download) { $download->set_user_id($id); $download->save(); }
                    do {
                        $tokens=Identities::local_tokens($old);
                        foreach ($tokens as $token) { $token->set_user_id($id); $token->save(); }
                    } while (count($tokens)===100);
                    delete_user_meta($old,'_woocommerce_persistent_cart_'.$store);
                    remove_user_from_blog($old,$store);
                }
                update_user_meta($id,'_gsm_first_store',$store);
                add_user_meta($id,'_gsm_store_'.$store.'_joined_at',gmdate('c'),true);
                if (class_exists('Automattic\\WooCommerce\\Admin\\API\\Reports\\Customers\\DataStore')) { \Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::update_registered_customer($id); }
                ++$counts['customer_accounts'];
            });
        }
        if ($source['kind']==='customer'||$source['customer_stores']) { \WP_Session_Tokens::get_instance($old)->destroy_all(); }
        return $counts;
    }
}
