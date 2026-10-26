<?php
/**
 * Non-billable integration check for the asynchronous portrait job lifecycle.
 * Run with: wp eval-file scripts/verify-ai-async-jobs.php
 */

use Tikool\FrameCustomizer\AI_Jobs;
use Tikool\FrameCustomizer\AI_Styles;

if (! defined('ABSPATH')) {
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$styles = AI_Styles::instance();
$ensure_session = new ReflectionMethod(AI_Styles::class, 'ensure_guest_session');
$ensure_session->setAccessible(true);
$ensure_session->invoke($styles);
if (function_exists('WC') && WC()->session && ! is_user_logged_in()) {
    $assert(WC()->session->has_session(), 'Guest WooCommerce session was not persisted.');
}
$directory = WP_CONTENT_DIR . '/uploads/tikool-customer-photos';
wp_mkdir_p($directory);
$create_source = static function (string $suffix) use ($directory): string {
    $path = trailingslashit($directory) . 'verification-' . $suffix . '-ai-job-source.jpg';
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, 224, 190, 170));
    imagejpeg($image, $path, 90);
    imagedestroy($image);
    return $path;
};
$create_job = static function (string $suffix, string $source): int {
    $unique = hash('sha256', wp_generate_uuid4() . $suffix);
    return AI_Jobs::create([
        'owner_hash' => hash('sha256', 'verification'),
        'fingerprint' => $unique,
        'dedupe_key' => $unique,
        'public_token' => bin2hex(random_bytes(24)),
        'product_id' => 31,
        'variation_id' => 0,
        'style_key' => 'watercolor',
        'feature' => 'pet_portrait_watercolor',
        'rate_hash' => md5('verification'),
        'source_path' => $source,
    ]);
};

$source_success = $create_source('success');
$job_success = $create_job('success', $source_success);
$assert($job_success > 0, 'Success job could not be created.');
$mock_success = static function ($preempt, array $args, string $url) use ($job_success) {
    unset($preempt, $args);
    if (str_contains($url, 'ark.cn-beijing.volces.com')) {
        $job = AI_Jobs::find($job_success);
        if (($job['status'] ?? '') !== 'requesting') {
            return new WP_Error('verification_status', 'Job was not requesting before the provider call.');
        }
        return [
            'headers' => ['x-request-id' => 'verification-success'],
            'body' => wp_json_encode(['data' => [['url' => 'https://example.com/verification.jpg']]]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://example.com/verification.jpg') {
        $image = imagecreatetruecolor(12, 12);
        ob_start();
        imagejpeg($image, null, 90);
        $body = (string) ob_get_clean();
        imagedestroy($image);
        return [
            'headers' => ['content-length' => (string) strlen($body)],
            'body' => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    return false;
};
add_filter('pre_http_request', $mock_success, 10, 3);
AI_Styles::instance()->process_job($job_success);
remove_filter('pre_http_request', $mock_success, 10);
$success = AI_Jobs::find($job_success);
$assert(($success['status'] ?? '') === 'success', 'Job did not transition to success.');
$assert(is_file((string) ($success['result_path'] ?? '')), 'Successful job result was not stored.');
$payload_method = new ReflectionMethod(AI_Styles::class, 'job_payload');
$payload_method->setAccessible(true);
$payload = $payload_method->invoke($styles, $success);
$access_token = (string) ($payload['jobAccessToken'] ?? '');
$assert(preg_match('/^[a-f0-9]{64}$/', $access_token) === 1, 'Signed job access token was not returned.');
$assert(str_contains((string) ($payload['previewUrl'] ?? ''), 'job_access_token='), 'Preview URL did not include the signed access token.');
$access_method = new ReflectionMethod(AI_Styles::class, 'can_access_job');
$access_method->setAccessible(true);
$assert($access_method->invoke($styles, $success, $access_token) === true, 'Valid signed job token was rejected.');
$assert($access_method->invoke($styles, $success, str_repeat('0', 64)) === false, 'Forged job token was accepted.');
$cache_method = new ReflectionMethod(AI_Styles::class, 'record_cache_hit');
$cache_method->invoke(AI_Styles::instance(), $success);
global $wpdb;
$log_table = \Tikool\AI\Generation_Log::table_name();
$cache_log = $wpdb->get_row("SELECT status, context_json FROM {$log_table} ORDER BY id DESC LIMIT 1", ARRAY_A);
$assert(($cache_log['status'] ?? '') === 'cached', 'Cache hit was not recorded with cached status.');
$assert(str_contains((string) ($cache_log['context_json'] ?? ''), (string) $success['dedupe_key']), 'Cache key was not saved in the cache-hit record.');
AI_Styles::instance()->delete_job($job_success);

$source_failure = $create_source('failure');
$job_failure = $create_job('failure', $source_failure);
$assert($job_failure > 0, 'Failure job could not be created.');
$mock_failure = static function ($preempt, array $args, string $url) {
    unset($preempt, $args);
    if (str_contains($url, 'ark.cn-beijing.volces.com')) {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 180001 milliseconds');
    }
    return false;
};
add_filter('pre_http_request', $mock_failure, 10, 3);
AI_Styles::instance()->process_job($job_failure);
remove_filter('pre_http_request', $mock_failure, 10);
$failure = AI_Jobs::find($job_failure);
$assert(($failure['status'] ?? '') === 'failed', 'Job did not transition to failed.');

$latest_error = (string) $wpdb->get_var("SELECT error_message FROM {$log_table} ORDER BY id DESC LIMIT 1");
$assert(str_contains($latest_error, 'cURL error 28'), 'The low-level transport cause was not saved in the Tikool AI log.');
AI_Styles::instance()->delete_job($job_failure);

echo "PASS: async lifecycle, cache-hit metadata, and transport-cause logging verified.\n";
