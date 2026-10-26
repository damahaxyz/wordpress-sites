<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = $root . '/plugin/tikool-memory-page';
$scene_slugs = ['cozy-home', 'modern-gallery', 'joyful-days', 'gentle-memorial'];
$required = [
    'tikool-memory-page.php',
    'includes/class-plugin.php',
    'includes/class-settings.php',
    'includes/class-memory-pages.php',
    'includes/class-claims.php',
    'assets/css/claim.css',
    'includes/class-storage.php',
    'includes/class-tasks.php',
    'includes/class-git-publisher.php',
    'includes/class-account.php',
    'templates/scenes/garden/scene.json',
    'templates/scenes/garden/index.html',
];
foreach ($scene_slugs as $scene_slug) {
    $required[] = "templates/scenes/{$scene_slug}/scene.json";
    $required[] = "templates/scenes/{$scene_slug}/index.html";
}

foreach ($required as $relative) {
    if (! is_file($plugin . '/' . $relative)) {
        fwrite(STDERR, "Missing {$relative}\n");
        exit(1);
    }
}

$expected_order = 10;
foreach ($scene_slugs as $scene_slug) {
    $scene = json_decode((string) file_get_contents($plugin . "/templates/scenes/{$scene_slug}/scene.json"), true);
    if (! is_array($scene) || empty($scene['label']) || ($scene['order'] ?? 0) !== $expected_order || ! empty($scene['hidden'])) {
        fwrite(STDERR, "Invalid {$scene_slug} scene metadata\n");
        exit(1);
    }
    $expected_order += 10;

    $html = (string) file_get_contents($plugin . "/templates/scenes/{$scene_slug}/index.html");
    foreach (['__TIKOOL_MEMORY_MANIFEST_JSON__', 'window.__TIKOOL_MEMORY_MANIFEST__', 'm.media?.photos', 'timeline', '<video controls', '<audio controls'] as $needle) {
        if (! str_contains($html, $needle)) {
            fwrite(STDERR, "{$scene_slug} scene is missing {$needle}\n");
            exit(1);
        }
    }
    if (str_contains($html, 'fetch("manifest.json"') || preg_match('/<(?:video|audio)[^>]+autoplay/i', $html)) {
        fwrite(STDERR, "{$scene_slug} must use its embedded manifest and must not autoplay media\n");
        exit(1);
    }
}

$legacy_scene = json_decode((string) file_get_contents($plugin . '/templates/scenes/garden/scene.json'), true);
if (! is_array($legacy_scene) || empty($legacy_scene['hidden'])) {
    fwrite(STDERR, "The legacy garden scene must remain hidden but available\n");
    exit(1);
}

$storage = (string) file_get_contents($plugin . '/includes/class-storage.php');
foreach (['_tikool_memory_bucket_git_url', '/var/lib/tikool-memory-tasks', 'payload.json', 'template.html', 'function stage('] as $needle) {
    if (! str_contains($storage, $needle)) {
        fwrite(STDERR, "Storage is missing {$needle}\n");
        exit(1);
    }
}
foreach (["'cozy-home'", 'scenes(true)', "! empty(\$config['hidden'])", "\$config['order']"] as $needle) {
    if (! str_contains($storage, $needle)) {
        fwrite(STDERR, "Storage is missing scene behavior {$needle}\n");
        exit(1);
    }
}
foreach (['PHOTO_MAX_COUNT = 30', 'VIDEO_MAX_COUNT = 5', 'AUDIO_MAX_COUNT = 3', 'tikool_memory_media_count'] as $needle) {
    if (! str_contains($storage, $needle)) {
        fwrite(STDERR, "Storage is missing media limit {$needle}\n");
        exit(1);
    }
}
foreach (['media_order', 'apply_media_order', "'existing:'", "preg_match('/^new:"] as $needle) {
    if (! str_contains($storage, $needle)) {
        fwrite(STDERR, "Storage is missing persistent media ordering {$needle}\n");
        exit(1);
    }
}

$account = (string) file_get_contents($plugin . '/includes/class-account.php');
foreach (['tikool-memory-preview', 'Live preview', 'wc_add_notice('] as $needle) {
    if (str_contains($account, $needle)) {
        fwrite(STDERR, "Account editor must not contain {$needle}\n");
        exit(1);
    }
}
foreach (['set_transient(', 'wp_send_json_success(', 'X-Requested-With'] as $needle) {
    if (! str_contains($account, $needle) && $needle !== 'X-Requested-With') {
        fwrite(STDERR, "Account save flow is missing {$needle}\n");
        exit(1);
    }
}
$account_js = (string) file_get_contents($plugin . '/assets/js/account.js');
if (! str_contains($account_js, 'X-Requested-With')) {
    fwrite(STDERR, "Account uploader is missing its XHR response contract\n");
    exit(1);
}
foreach (['data-photo-manager', 'data-existing-photo-card', 'data-photo-add-card', 'data-video-manager', 'data-existing-video-card', 'data-video-add-card', 'data-audio-manager', 'data-existing-audio-card', 'data-audio-add-card'] as $needle) {
    if (! str_contains($account, $needle)) {
        fwrite(STDERR, "Account media cards are missing {$needle}\n");
        exit(1);
    }
}
foreach (['DataTransfer', 'URL.createObjectURL', 'is-pending-removal', 'currentCount()', 'data-new-photo-id', 'initVideoManager', 'data-new-video-id', 'data-remove-existing-video', 'initAudioManager', 'data-new-audio-id', 'data-remove-existing-audio', 'initMediaOrdering', 'data-sortable-media-card', 'memory[media_order]'] as $needle) {
    if (! str_contains($account_js, $needle)) {
        fwrite(STDERR, "Account media JavaScript is missing {$needle}\n");
        exit(1);
    }
}
$account_css = (string) file_get_contents($plugin . '/assets/css/account.css');
foreach (['tikool-memory-video-grid', 'tikool-memory-video-card__preview video', 'tikool-memory-video-add-card', 'tikool-memory-audio-grid', 'tikool-memory-audio-card__preview audio', 'tikool-memory-audio-add-card', 'tikool-memory-photo-card__drag', 'is-dragging'] as $needle) {
    if (! str_contains($account_css, $needle)) {
        fwrite(STDERR, "Account media CSS is missing {$needle}\n");
        exit(1);
    }
}
if (! str_contains($account_js, 'form.getAttribute("action")') || str_contains($account_js, 'form.action')) {
    fwrite(STDERR, "Account uploader must avoid DOM clobbering from the hidden action input\n");
    exit(1);
}
if (! str_contains($account, 'Your changes were saved. The system is publishing your Memory Page. Please wait.')
    || str_contains($account, 'Publishing task #%1$d')
    || str_contains($account, 'is now queued.')) {
    fwrite(STDERR, "Customer publishing notice must be singular and must not expose task details\n");
    exit(1);
}

$claims = (string) file_get_contents($plugin . '/includes/class-claims.php');
foreach (['woocommerce_email_after_order_table', 'customer_processing_order', 'customer_completed_order', '_tikool_memory_claim_token_hash', '_tikool_memory_claim_expires_at', 'wp_login_form(', 'wc_create_new_customer(', 'wc_set_customer_auth_cookie(', 'post_author', 'delete_meta_data(', "wc_get_endpoint_url('memory-pages'", 'Referrer-Policy: no-referrer'] as $needle) {
    if (! str_contains($claims, $needle)) {
        fwrite(STDERR, "Guest claim flow is missing {$needle}\n");
        exit(1);
    }
}
if (str_contains($claims, "update_meta_data('_tikool_memory_claim_token',")) {
    fwrite(STDERR, "Guest claim tokens must not be stored in plain text\n");
    exit(1);
}

$memory_pages = (string) file_get_contents($plugin . '/includes/class-memory-pages.php');
foreach (["return \$this->cart_contains_memory_page() ? false : \$required;", "'_tikool_memory_claim_status'", "'unclaimed'", 'checkout_guest_message'] as $needle) {
    if (! str_contains($memory_pages, $needle)) {
        fwrite(STDERR, "Memory Page guest checkout is missing {$needle}\n");
        exit(1);
    }
}
if (! str_contains($memory_pages, "return untrailingslashit(\$base) . '/' . rawurlencode(\$slug);")
    || str_contains($memory_pages, "rawurlencode(\$slug) . '.html'")) {
    fwrite(STDERR, "Public Memory Page URLs must use Cloudflare's extensionless canonical path\n");
    exit(1);
}
if (! str_contains($storage, "\$slug . '.html'")) {
    fwrite(STDERR, "Physical Memory Page files must retain their .html extension\n");
    exit(1);
}

$tasks = (string) file_get_contents($plugin . '/includes/class-tasks.php');
foreach (['tikool_memory_process_task', "'queued'", "'running'", "'completed'", "'failed'", 'publish_task('] as $needle) {
    if (! str_contains($tasks, $needle)) {
        fwrite(STDERR, "Task manager is missing {$needle}\n");
        exit(1);
    }
}

$settings = (string) file_get_contents($plugin . '/includes/class-settings.php');
foreach (['bucket_git_url', 'tikool-memory-pages-bucket1.git', 'bucket_from_git_url'] as $needle) {
    if (! str_contains($settings, $needle)) {
        fwrite(STDERR, "Settings are missing {$needle}\n");
        exit(1);
    }
}

$publisher = (string) file_get_contents($root . '/scripts/memory-page-publisher.py');
foreach (['BUCKET_GIT_PATTERN', 'sparse-checkout', 'public/{slug}', 'bucket-checkout-', 'shutil.rmtree(checkout', 'bucket_commit', 'html_commit', 'PHOTO_MAX_SIZE', 'VIDEO_DIRECT_MAX_SIZE', 'AUDIO_MAX_SIZE', 'process_staged_media', 'FFMPEG_BINARY', 'FFPROBE_BINARY'] as $needle) {
    if (! str_contains($publisher, $needle)) {
        fwrite(STDERR, "Publisher is missing {$needle}\n");
        exit(1);
    }
}
if (! str_contains($publisher, 'f"{public_path}/{slug}.html"')) {
    fwrite(STDERR, "Publisher must keep the physical Memory Page .html filename\n");
    exit(1);
}

$compose = (string) file_get_contents($root . '/compose.yaml');
if (! str_contains($compose, '/var/lib/tikool-memory-pages:ro')
    || ! str_contains($compose, '/var/lib/tikool-memory-tasks')
    || ! str_contains($compose, '/var/run/tikool-memory-publisher')) {
    fwrite(STDERR, "Memory Page repository or publisher socket is not mounted into WordPress\n");
    exit(1);
}

echo "Memory Page structure verified.\n";
