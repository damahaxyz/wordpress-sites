<?php
/**
 * Configurable homepage banner.
 *
 * @package TrovesiaShop
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
function trovesia_home_banner_defaults(): array
{
    return [
        'enabled'          => '1',
        'desktop_image_id' => 0,
        'mobile_image_id'  => 0,
        'desktop_position' => 'center center',
        'mobile_position'  => '61% center',
        'kicker'           => __('Botanical hair care, thoughtfully chosen', 'trovesia'),
        'heading'          => __('Rooted in ritual. Made for real life.', 'trovesia'),
        'lead'             => __('Discover uncomplicated scalp and hair care built around nourishing botanicals, honest guidance and a routine you will actually enjoy.', 'trovesia'),
        'primary_label'    => __('Explore the collection', 'trovesia'),
        'primary_url'      => class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop'),
        'secondary_label'  => __('Find your ritual', 'trovesia'),
        'secondary_url'    => '#ritual',
        'note'             => __('No complicated routines. No pressure-led promises.', 'trovesia'),
        'text_alignment'   => 'left',
        'overlay_color'    => '#102c25',
        'overlay_opacity'  => 82,
        'text_color'       => '#fffefb',
    ];
}

/**
 * Convert a six-character hex color to RGB channels.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function trovesia_home_banner_hex_to_rgb(string $hex): array
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
function trovesia_home_banner_meta(int $post_id, string $key, $default)
{
    $meta_key = '_trovesia_home_banner_' . $key;

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
function trovesia_get_home_banner_data(int $post_id = 0): array
{
    $defaults = trovesia_home_banner_defaults();
    $values   = [];

    foreach ($defaults as $key => $default) {
        $values[$key] = trovesia_home_banner_meta($post_id, $key, $default);
    }

    $default_image = get_template_directory_uri() . '/assets/images/trovesia-product-hero-v2.webp';
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
function trovesia_add_home_banner_meta_box(WP_Post $post): void
{
    if ($post->ID < 1 || $post->ID !== (int) get_option('page_on_front')) {
        return;
    }

    add_meta_box(
        'trovesia-homepage-banner',
        __('Trovesia homepage banner', 'trovesia'),
        'trovesia_render_home_banner_meta_box',
        'page',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_page', 'trovesia_add_home_banner_meta_box');

/**
 * Render an image picker control.
 */
function trovesia_home_banner_image_field(string $key, string $label, int $attachment_id, string $help = ''): void
{
    $image_url = $attachment_id > 0 ? wp_get_attachment_image_url($attachment_id, 'medium') : '';
    ?>
    <div class="trovesia-banner-field trovesia-banner-image-field" data-trovesia-media-field data-trovesia-media-current-id="<?php echo esc_attr((string) $attachment_id); ?>">
        <label><?php echo esc_html($label); ?></label>
        <div class="trovesia-banner-image-preview" data-trovesia-media-preview>
            <?php if ($image_url) : ?>
                <img src="<?php echo esc_url($image_url); ?>" alt="">
            <?php endif; ?>
        </div>
        <input type="text" name="trovesia_home_banner[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $attachment_id); ?>" data-trovesia-media-id hidden readonly>
        <div class="trovesia-banner-image-actions">
            <button class="button" type="button" data-trovesia-media-select><?php esc_html_e('Choose image', 'trovesia'); ?></button>
            <button class="button-link-delete" type="button" data-trovesia-media-remove<?php echo $attachment_id < 1 ? ' hidden' : ''; ?>><?php esc_html_e('Remove', 'trovesia'); ?></button>
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
function trovesia_render_home_banner_meta_box(WP_Post $post): void
{
    $values = trovesia_get_home_banner_data($post->ID);
    wp_nonce_field('trovesia_save_home_banner', 'trovesia_home_banner_nonce');
    ?>
    <div class="trovesia-banner-settings">
        <p class="trovesia-banner-intro"><?php esc_html_e('Control the homepage hero image and all text displayed over it. Mobile image is optional and falls back to the desktop image.', 'trovesia'); ?></p>

        <label class="trovesia-banner-toggle">
            <input type="hidden" name="trovesia_home_banner[enabled]" value="0">
            <input type="checkbox" name="trovesia_home_banner[enabled]" value="1" <?php checked((bool) $values['enabled']); ?>>
            <strong><?php esc_html_e('Display homepage banner', 'trovesia'); ?></strong>
        </label>

        <div class="trovesia-banner-grid trovesia-banner-grid--images">
            <?php
            trovesia_home_banner_image_field(
                'desktop_image_id',
                __('Desktop background image', 'trovesia'),
                (int) $values['desktop_image_id'],
                __('Recommended: landscape image at least 2000px wide.', 'trovesia')
            );
            trovesia_home_banner_image_field(
                'mobile_image_id',
                __('Mobile background image', 'trovesia'),
                (int) $values['mobile_image_id'],
                __('Optional. Recommended: portrait image around 900 × 1200px.', 'trovesia')
            );
            ?>
        </div>

        <div class="trovesia-banner-grid">
            <p class="trovesia-banner-field">
                <label for="trovesia-banner-desktop-position"><?php esc_html_e('Desktop image focus', 'trovesia'); ?></label>
                <select id="trovesia-banner-desktop-position" name="trovesia_home_banner[desktop_position]">
                    <?php foreach (trovesia_home_banner_position_options() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($values['desktop_position'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="trovesia-banner-field">
                <label for="trovesia-banner-mobile-position"><?php esc_html_e('Mobile image focus', 'trovesia'); ?></label>
                <select id="trovesia-banner-mobile-position" name="trovesia_home_banner[mobile_position]">
                    <?php foreach (trovesia_home_banner_position_options() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($values['mobile_position'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>

        <div class="trovesia-banner-grid">
            <?php trovesia_home_banner_text_input('kicker', __('Small heading', 'trovesia'), (string) $values['kicker']); ?>
            <?php trovesia_home_banner_text_input('heading', __('Main heading', 'trovesia'), (string) $values['heading']); ?>
        </div>

        <p class="trovesia-banner-field">
            <label for="trovesia-banner-lead"><?php esc_html_e('Description', 'trovesia'); ?></label>
            <textarea id="trovesia-banner-lead" name="trovesia_home_banner[lead]" rows="3"><?php echo esc_textarea((string) $values['lead']); ?></textarea>
        </p>

        <div class="trovesia-banner-grid">
            <?php trovesia_home_banner_text_input('primary_label', __('Primary button label', 'trovesia'), (string) $values['primary_label']); ?>
            <?php trovesia_home_banner_text_input('primary_url', __('Primary button URL', 'trovesia'), (string) $values['primary_url'], 'url'); ?>
            <?php trovesia_home_banner_text_input('secondary_label', __('Secondary button label', 'trovesia'), (string) $values['secondary_label']); ?>
            <?php trovesia_home_banner_text_input('secondary_url', __('Secondary button URL', 'trovesia'), (string) $values['secondary_url'], 'url'); ?>
        </div>

        <?php trovesia_home_banner_text_input('note', __('Bottom note', 'trovesia'), (string) $values['note']); ?>

        <div class="trovesia-banner-grid trovesia-banner-grid--style">
            <p class="trovesia-banner-field">
                <label for="trovesia-banner-alignment"><?php esc_html_e('Text position', 'trovesia'); ?></label>
                <select id="trovesia-banner-alignment" name="trovesia_home_banner[text_alignment]">
                    <?php foreach (['left' => __('Left', 'trovesia'), 'center' => __('Center', 'trovesia'), 'right' => __('Right', 'trovesia')] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($values['text_alignment'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="trovesia-banner-field">
                <label for="trovesia-banner-overlay-color"><?php esc_html_e('Overlay color', 'trovesia'); ?></label>
                <input id="trovesia-banner-overlay-color" type="color" name="trovesia_home_banner[overlay_color]" value="<?php echo esc_attr((string) $values['overlay_color']); ?>">
            </p>
            <p class="trovesia-banner-field">
                <label for="trovesia-banner-opacity"><?php esc_html_e('Overlay strength (%)', 'trovesia'); ?></label>
                <input id="trovesia-banner-opacity" type="number" min="0" max="95" step="1" name="trovesia_home_banner[overlay_opacity]" value="<?php echo esc_attr((string) $values['overlay_opacity']); ?>">
            </p>
            <p class="trovesia-banner-field">
                <label for="trovesia-banner-text-color"><?php esc_html_e('Text color', 'trovesia'); ?></label>
                <input id="trovesia-banner-text-color" type="color" name="trovesia_home_banner[text_color]" value="<?php echo esc_attr((string) $values['text_color']); ?>">
            </p>
        </div>
    </div>
    <?php
}

/**
 * Render a text or URL input.
 */
function trovesia_home_banner_text_input(string $key, string $label, string $value, string $type = 'text'): void
{
    $id = 'trovesia-banner-' . str_replace('_', '-', $key);
    ?>
    <p class="trovesia-banner-field">
        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
        <input id="<?php echo esc_attr($id); ?>" type="<?php echo esc_attr($type); ?>" name="trovesia_home_banner[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
    </p>
    <?php
}

/**
 * @return array<string, string>
 */
function trovesia_home_banner_position_options(): array
{
    return [
        'left center'   => __('Left', 'trovesia'),
        '35% center'    => __('Left center', 'trovesia'),
        'center center' => __('Center', 'trovesia'),
        '61% center'    => __('Right center', 'trovesia'),
        'right center'  => __('Right', 'trovesia'),
        'center top'    => __('Top', 'trovesia'),
        'center bottom' => __('Bottom', 'trovesia'),
    ];
}

/**
 * Save homepage banner fields.
 */
function trovesia_save_home_banner(int $post_id): void
{
    if (
        ! isset($_POST['trovesia_home_banner_nonce'])
        || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['trovesia_home_banner_nonce'])), 'trovesia_save_home_banner')
        || ! current_user_can('edit_post', $post_id)
        || wp_is_post_autosave($post_id)
        || wp_is_post_revision($post_id)
        || $post_id !== (int) get_option('page_on_front')
    ) {
        return;
    }

    $raw = isset($_POST['trovesia_home_banner']) && is_array($_POST['trovesia_home_banner'])
        ? wp_unslash($_POST['trovesia_home_banner'])
        : [];

    $positions  = array_keys(trovesia_home_banner_position_options());
    $alignments = ['left', 'center', 'right'];
    $text_keys  = ['kicker', 'heading', 'primary_label', 'secondary_label', 'note'];

    update_post_meta($post_id, '_trovesia_home_banner_enabled', ! empty($raw['enabled']) ? '1' : '0');
    foreach (['desktop_image_id', 'mobile_image_id'] as $image_key) {
        $meta_key       = '_trovesia_home_banner_' . $image_key;
        $submitted_id   = (string) ($raw[$image_key] ?? '');
        $attachment_id  = '' === $submitted_id ? absint(get_post_meta($post_id, $meta_key, true)) : absint($submitted_id);
        update_post_meta($post_id, $meta_key, $attachment_id);
    }

    foreach ($text_keys as $key) {
        update_post_meta($post_id, '_trovesia_home_banner_' . $key, sanitize_text_field((string) ($raw[$key] ?? '')));
    }

    update_post_meta($post_id, '_trovesia_home_banner_lead', sanitize_textarea_field((string) ($raw['lead'] ?? '')));
    update_post_meta($post_id, '_trovesia_home_banner_primary_url', esc_url_raw((string) ($raw['primary_url'] ?? '')));
    update_post_meta($post_id, '_trovesia_home_banner_secondary_url', esc_url_raw((string) ($raw['secondary_url'] ?? '')));

    $desktop_position = (string) ($raw['desktop_position'] ?? 'center center');
    $mobile_position  = (string) ($raw['mobile_position'] ?? '61% center');
    $alignment        = (string) ($raw['text_alignment'] ?? 'left');
    update_post_meta($post_id, '_trovesia_home_banner_desktop_position', in_array($desktop_position, $positions, true) ? $desktop_position : 'center center');
    update_post_meta($post_id, '_trovesia_home_banner_mobile_position', in_array($mobile_position, $positions, true) ? $mobile_position : '61% center');
    update_post_meta($post_id, '_trovesia_home_banner_text_alignment', in_array($alignment, $alignments, true) ? $alignment : 'left');

    $overlay_color = sanitize_hex_color((string) ($raw['overlay_color'] ?? '')) ?: '#102c25';
    $text_color    = sanitize_hex_color((string) ($raw['text_color'] ?? '')) ?: '#fffefb';
    $opacity       = min(95, max(0, absint($raw['overlay_opacity'] ?? 82)));
    update_post_meta($post_id, '_trovesia_home_banner_overlay_color', $overlay_color);
    update_post_meta($post_id, '_trovesia_home_banner_text_color', $text_color);
    update_post_meta($post_id, '_trovesia_home_banner_overlay_opacity', $opacity);
}
add_action('save_post_page', 'trovesia_save_home_banner');

/**
 * Load media picker assets only while editing the selected homepage.
 */
function trovesia_home_banner_admin_assets(string $hook_suffix): void
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
    wp_enqueue_script('trovesia-home-banner-admin', get_template_directory_uri() . '/assets/js/homepage-banner-admin.js', [], (string) filemtime($script_path), true);
    wp_localize_script('trovesia-home-banner-admin', 'trovesiaHomeBannerAdmin', [
        'title'  => __('Choose banner image', 'trovesia'),
        'button' => __('Use this image', 'trovesia'),
    ]);
    wp_enqueue_style('trovesia-home-banner-admin', get_template_directory_uri() . '/assets/css/homepage-banner-admin.css', [], (string) filemtime($style_path));
}
add_action('admin_enqueue_scripts', 'trovesia_home_banner_admin_assets');
