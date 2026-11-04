<?php
/** Creates and removes a disposable image/folder; no existing media is changed. */
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$attachment = 0;
$term = 0;
$temporary = wp_tempnam('trovesia-media-probe.png');
try {
    if (! taxonomy_exists('wpmf-category')) {
        throw new RuntimeException('WP Media Folder taxonomy is unavailable');
    }
    $result = wp_insert_term('Temporary security media probe ' . bin2hex(random_bytes(6)), 'wpmf-category');
    if (is_wp_error($result)) {
        throw new RuntimeException('Could not create a disposable media folder');
    }
    $term = (int) $result['term_id'];
    file_put_contents($temporary, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR1sAAAAASUVORK5CYII='));
    $result = media_handle_sideload(['name' => 'trovesia-temporary-media-probe.png', 'tmp_name' => $temporary], 0, 'Temporary media permission probe');
    if (is_wp_error($result)) {
        throw new RuntimeException('Media image import failed: ' . $result->get_error_code());
    }
    $attachment = (int) $result;
    $result = wp_set_object_terms($attachment, [$term], 'wpmf-category');
    if (is_wp_error($result) || ! has_term($term, 'wpmf-category', $attachment) || ! is_file(get_attached_file($attachment))) {
        throw new RuntimeException('Media folder assignment or image persistence failed');
    }
    WP_CLI::success('WP Media Folder taxonomy, folder creation, image import and folder assignment work as web UID 33.');
} finally {
    if ($attachment) {
        wp_delete_attachment($attachment, true);
    }
    if ($term) {
        wp_delete_term($term, 'wpmf-category');
    }
    if (is_file($temporary)) {
        unlink($temporary);
    }
}
