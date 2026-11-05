<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Owner-scoped, resumable native-product jobs; one product per request. */
final class BulkProducts {
    public const LIMIT=200;
    private const MODES=['gsm_add_brand'=>'批量添加到品牌（草稿）','gsm_publish'=>'批量上架品牌商品','gsm_draft'=>'批量转为草稿'];
    public static function boot(): void {
        add_filter('bulk_actions-edit-product',static function ($actions) { if (Brands::root()) { $actions['gsm_add_brand']=self::MODES['gsm_add_brand']; } if (current_user_can('edit_products')) { $actions['gsm_publish']=self::MODES['gsm_publish']; $actions['gsm_draft']=self::MODES['gsm_draft']; } return $actions; },30);
        add_filter('handle_bulk_actions-edit-product',static function ($redirect,$mode,$ids) { if (!isset(self::MODES[$mode])) { return $redirect; } try { $job=self::prepare($mode,(array)$ids); return self::url($job['token']); } catch (\Throwable $e) { set_transient('gsm_bulk_error_'.get_current_user_id(),'无法准备批量任务：请确认权限，每批选择1至200件商品。',120); return self::url(); } },20,3);
        add_action('admin_menu',static fn()=>add_submenu_page('edit.php?post_type=product','批量商品操作','批量任务','edit_products','gsm-bulk-products',[self::class,'render']));
        add_action('admin_post_gsm_bulk_start',static function (): void { try { $token=self::token($_POST['gsm_job']??''); check_admin_referer('gsm_bulk_'.$token); self::start($token,absint($_POST['target_brand']??0)); wp_safe_redirect(self::url($token)); } catch (\Throwable $e) { set_transient('gsm_bulk_error_'.get_current_user_id(),'无法开始：请检查目标品牌、任务期限及操作权限。',120); wp_safe_redirect(self::url(isset($token)?$token:'')); } exit; });
        add_action('wp_ajax_gsm_bulk_step',[self::class,'ajax']);
        add_action('admin_enqueue_scripts',static function (): void { if (($_GET['page']??'')!=='gsm-bulk-products') { return; } wp_enqueue_style('gsm-bulk-products',plugins_url('assets/bulk-products.css',GSM_FILE),[],GSM_VERSION); wp_enqueue_script('gsm-bulk-products',plugins_url('assets/bulk-products.js',GSM_FILE),[],GSM_VERSION,true); });
    }
    private static function token(mixed $token): string { if (!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token)) { throw new \RuntimeException('Invalid job'); } return $token; }
    private static function authorize(string $mode): void { if (!isset(self::MODES[$mode])||!current_user_can('edit_products')||($mode==='gsm_add_brand'&&!Brands::root())) { throw new \RuntimeException('No permission'); } }
    private static function key(string $token): string { return 'gsm_bulk_'.get_current_user_id().'_'.self::token($token); }
    private static function save(array $job): void { $job['updated']=time(); set_transient(self::key($job['token']),$job,DAY_IN_SECONDS); }
    public static function get(string $token): array { $job=get_transient(self::key($token)); if (!is_array($job)||($job['owner']??0)!==get_current_user_id()) { throw new \RuntimeException('Expired job'); } self::authorize($job['mode']); return $job; }
    private static function lock(string $token,callable $callback): mixed { global $wpdb; $key='gsm_bulk_'.md5($wpdb->prefix.get_current_user_id().$token); if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!==1) { return null; } try { return $callback(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); } }
    public static function prepare(string $mode,array $ids): array {
        self::authorize($mode);$ids=array_values(array_unique(array_filter(wp_parse_id_list($ids)))); if (!$ids||count($ids)>self::LIMIT) { throw new \RuntimeException('Selection size'); }
        $old=(string)get_user_meta(get_current_user_id(),'_gsm_bulk_job',true);
        if ($old) { try { $active=self::get($old); if ($active['stage']==='running') { return $active; } } catch (\Throwable $e) {} }
        $token=bin2hex(random_bytes(16));$expected=[];foreach ($ids as $id) { $expected[$id]=get_post_status($id); }
        $job=['token'=>$token,'owner'=>get_current_user_id(),'mode'=>$mode,'target'=>0,'stage'=>'setup','ids'=>$ids,'queue'=>$ids,'next'=>0,'results'=>[],'expected'=>$expected,'created'=>time()]; self::save($job);update_user_meta(get_current_user_id(),'_gsm_bulk_job',$token);return $job;
    }
    public static function start(string $token,int $brand=0): array {
        $result=self::lock($token,static function () use ($token,$brand) { $job=self::get($token); if ($job['stage']!=='setup') { return $job; } if ($job['mode']==='gsm_add_brand') { Stores::assert_store($brand); if (!$brand) { throw new \RuntimeException('Choose brand'); } $job['target']=$brand; } $job['stage']='running';self::save($job);return $job; });
        if ($result===null) { throw new \RuntimeException('Busy'); }return $result;
    }
    public static function control(string $token,string $action): array {
        $job=self::lock($token,static function () use ($token,$action) { $job=self::get($token);
            if ($action==='pause'&&$job['stage']==='running') { $job['stage']='paused'; }
            elseif ($action==='resume'&&$job['stage']==='paused') { $job['stage']='running'; }
            elseif ($action==='retry'&&$job['stage']==='done') { $queue=[];foreach ($job['results'] as $id=>$row) { if ($row['state']==='failed') { $queue[]=(int)$id;unset($job['results'][$id]); } } if ($queue) { $job['queue']=$queue;$job['next']=0;$job['stage']='running'; } }
            self::save($job);return $job;
        });if ($job===null) { return self::get($token); }return $job;
    }
    private static function result(int $id,string $state,string $code,int $object=0): array { return compact('id','state','code','object'); }
    public static function step(string $token): array {
        $job=self::lock($token,static function () use ($token) { $job=self::get($token); if ($job['stage']!=='running') { return $job; }
            $id=(int)($job['queue'][$job['next']]??0); if (!$id) { $job['stage']='done';self::save($job);return $job; }
            try { $row=self::item($job,$id); }
            catch (\WC_Data_Exception $e) { if ($e->getErrorCode()==='gsm_edition_busy') { return $job+['busy'=>true]; } $row=self::result($id,'failed','write_failed');Schema::audit('bulk_product_failed',(int)$job['target'],$id,['reason'=>get_class($e)]); }
            catch (\Throwable $e) { $row=self::result($id,'failed','write_failed');Schema::audit('bulk_product_failed',(int)$job['target'],$id,['reason'=>get_class($e)]); }
            $job['results'][$id]=$row;++$job['next']; if ($job['next']>=count($job['queue'])) { $job['stage']='done';Stores::purge(); }
            self::save($job);return $job;
        });return $job??(self::get($token)+['busy'=>true]);
    }
    private static function item(array $job,int $id): array {
        $p=wc_get_product($id); if (!$p||$p->is_type('variation')||in_array($p->get_status(),['trash','auto-draft'],true)) { return self::result($id,'failed','invalid'); }
        $brand=BrandScope::product_brand($id);
        if ($job['mode']==='gsm_add_brand') {
            if ($brand!==0||$p->get_meta('_gsm_kind')!=='base') { return self::result($id,'skipped','not_base'); }
            if (!$p->is_type(['simple','variable'])) { return self::result($id,'failed','unsupported'); }
            $created=ProductVersions::ensure_edition($id,(int)$job['target']);
            if ($created['incomplete']) { return self::result($id,'failed','incomplete',$created['id']); }
            if ($created['created']) { Schema::audit('bulk_brand_version_created',(int)$job['target'],$created['id'],['source_product'=>$id]); }
            return self::result($id,$created['created']?'success':'skipped',$created['created']?'created':'exists',$created['id']);
        }
        if (!$brand||$p->get_meta('_gsm_kind')==='base') { return self::result($id,'skipped','public'); }
        if (!isset(Brands::registry()[$brand])||!Identities::accessible(get_current_user_id(),$brand)||!Stores::within($brand,static fn()=>current_user_can('edit_post',$id))) { return self::result($id,'failed','no_permission'); }
        return Stores::within($brand,static function () use ($job,$id,$p) {
            if (!$p->is_type(['simple','variable'])) { return self::result($id,'failed','unsupported'); }
            if ($p->get_meta('_gsm_build_state')) { return self::result($id,'failed','incomplete'); }
            $status=$job['mode']==='gsm_publish'?'publish':'draft';
            if ($p->get_status()===$status) { return self::result($id,'skipped','unchanged',$id); }
            if ($p->get_status()!==($job['expected'][$id]??false)) { return self::result($id,'skipped','conflict',$id); }
            if ($status==='publish') {
                if (!current_user_can('publish_products')) { return self::result($id,'failed','no_permission'); }
                if ($p->get_date_created()&&$p->get_date_created()->getTimestamp()>time()) { $p->set_date_created(time()); }
            }
            $p->set_status($status);if (!$p->save()||get_post_status($id)!==$status) { throw new \RuntimeException('Native status save failed'); }wc_delete_product_transients($id);Schema::audit('bulk_product_status_changed',Brands::id(),$id,['status'=>$status]);return self::result($id,'success',$status==='publish'?'published':'drafted',$id);
        });
    }
    public static function summary(array $job): array { $out=['success'=>0,'skipped'=>0,'failed'=>0];foreach ($job['results'] as $row) { ++$out[$row['state']]; }return $out; }
    public static function url(string $token=''): string { return add_query_arg(array_filter(['post_type'=>'product','page'=>'gsm-bulk-products','gsm_job'=>$token,'gsm_brand'=>Brands::root()?(Brands::query_scope()??0):Brands::id()],static fn($v)=>$v!==''),admin_url('edit.php')); }
    private static function label(string $code): string { return ['created'=>'已创建继承草稿','exists'=>'已有关联版本，未覆盖（含回收站）','not_base'=>'不是公共商品，已跳过','public'=>'公共商品不参与销售状态操作','unchanged'=>'已经是目标状态','conflict'=>'状态已被其他操作修改，未覆盖','invalid'=>'商品不存在、已删除或类型无效','unsupported'=>'当前仅支持简单／可变商品','no_permission'=>'没有该商品的操作权限','incomplete'=>'版本未完整建立，请打开草稿更新后重试','write_failed'=>'处理失败，可单独重试','published'=>'已上架','drafted'=>'已转为草稿'][$code]??'处理中'; }
    private static function title(int $id): string { $brand=BrandScope::product_brand($id);$allowed=Brands::root()||($brand&&Identities::accessible(get_current_user_id(),$brand));return $allowed&&get_post_type($id)==='product'?get_the_title($id).' (#'.$id.')':'商品 #'.$id; }
    private static function rows(array $job): string {
        $html='';foreach ($job['ids'] as $id) { $row=$job['results'][$id]??null;$text=$row?self::label($row['code']):'等待处理';$link='';
            if ($row&&!empty($row['object'])) { $object=(int)$row['object'];$brand=BrandScope::product_brand($object); if (Brands::root()||Identities::accessible(get_current_user_id(),$brand)) { $url=get_post_status($object)==='trash'?add_query_arg(['post_type'=>'product','post_status'=>'trash','gsm_brand'=>$brand],admin_url('edit.php')):NativeProducts::edit_url($object);$link=' <a href="'.esc_url($url).'">查看版本</a>'; } }
            $html.='<tr><td>'.esc_html(self::title((int)$id)).'</td><td>'.esc_html($text).$link.'</td></tr>';
        }return $html;
    }
    public static function ajax(): void {
        $token=is_string($_POST['gsm_job']??null)?$_POST['gsm_job']:'';
        if (!wp_verify_nonce((string)($_POST['nonce']??''),'gsm_bulk_'.$token)) { wp_send_json_error(['message'=>'任务凭证已过期，请刷新页面继续。'],403); }
        try { $action=sanitize_key($_POST['control']??'step');$job=$action==='step'?self::step($token):self::control($token,$action);wp_send_json_success(['stage'=>$job['stage'],'busy'=>!empty($job['busy']),'counts'=>self::summary($job),'processed'=>count($job['results']),'total'=>count($job['ids']),'rows'=>self::rows($job)]); }
        catch (\Throwable $e) { wp_send_json_error(['message'=>'任务不可用或权限已变更，请返回列表重新选择。'],403); }
    }
    public static function render(): void {
        if (!current_user_can('edit_products')) { wp_die('没有商品管理权限。','权限不足',['response'=>403]); }
        echo '<div class="wrap gsm-bulk-page"><h1>批量商品操作</h1>';$error=get_transient('gsm_bulk_error_'.get_current_user_id());delete_transient('gsm_bulk_error_'.get_current_user_id());if ($error) { echo '<p class="gsm-bulk-message">'.esc_html($error).'</p>'; }
        $token=(string)($_GET['gsm_job']??get_user_meta(get_current_user_id(),'_gsm_bulk_job',true));
        try { $job=self::get($token); } catch (\Throwable $e) { echo '<p>从 Products 勾选商品，再选择批量操作。每批最多200件；添加到品牌请先筛选公共商品。</p><a class="button" href="'.esc_url(add_query_arg(['post_type'=>'product','gsm_brand'=>Brands::root()?0:Brands::id()],admin_url('edit.php'))).'">返回 Products</a></div>';return; }
        echo '<h2>'.esc_html(self::MODES[$job['mode']]).'</h2><p>已选择 '.count($job['ids']).' 件商品。任务记录保留24小时，可暂停、刷新或重新进入“Products → 批量任务”继续。</p>';
        if ($job['stage']==='setup') {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="gsm_bulk_start"><input type="hidden" name="gsm_job" value="'.esc_attr($token).'">';wp_nonce_field('gsm_bulk_'.$token);
            if ($job['mode']==='gsm_add_brand') { echo '<p><label>目标品牌 <select name="target_brand" required><option value="">请选择品牌</option>';foreach (Stores::all() as $id=>$name) { echo '<option value="'.(int)$id.'">'.esc_html($name).'</option>'; }echo '</select></label></p><p>仅从公共商品建立继承草稿；已有版本（含回收站）跳过，内容、价格和状态保持。不会复制图片文件或评论。</p>'; }
            else { echo '<p>仅修改所选品牌销售商品的发布状态；公共商品及已经达到目标状态的记录跳过。执行前状态发生变化的商品会跳过。</p>'; }
            submit_button('确认并开始批量处理');echo '</form>';
        } else {
            $counts=self::summary($job);echo '<div id="gsm-bulk-job" data-token="'.esc_attr($token).'" data-nonce="'.esc_attr(wp_create_nonce('gsm_bulk_'.$token)).'" data-url="'.esc_url(admin_url('admin-ajax.php')).'" data-brand="'.(Brands::root()?(Brands::query_scope()??0):Brands::id()).'" data-stage="'.esc_attr($job['stage']).'"><p id="gsm-bulk-status" role="status"></p><progress id="gsm-bulk-progress" max="'.count($job['ids']).'" value="'.count($job['results']).'"></progress><p id="gsm-bulk-counts">成功 '.$counts['success'].'，跳过 '.$counts['skipped'].'，失败 '.$counts['failed'].'</p><button type="button" class="button" data-control="pause">暂停</button> <button type="button" class="button button-primary" data-control="resume">继续处理</button> <button type="button" class="button" data-control="retry">重试失败项</button></div>';
            if ($job['mode']==='gsm_add_brand') { echo '<p>目标品牌：'.esc_html(Brands::name($job['target'])).'。<a href="'.esc_url(Brands::admin_url((int)$job['target'],'edit.php?post_type=product')).'">查看该品牌商品</a>，核对后可批量上架。</p>'; }
        }
        echo '<table class="widefat striped gsm-bulk-results"><thead><tr><th>所选商品</th><th>处理结果</th></tr></thead><tbody id="gsm-bulk-rows">'.self::rows($job).'</tbody></table></div>';
    }
}
