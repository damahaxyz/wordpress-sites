<?php
/**
 * Configurable homepage banner.
 *
 * @package TikoolShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Banner defaults preserve the original homepage when no values have been saved.
 *
 * @return array<string, mixed>
 */
function tikool_home_banner_defaults(): array
{
    return [
        'enabled'          => '1',
        'desktop_image_id' => 0,
        'mobile_image_id'  => 0,
        'desktop_position' => 'center center',
        'mobile_position'  => '61% center',
        'kicker'           => __('Custom keepsakes for the pets you love', 'tikool'),
        'heading'          => __('Turn their favorite photo into something you can keep.', 'tikool'),
        'lead'             => __('Thoughtfully made pet portraits, home gifts, and memorial keepsakes—personalized from your photo in a few simple steps.', 'tikool'),
        'primary_label'    => __('Create your keepsake', 'tikool'),
        'primary_url'      => class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop'),
        'secondary_label'  => __('How it works', 'tikool'),
        'secondary_url'    => '#how-it-works',
        'note'             => __('Upload a photo · Approve your design · Made to order', 'tikool'),
        'text_alignment'   => 'left',
        'overlay_color'    => '#4b2e46',
        'overlay_opacity'  => 82,
        'text_color'       => '#fffaf4',
    ];
}

/**
 * Convert a six-character hex color to RGB channels.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function tikool_home_banner_hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
        return [255, 254, 251];
    }

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Read a saved value while retaining defaults for fields that have never existed.
 *
 * @param int    $post_id Page ID.
 * @param string $key     Field key without prefix.
 * @param mixed  $default Default value.
 * @return mixed
 */
function tikool_home_banner_meta(int $post_id, string $key, $default)
{
    $meta_key = '_tikool_home_banner_' . $key;

    if ($post_id < 1 || ! metadata_exists('post', $post_id, $meta_key)) {
        return $default;
    }

    return get_post_meta($post_id, $meta_key, true);
}

/**
 * Return normalized frontend banner values.
 *
 * @return array<string, mixed>
 */
function tikool_get_home_banner_data(int $post_id = 0): array
{
    $defaults = tikool_home_banner_defaults();
    $values   = [];

    foreach ($defaults as $key => $default) {
        $values[$key] = tikool_home_banner_meta($post_id, $key, $default);
    }

    $default_image = get_template_directory_uri() . '/assets/images/tikool-home-hero-desktop-v1.png';
    $desktop_image = wp_get_attachment_image_url((int) $values['desktop_image_id'], 'full');
    $mobile_image  = wp_get_attachment_image_url((int) $values['mobile_image_id'], 'full');

    $values['desktop_image_url'] = $desktop_image ?: $default_image;
    $values['mobile_image_url']  = $mobile_image ?: $values['desktop_image_url'];
    $values['enabled']           = '0' !== (string) $values['enabled'];

    return $values;
}

/**
 * Add the editor panel only to the page selected as the site homepage.
 */
function tikool_add_home_banner_meta_box(WP_Post $post): void
{
    if ($post->ID < 1 || $post->ID !== (int) get_option('page_on_front')) {
        return;
    }

    add_meta_box(
        'tikool-homepage-banner',
        __('Tikool homepage banner', 'tikool'),
        'tikool_render_home_banner_meta_box',
        'page',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_page', 'tikool_add_home_banner_meta_box');

/**
 * Render an image picker control.
 */
function tikool_home_banner_image_field(string $key, string $label, int $attachment_id, string $help = ''): void
{
    $image_url = $attachment_id > 0 ? wp_get_attachment_image_url($attachment_id, 'medium') : '';
    ?>
    <div class="tikool-banner-field tikool-banner-image-field" data-tikool-media-field data-tikool-media-current-id="<?php echo esc_attr((string) $attachment_id); ?>">
        <label><?php echo esc_html($label); ?></label>
        <div class="tikool-banner-image-preview" data-tikool-media-preview>
            <?php if ($image_url) : ?>
                <img src="<?php echo esc_url($image_url); ?>" alt="">
            <?php endif; ?>
        </div>
        <input type="text" name="tikool_home_banner[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $attachment_id); ?>" data-tikool-media-id hidden readonly>
        <div class="tikool-banner-image-actions">
            <button class="button" type="button" data-tikool-media-select><?php esc_html_e('Choose image', 'tikool'); ?></button>
            <button class="button-link-delete" type="button" data-tikool-media-remove<?php echo $attachment_id < 1 ? ' hidden' : ''; ?>><?php esc_html_e('Remove', 'tikool'); ?></button>
        </div>
        <?php if ($help !== '') : ?>
            <p class="description"><?php echo esc_html($help); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render homepage banner fields.
 */
function tikool_render_home_banner_meta_box(WP_Post $post): void
{
    $values = tikool_get_home_banner_data($post->ID);
    wp_nonce_field('tikool_save_home_banner', 'tikool_home_banner_nonce');
    ?>
    <div class="tikool-banner-settings">
        <p class="tikool-banner-intro"><?php esc_html_e('Control the homepage hero image and all text displayed over it. Mobile image is optional and falls back to the desktop image.', 'tikool'); ?></p>

        <label class="tikool-banner-toggle">
            <input type="hidden" name="tikool_home_banner[enabled]" value="0">
            <input type="checkbox" name="tikool_home_banner[enabled]" value="1" <?php checked((bool) $values['enabled']); ?>>
            <strong><?php esc_html_e('Display homepage banner', 'tikool'); ?></strong>
        </label>

        <div class="tikool-banner-grid tikool-banner-grid--images">
            <?php
            tikool_home_banner_image_field(
                'desktop_image_id',
                __('Desktop background image', 'tikool'),
                (int) $values['desktop_image_id'],
                __('Recommended: landscape image at least 2000px wide.', 'tikool')
            );
            tikool_home_banner_image_field(
                'mobile_image_id',
                __('Mobile background image', 'tikool'),
                (int) $values['mobile_image_id'],
                __('Optional. Recommended: portrait image around 900 × 1200px.', 'tikool')
            );
            ?>
        </div>

        <div class="tikool-banner-grid">
            <p class="tikool-banner-field">
                <label for="tikool-banner-desktop-position"><?php esc_html_e('Desktop image focus', 'tikool'); ?></label>
                <select id="tikool-banner-desktop-position" name="tikool_home_banner[desktop_position]">
                    <?php foreach (tikool_home_banner_position_options() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($values['desktop_position'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="tikool-banner-field">
                <label for="tikool-banner-mobile-position"><?php esc_html_e('Mobile image focus', 'tikool'); ?></label>
                <select id="tikool-banner-mobile-position" name="tikool_home_banner[mobile_position]">
                    <?php foreach (tikool_home_banner_position_options() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($values['mobile_position'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>

        <div class="tikool-banner-grid">
            <?php tikool_home_banner_text_input('kicker', __('Small heading', 'tikool'), (string) $values['kicker']); ?>
            <?php tikool_home_banner_text_input('heading', __('Main heading', 'tikool'), (string) $values['heading']); ?>
        </div>

        <p class="tikool-banner-field">
            <label for="tikool-banner-lead"><?php esc_html_e('Description', 'tikool'); ?></label>
            <textarea id="tikool-banner-lead" name="tikool_home_banner[lead]" rows="3"><?php echo esc_textarea((string) $values['lead']); ?></textarea>
        </p>

        <div class="tikool-banner-grid">
            <?php tikool_home_banner_text_input('primary_label', __('Primary button label', 'tikool'), (string) $values['primary_label']); ?>
            <?php tikool_home_banner_text_input('primary_url', __('Primary button URL', 'tikool'), (string) $values['primary_url'], 'url'); ?>
            <?php tikool_home_banner_text_input('secondary_label', __('Secondary button label', 'tikool'), (string) $values['secondary_label']); ?>
            <?php tikool_home_banner_text_input('secondary_url', __('Secondary button URL', 'tikool'), (string) $values['secondary_url'], 'url'); ?>
        </div>

        <?php tikool_home_banner_text_input('note', __('Bottom note', 'tikool'), (string) $values['note']); ?>

        <div class="tikool-banner-grid tikool-banner-grid--style">
            <p class="tikool-banner-field">
                <label for="tikool-banner-alignment"><?php esc_html_e('Text position', 'tikool'); ?></label>
                <select id="tikool-banner-alignment" name="tikool_home_banner[text_alignment]">
                    <?php foreach (['left' => __('Left', 'tikool'), 'center' => __('Center', 'tikool'), 'right' => __('Right', 'tikool')] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($values['text_alignment'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="tikool-banner-field">
                <label for="tikool-banner-overlay-color"><?php esc_html_e('Overlay color', 'tikool'); ?></label>
                <input id="tikool-banner-overlay-color" type="color" name="tikool_home_banner[overlay_color]" value="<?php echo esc_attr((string) $values['overlay_color']); ?>">
            </p>
            <p class="tikool-banner-field">
                <label for="tikool-banner-opacity"><?php esc_html_e('Overlay strength (%)', 'tikool'); ?></label>
                <input id="tikool-banner-opacity" type="number" min="0" max="95" step="1" name="tikool_home_banner[overlay_opacity]" value="<?php echo esc_attr((string) $values['overlay_opacity']); ?>">
            </p>
            <p class="tikool-banner-field">
                <label for="tikool-banner-text-color"><?php esc_html_e('Text color', 'tikool'); ?></label>
                <input id="tikool-banner-text-color" type="color" name="tikool_home_banner[text_color]" value="<?php echo esc_attr((string) $values['text_color']); ?>">
            </p>
        </div>
    </div>
    <?php
}

/**
 * Render a text or URL input.
 */
function tikool_home_banner_text_input(string $key, string $label, string $value, string $type = 'text'): void
{
    $id = 'tikool-banner-' . str_replace('_', '-', $key);
    ?>
    <p class="tikool-banner-field">
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
        <input id="<?php echo esc_attr($id); ?>" type="<?php echo esc_attr($type); ?>" name="tikool_home_banner[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
    </p>
    <?php
}

/**
 * @return array<string, string>
 */
function tikool_home_banner_position_options(): array
{
    return [
        'left center'   => __('Left', 'tikool'),
        '35% center'    => __('Left center', 'tikool'),
        'center center' => __('Center', 'tikool'),
        '61% center'    => __('Right center', 'tikool'),
        'right center'  => __('Right', 'tikool'),
        'center top'    => __('Top', 'tikool'),
        'center bottom' => __('Bottom', 'tikool'),
    ];
}

/**
 * Save homepage banner fields.
 */
function tikool_save_home_banner(int $post_id): void
{
    if (
        ! isset($_POST['tikool_home_banner_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tikool_home_banner_nonce'])), 'tikool_save_home_banner')
        || ! current_user_can('edit_post', $post_id)
        || wp_is_post_autosave($post_id)
        || wp_is_post_revision($post_id)
        || $post_id !== (int) get_option('page_on_front')
    ) {
        return;
    }

    $raw = isset($_POST['tikool_home_banner']) && is_array($_POST['tikool_home_banner'])
        ? wp_unslash($_POST['tikool_home_banner'])
        : [];

    $positions  = array_keys(tikool_home_banner_position_options());
    $alignments = ['left', 'center', 'right'];
    $text_keys  = ['kicker', 'heading', 'primary_label', 'secondary_label', 'note'];

    update_post_meta($post_id, '_tikool_home_banner_enabled', ! empty($raw['enabled']) ? '1' : '0');
    foreach (['desktop_image_id', 'mobile_image_id'] as $image_key) {
        $meta_key       = '_tikool_home_banner_' . $image_key;
        $submitted_id   = (string) ($raw[$image_key] ?? '');
        $attachment_id  = '' === $submitted_id ? absint(get_post_meta($post_id, $meta_key, true)) : absint($submitted_id);
        update_post_meta($post_id, $meta_key, $attachment_id);
    }

    foreach ($text_keys as $key) {
        update_post_meta($post_id, '_tikool_home_banner_' . $key, sanitize_text_field((string) ($raw[$key] ?? '')));
    }

    update_post_meta($post_id, '_tikool_home_banner_lead', sanitize_textarea_field((string) ($raw['lead'] ?? '')));
    update_post_meta($post_id, '_tikool_home_banner_primary_url', esc_url_raw((string) ($raw['primary_url'] ?? '')));
    update_post_meta($post_id, '_tikool_home_banner_secondary_url', esc_url_raw((string) ($raw['secondary_url'] ?? '')));

    $desktop_position = (string) ($raw['desktop_position'] ?? 'center center');
    $mobile_position  = (string) ($raw['mobile_position'] ?? '61% center');
    $alignment        = (string) ($raw['text_alignment'] ?? 'left');
    update_post_meta($post_id, '_tikool_home_banner_desktop_position', in_array($desktop_position, $positions, true) ? $desktop_position : 'center center');
    update_post_meta($post_id, '_tikool_home_banner_mobile_position', in_array($mobile_position, $positions, true) ? $mobile_position : '61% center');
    update_post_meta($post_id, '_tikool_home_banner_text_alignment', in_array($alignment, $alignments, true) ? $alignment : 'left');

    $overlay_color = sanitize_hex_color((string) ($raw['overlay_color'] ?? '')) ?: '#4b2e46';
    $text_color    = sanitize_hex_color((string) ($raw['text_color'] ?? '')) ?: '#fffaf4';
    $opacity       = min(95, max(0, absint($raw['overlay_opacity'] ?? 82)));
    update_post_meta($post_id, '_tikool_home_banner_overlay_color', $overlay_color);
    update_post_meta($post_id, '_tikool_home_banner_text_color', $text_color);
    update_post_meta($post_id, '_tikool_home_banner_overlay_opacity', $opacity);
}
add_action('save_post_page', 'tikool_save_home_banner');

/**
 * Load media picker assets only while editing the selected homepage.
 */
function tikool_home_banner_admin_assets(string $hook_suffix): void
{
    if (! in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
        return;
    }

    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($post_id < 1 || $post_id !== (int) get_option('page_on_front')) {
        return;
    }

    wp_enqueue_media();

    $script_path = get_template_directory() . '/assets/js/homepage-banner-admin.js';
    $style_path  = get_template_directory() . '/assets/css/homepage-banner-admin.css';
    wp_enqueue_script('tikool-home-banner-admin', get_template_directory_uri() . '/assets/js/homepage-banner-admin.js', [], (string) filemtime($script_path), true);
    wp_localize_script('tikool-home-banner-admin', 'tikoolHomeBannerAdmin', [
        'title'  => __('Choose banner image', 'tikool'),
        'button' => __('Use this image', 'tikool'),
    ]);
    wp_enqueue_style('tikool-home-banner-admin', get_template_directory_uri() . '/assets/css/homepage-banner-admin.css', [], (string) filemtime($style_path));
}
add_action('admin_enqueue_scripts', 'tikool_home_banner_admin_assets');
