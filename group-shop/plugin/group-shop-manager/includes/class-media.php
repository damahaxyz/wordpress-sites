<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Run WordPress's original media AJAX handlers in the explicitly authorized store. */
final class Media {
    private static ?int $depth=null;
    private const ACTIONS=['query-attachments','get-attachment','upload-attachment','save-attachment','save-attachment-compat','image-editor','imgedit-preview','crop-image','send-attachment-to-editor','send-link-to-editor'];
    public static function boot(): void { add_action('admin_init',[self::class,'guard_request'],0); }
    public static function target(array $request): int {
        Stores::authorize();
        $store=absint($request['gsm_media_store']??0);
        if (!in_array($request['action']??'',self::ACTIONS,true)) { throw new \RuntimeException('此媒体操作不受支持，请在所属店原生媒体库完成。'); }
        if (!$store||!wp_verify_nonce((string)($request['gsm_media_nonce']??''),'gsm_media_'.$store)) { throw new \RuntimeException('媒体选择授权已过期，请刷新当前编辑页。'); }
        Stores::assert_store($store);
        return $store;
    }
    public static function guard_request(): void {
        if (!wp_doing_ajax()||!isset($_REQUEST['gsm_media_store'])) { return; }
        try { self::target(wp_unslash($_REQUEST)); }
        catch (\Throwable $error) { wp_send_json_error(['message'=>$error->getMessage()],403); }
        // Leave all unrelated admin_init handlers in the original site's context.
        // In particular, a new target theme's one-time redirects must not run here.
        if (basename((string)($_SERVER['SCRIPT_NAME']??''))==='async-upload.php') {
            add_action('admin_init',[self::class,'ajax_context'],PHP_INT_MAX);
        } else {
            add_action('wp_ajax_'.sanitize_key($_REQUEST['action']),[self::class,'ajax_context'],0);
        }
    }
    public static function ajax_context(): void {
        self::target(wp_unslash($_REQUEST));
        if (!current_user_can('upload_files')) { wp_send_json_error(['message'=>'没有媒体权限。'],403); }
        // All brands use the same native media library; never switch WordPress sites.
    }
}
