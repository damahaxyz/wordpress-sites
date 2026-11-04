<?php
use GroupShop\Manager\{Stores,Identities,Schema};
if (!defined('WP_CLI')||!WP_CLI||wp_get_environment_type()!=='local'||get_home_url()!=='http://www.main.local') { throw new RuntimeException('Mapped local QA only'); }
$f=json_decode(file_get_contents('/gsm-private/fixtures.json'),true,512,JSON_THROW_ON_ERROR); $keep=[(int)$f['a'],(int)$f['b'],(int)$f['staff']]; $removed=0;
Identities::internal(static function () use ($keep,&$removed): void {
    require_once ABSPATH.'wp-admin/includes/ms.php';
    foreach (get_users(['blog_id'=>0,'number'=>-1]) as $u) {
        $alias=Identities::alias($u->ID);
        if ((!str_starts_with($alias,'isolated-')&&!str_starts_with($alias,'httpiso-'))||!str_ends_with($u->user_email,'@example.invalid')||in_array($u->ID,$keep,true)||is_super_admin($u->ID)) { continue; }
        foreach (Stores::all() as $store=>$_) { if (Stores::within((int)$store,static fn()=>wc_get_orders(['customer_id'=>$u->ID,'limit'=>1]))) { throw new RuntimeException('Fixture has an unexpected order'); } }
        WP_Session_Tokens::get_instance($u->ID)->destroy_all(); wpmu_delete_user($u->ID); ++$removed;
    }
    foreach ($keep as $id) { WP_Session_Tokens::get_instance($id)->destroy_all(); wp_update_user(['ID'=>$id,'user_activation_key'=>'']); }
});
unset($f['reset_a']); file_put_contents('/gsm-private/fixtures.json',wp_json_encode($f)); chmod('/gsm-private/fixtures.json',0600);
global $wpdb;
$rows=$wpdb->get_results('SELECT kind,COUNT(*) AS accounts FROM '.Schema::table('identities').' GROUP BY kind',ARRAY_A);
$orphans=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('identities')." i LEFT JOIN {$wpdb->users} u ON i.user_id=u.ID WHERE u.ID IS NULL");
if ($orphans) { throw new RuntimeException('Orphan identities remain'); }
echo wp_json_encode(['version'=>GSM_VERSION,'removed_redundant_synthetic_users'=>$removed,'retained_fixture_ids'=>$keep,'identities'=>$rows,'orphan_identities'=>$orphans,'test_sessions_revoked'=>true,'ready'=>Identities::ready()],JSON_PRETTY_PRINT)."\n";
