<?php
/**
 * WooCommerce My Account list and editor.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class Account
{
    private static ?self $instance = null;

    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted || ! class_exists('WooCommerce')) {
            return;
        }
        $this->booted = true;

        add_action('init', [self::class, 'register_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'menu_items']);
        add_action('woocommerce_account_memory-pages_endpoint', [$this, 'render_endpoint']);
        add_filter('woocommerce_endpoint_memory-pages_title', [$this, 'endpoint_title']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_tikool_save_memory_page', [$this, 'save']);
        add_action('admin_post_tikool_memory_media', [$this, 'serve_media']);
    }

    public static function register_endpoint(): void
    {
        add_rewrite_endpoint('memory-pages', EP_ROOT | EP_PAGES);
    }

    /**
     * @param array<string, string> $items Account menu items.
     * @return array<string, string>
     */
    public function menu_items(array $items): array
    {
        $result = [];
        foreach ($items as $key => $label) {
            $result[$key] = $label;
            if ($key === 'orders') {
                $result['memory-pages'] = __('Memory Pages', 'tikool-memory-page');
            }
        }
        if (! isset($result['memory-pages'])) {
            $result['memory-pages'] = __('Memory Pages', 'tikool-memory-page');
        }

        return $result;
    }

    public function endpoint_title(string $title): string
    {
        return __('Memory Pages', 'tikool-memory-page');
    }

    public function enqueue_assets(): void
    {
        global $wp;
        if (! is_account_page() || ! isset($wp->query_vars['memory-pages'])) {
            return;
        }

        $css = TIKOOL_MEMORY_PAGE_DIR . 'assets/css/account.css';
        $js = TIKOOL_MEMORY_PAGE_DIR . 'assets/js/account.js';
        wp_enqueue_style('tikool-memory-page-account', TIKOOL_MEMORY_PAGE_URL . 'assets/css/account.css', [], is_file($css) ? (string) filemtime($css) : TIKOOL_MEMORY_PAGE_VERSION);
        wp_enqueue_script('tikool-memory-page-account', TIKOOL_MEMORY_PAGE_URL . 'assets/js/account.js', [], is_file($js) ? (string) filemtime($js) : TIKOOL_MEMORY_PAGE_VERSION, true);
        wp_localize_script('tikool-memory-page-account', 'tikoolMemoryPage', [
            'removeLabel'        => __('Remove', 'tikool-memory-page'),
            'photoSizeError'     => __('Each photo must be 2 MB or smaller.', 'tikool-memory-page'),
            'photoLimitError'    => sprintf(__('A Memory Page can contain up to %d photos. Remove another photo before adding more.', 'tikool-memory-page'), Storage::PHOTO_MAX_COUNT),
            'videoLimitError'    => sprintf(__('A Memory Page can contain up to %d videos. Remove another video before adding more.', 'tikool-memory-page'), Storage::VIDEO_MAX_COUNT),
            'audioLimitError'    => sprintf(__('A Memory Page can contain up to %d audio clips. Remove another audio clip before adding more.', 'tikool-memory-page'), Storage::AUDIO_MAX_COUNT),
            'photoCountSuffix'   => __('photos', 'tikool-memory-page'),
            'videoCountSuffix'   => __('videos', 'tikool-memory-page'),
            'audioCountSuffix'   => __('audio clips', 'tikool-memory-page'),
            'newPhotoLabel'      => __('New', 'tikool-memory-page'),
            'newVideoLabel'      => __('New', 'tikool-memory-page'),
            'newAudioLabel'      => __('New', 'tikool-memory-page'),
            'addPhotoLabel'      => __('Add photo', 'tikool-memory-page'),
            'removeLabel'        => __('Remove', 'tikool-memory-page'),
            'undoRemoveLabel'    => __('Undo remove', 'tikool-memory-page'),
            'willRemoveLabel'    => __('Will be removed when you save', 'tikool-memory-page'),
            'dragLabel'          => __('Drag to reorder. You can also use the arrow keys.', 'tikool-memory-page'),
            'videoSizeError'     => __('Each source video must be 90 MB or smaller.', 'tikool-memory-page'),
            'audioSizeError'     => __('Each audio file must be 20 MB or smaller.', 'tikool-memory-page'),
            'audioDurationError' => __('Each audio file must be no longer than 1 minute.', 'tikool-memory-page'),
            'metadataError'      => __('This media file could not be read. Please choose another file.', 'tikool-memory-page'),
            'checkingLabel'      => __('Checking media…', 'tikool-memory-page'),
            'uploadingLabel'     => __('Uploading your media…', 'tikool-memory-page'),
            'preparingLabel'     => __('Upload complete. Preparing your Memory Page for publishing…', 'tikool-memory-page'),
            'completedLabel'     => __('Saved. Opening your Memory Page…', 'tikool-memory-page'),
            'requestError'       => __('The upload could not be completed. Please check your connection and try again.', 'tikool-memory-page'),
            'copyLabel'          => __('Copy link', 'tikool-memory-page'),
            'copiedLabel'        => __('Copied', 'tikool-memory-page'),
        ]);
    }

    public function render_endpoint(string $value = ''): void
    {
        if (! is_user_logged_in()) {
            return;
        }

        $slug = sanitize_text_field($value !== '' ? $value : (string) get_query_var('memory-pages'));
        if ($slug !== '') {
            $page_id = Memory_Pages::instance()->page_by_slug_for_user($slug, get_current_user_id());
            if ($page_id < 1) {
                wc_print_notice(__('That Memory Page was not found.', 'tikool-memory-page'), 'error');
                $this->render_list();
                return;
            }
            $this->render_editor($page_id);
            return;
        }

        $this->render_list();
    }

    private function render_list(): void
    {
        $page_ids = Memory_Pages::instance()->user_pages(get_current_user_id());
        ?>
        <section class="tikool-memory-list">
            <header class="tikool-memory-heading">
                <div>
                    <p class="tikool-memory-eyebrow"><?php esc_html_e('Made for a lifetime', 'tikool-memory-page'); ?></p>
                    <h2><?php esc_html_e('Your Memory Pages', 'tikool-memory-page'); ?></h2>
                    <p class="tikool-memory-heading__intro"><?php esc_html_e('Create and update the story opened by your Tikool NFC keepsake.', 'tikool-memory-page'); ?></p>
                </div>
            </header>
            <?php if ($page_ids === []) : ?>
                <div class="tikool-memory-empty">
                    <span class="tikool-memory-empty__mark" aria-hidden="true">♡</span>
                    <h3><?php esc_html_e('No Memory Pages yet', 'tikool-memory-page'); ?></h3>
                    <p><?php esc_html_e('A page will appear here after payment for a Memory Page product is confirmed.', 'tikool-memory-page'); ?></p>
                    <a class="button" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Visit the shop', 'tikool-memory-page'); ?></a>
                </div>
            <?php else : ?>
                <div class="tikool-memory-grid">
                    <?php foreach ($page_ids as $page_id) :
                        $status = (string) get_post_meta($page_id, '_tikool_memory_publish_status', true);
                        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
                        $pet_name = (string) get_post_meta($page_id, '_tikool_memory_pet_name', true);
                        $edit_url = wc_get_endpoint_url('memory-pages', $slug, wc_get_page_permalink('myaccount'));
                        $public_url = Memory_Pages::public_url($page_id);
                        $has_published = get_post_meta($page_id, '_tikool_memory_published_at', true) !== '';
                        ?>
                        <article class="tikool-memory-card">
                            <div class="tikool-memory-card__topline">
                                <span class="tikool-memory-card__mark" aria-hidden="true">T</span>
                                <span class="tikool-memory-status tikool-memory-status--<?php echo esc_attr($status ?: 'draft'); ?>"><?php echo esc_html($this->status_label($status)); ?></span>
                            </div>
                            <div class="tikool-memory-card__body">
                                <p class="tikool-memory-card__label"><?php esc_html_e('Memory Page', 'tikool-memory-page'); ?></p>
                                <h3><?php echo esc_html($pet_name !== '' ? $pet_name : get_the_title($page_id)); ?></h3>
                                <p><?php echo esc_html(get_the_title($page_id)); ?></p>
                            </div>
                            <div class="tikool-memory-card__actions">
                                <a class="button" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit page', 'tikool-memory-page'); ?></a>
                                <?php if (($status === 'published' || $has_published) && $public_url !== '') : ?>
                                    <a href="<?php echo esc_url($public_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open live page', 'tikool-memory-page'); ?></a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    private function render_editor(int $page_id): void
    {
        $post = get_post($page_id);
        if (! $post instanceof WP_Post) {
            return;
        }

        $status = (string) get_post_meta($page_id, '_tikool_memory_publish_status', true);
        $manifest = $status === 'published' ? Storage::manifest_for_page($page_id) : [];
        $editor_title = $manifest !== [] && $manifest['title'] !== '' ? (string) $manifest['title'] : $post->post_title;
        $scene = $manifest !== [] ? (string) $manifest['scene'] : ((string) get_post_meta($page_id, '_tikool_memory_scene', true) ?: 'cozy-home');
        // Existing pages keep their published legacy HTML until the customer saves.
        // In the editor, guide the retired garden scene to its closest replacement.
        if ($scene === 'garden') {
            $scene = 'gentle-memorial';
        }
        $pet_name = $manifest !== [] ? (string) ($manifest['pet']['name'] ?? '') : (string) get_post_meta($page_id, '_tikool_memory_pet_name', true);
        $story = $manifest !== [] ? (string) $manifest['story'] : (string) get_post_meta($page_id, '_tikool_memory_story', true);
        $timeline = $manifest !== [] ? $manifest['timeline'] : get_post_meta($page_id, '_tikool_memory_timeline', true);
        $timeline = is_array($timeline) ? $timeline : [];
        $media = $manifest !== [] ? $manifest['media'] : get_post_meta($page_id, '_tikool_memory_media', true);
        $media = is_array($media) ? $media : ['photos' => [], 'videos' => [], 'audio' => []];
        $last_error = (string) get_post_meta($page_id, '_tikool_memory_last_error', true);
        $public_url = Memory_Pages::public_url($page_id);
        $has_published = get_post_meta($page_id, '_tikool_memory_published_at', true) !== '';
        $saved_notice = $this->take_saved_notice($page_id);
        ?>
        <section class="tikool-memory-editor">
            <a class="tikool-memory-back" href="<?php echo esc_url(wc_get_account_endpoint_url('memory-pages')); ?>">&larr; <?php esc_html_e('All Memory Pages', 'tikool-memory-page'); ?></a>
            <header class="tikool-memory-heading">
                <div>
                    <p class="tikool-memory-eyebrow"><?php esc_html_e('NFC destination', 'tikool-memory-page'); ?></p>
                    <h2><?php echo esc_html($editor_title); ?></h2>
                    <p class="tikool-memory-heading__intro"><?php esc_html_e('Shape the story behind your keepsake. Your changes publish to the same permanent link.', 'tikool-memory-page'); ?></p>
                </div>
                <span class="tikool-memory-status tikool-memory-status--<?php echo esc_attr($status ?: 'draft'); ?>"><?php echo esc_html($this->status_label($status)); ?></span>
            </header>

            <?php if ($saved_notice !== []) : ?>
                <div class="<?php echo esc_attr($saved_notice['type'] === 'success' ? 'woocommerce-message' : 'woocommerce-error'); ?>" role="status"><?php echo esc_html($saved_notice['message']); ?></div>
            <?php endif; ?>

            <?php if ($public_url !== '') : ?>
                <div class="tikool-memory-nfc-url">
                    <span class="tikool-memory-nfc-url__icon" aria-hidden="true">∞</span>
                    <div class="tikool-memory-nfc-url__copy">
                        <span><?php esc_html_e('Permanent NFC URL', 'tikool-memory-page'); ?></span>
                        <code data-memory-url><?php echo esc_html($public_url); ?></code>
                    </div>
                    <div class="tikool-memory-nfc-url__actions">
                        <button class="tikool-memory-copy-link" type="button" data-copy-memory-url><?php esc_html_e('Copy link', 'tikool-memory-page'); ?></button>
                        <?php if ($status === 'published' || $has_published) : ?><a href="<?php echo esc_url($public_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open page', 'tikool-memory-page'); ?> <span aria-hidden="true">↗</span></a><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($status === 'failed' && $last_error !== '') : ?>
                <p class="woocommerce-error"><?php echo esc_html($last_error); ?></p>
            <?php endif; ?>

            <form class="tikool-memory-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data" data-memory-page-form>
                <input type="hidden" name="action" value="tikool_save_memory_page">
                <input type="hidden" name="page_id" value="<?php echo esc_attr((string) $page_id); ?>">
                <?php wp_nonce_field('tikool_save_memory_page_' . $page_id); ?>

                <fieldset class="tikool-memory-section">
                    <legend><span>01</span><?php esc_html_e('Tell their story', 'tikool-memory-page'); ?></legend>
                    <p class="tikool-memory-section__intro"><?php esc_html_e('Start with the words visitors will see first, then add the memories that made them one of a kind.', 'tikool-memory-page'); ?></p>
                    <div class="tikool-memory-field-grid">
                        <p><label for="memory-title"><?php esc_html_e('Page title', 'tikool-memory-page'); ?></label><input id="memory-title" type="text" name="memory[title]" value="<?php echo esc_attr($editor_title); ?>" maxlength="120" required><span class="tikool-memory-field-hint"><?php esc_html_e('A short headline for the page.', 'tikool-memory-page'); ?></span></p>
                        <p><label for="memory-pet-name"><?php esc_html_e('Pet name', 'tikool-memory-page'); ?></label><input id="memory-pet-name" type="text" name="memory[pet_name]" value="<?php echo esc_attr($pet_name); ?>" maxlength="80" required><span class="tikool-memory-field-hint"><?php esc_html_e('Shown throughout the chosen scene.', 'tikool-memory-page'); ?></span></p>
                    </div>
                    <p><label for="memory-story"><?php esc_html_e('Their story', 'tikool-memory-page'); ?></label><textarea id="memory-story" name="memory[story]" rows="8" maxlength="12000" placeholder="<?php esc_attr_e('Share the moments, habits and little details you never want to forget…', 'tikool-memory-page'); ?>"><?php echo esc_textarea($story); ?></textarea></p>
                </fieldset>

                <fieldset class="tikool-memory-section">
                    <legend><span>02</span><?php esc_html_e('Choose a scene', 'tikool-memory-page'); ?></legend>
                    <p class="tikool-memory-section__intro"><?php esc_html_e('Pick the atmosphere that feels most like them. You can change it whenever you like.', 'tikool-memory-page'); ?></p>
                    <div class="tikool-memory-scenes">
                        <?php foreach (Storage::scenes() as $scene_slug => $details) : ?>
                            <label data-scene="<?php echo esc_attr($scene_slug); ?>"><input type="radio" name="memory[scene]" value="<?php echo esc_attr($scene_slug); ?>" <?php checked($scene, $scene_slug); ?> required><strong><?php echo esc_html($details['label']); ?></strong><span><?php echo esc_html($details['description']); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="tikool-memory-section">
                    <legend><span>03</span><?php esc_html_e('Add photos, video and voice', 'tikool-memory-page'); ?></legend>
                    <p class="tikool-memory-section__intro"><?php esc_html_e('Bring the page to life with the images, movement and sounds that hold the strongest memories.', 'tikool-memory-page'); ?></p>
                    <?php $this->render_media_group($page_id, 'photos', __('Photos', 'tikool-memory-page'), $media['photos'] ?? [], 'image/jpeg,image/png,image/webp,image/avif'); ?>
                    <?php $this->render_media_group($page_id, 'videos', __('Videos', 'tikool-memory-page'), $media['videos'] ?? [], 'video/mp4,video/webm,video/quicktime'); ?>
                    <?php $this->render_media_group($page_id, 'audio', __('Audio', 'tikool-memory-page'), $media['audio'] ?? [], 'audio/mpeg,audio/mp4,audio/wav,audio/ogg'); ?>
                </fieldset>

                <fieldset class="tikool-memory-section">
                    <legend><span>04</span><?php esc_html_e('Build their timeline', 'tikool-memory-page'); ?></legend>
                    <div class="tikool-memory-fieldset-heading"><p class="tikool-memory-section__intro"><?php esc_html_e('Add the milestones, adventures and everyday moments that tell their story.', 'tikool-memory-page'); ?></p><button class="button" type="button" data-add-timeline><span aria-hidden="true">+</span> <?php esc_html_e('Add moment', 'tikool-memory-page'); ?></button></div>
                    <div class="tikool-memory-timeline-fields" data-timeline-list>
                        <?php foreach ($timeline as $index => $row) : ?><?php $this->render_timeline_row((int) $index, $row); ?><?php endforeach; ?>
                    </div>
                    <template data-timeline-template><?php $this->render_timeline_row('__INDEX__', []); ?></template>
                </fieldset>

                <div class="tikool-memory-submit">
                    <div class="tikool-memory-submit__action">
                        <div><strong><?php esc_html_e('Ready when you are', 'tikool-memory-page'); ?></strong><span><?php esc_html_e('Your NFC URL always stays the same.', 'tikool-memory-page'); ?></span></div>
                        <button class="button alt" type="submit" data-save-button <?php disabled(in_array($status, ['queued', 'running'], true)); ?>><?php esc_html_e('Save & publish', 'tikool-memory-page'); ?> <span aria-hidden="true">→</span></button>
                    </div>
                    <div class="tikool-memory-progress" data-upload-progress hidden>
                        <div class="tikool-memory-progress__header"><strong data-progress-label><?php esc_html_e('Preparing upload…', 'tikool-memory-page'); ?></strong><span data-progress-value>0%</span></div>
                        <progress max="100" value="0" data-progress-bar>0%</progress>
                    </div>
                </div>
            </form>
        </section>
        <?php
    }

    /**
     * @param array<int, array<string, string>> $items Media items.
     */
    private function render_media_group(int $page_id, string $kind, string $label, array $items, string $accept): void
    {
        if ($kind === 'photos') {
            $this->render_photo_group($page_id, $label, $items, $accept);
            return;
        }
        if ($kind === 'videos') {
            $this->render_video_group($page_id, $label, $items, $accept);
            return;
        }
        $this->render_audio_group($page_id, $label, $items, $accept);
    }

    /**
     * Render photos as a single interactive card collection. JavaScript keeps the
     * selected FileList and the pending-removal state in sync with the form.
     *
     * @param array<int, array<string, string>> $items Existing photo items.
     */
    private function render_photo_group(int $page_id, string $label, array $items, string $accept): void
    {
        ?>
        <div class="tikool-memory-media-group tikool-memory-photo-manager" data-photo-manager data-max-photos="<?php echo esc_attr((string) Storage::PHOTO_MAX_COUNT); ?>">
            <div class="tikool-memory-media-group__heading">
                <span class="tikool-memory-media-group__icon" aria-hidden="true">▧</span>
                <label for="memory-photos"><?php echo esc_html($label); ?></label>
                <span class="tikool-memory-photo-count" data-photo-count aria-live="polite"></span>
            </div>
            <p class="tikool-memory-media-help"><?php echo esc_html(sprintf(__('JPEG, PNG, WebP or AVIF. Maximum 2 MB each, up to %d photos. Drag the cards to change their order.', 'tikool-memory-page'), Storage::PHOTO_MAX_COUNT)); ?></p>
            <div class="tikool-memory-photo-grid" data-photo-grid>
                <?php foreach ($items as $item) :
                    $src = (string) ($item['src'] ?? '');
                    $name = (string) ($item['name'] ?? basename($src));
                    if ($src === '') {
                        continue;
                    }
                    $media_url = $this->media_url($page_id, $src);
                    ?>
                    <article class="tikool-memory-photo-card" data-existing-photo-card data-sortable-media-card data-media-existing="<?php echo esc_attr($src); ?>">
                        <div class="tikool-memory-photo-card__preview">
                            <img src="<?php echo esc_url($media_url); ?>" alt="<?php echo esc_attr($name); ?>">
                            <button type="button" class="tikool-memory-photo-card__drag" data-media-drag-handle aria-label="<?php esc_attr_e('Drag to reorder. You can also use the arrow keys.', 'tikool-memory-page'); ?>">⠿</button>
                            <div class="tikool-memory-photo-card__remove-overlay" aria-hidden="true"><span>×</span><strong><?php esc_html_e('Will be removed when you save', 'tikool-memory-page'); ?></strong></div>
                        </div>
                        <div class="tikool-memory-photo-card__footer">
                            <span class="tikool-memory-photo-card__name" title="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></span>
                            <button type="button" class="tikool-memory-photo-card__remove" data-toggle-existing-photo><?php esc_html_e('Remove', 'tikool-memory-page'); ?></button>
                            <input class="tikool-memory-photo-remove-checkbox" type="checkbox" name="memory[remove_media][photos][]" value="<?php echo esc_attr($src); ?>" data-remove-existing-photo tabindex="-1" aria-hidden="true">
                        </div>
                    </article>
                <?php endforeach; ?>
                <label class="tikool-memory-photo-add-card" data-photo-add-card for="memory-photos">
                    <span class="tikool-memory-photo-add-card__icon" aria-hidden="true">+</span>
                    <strong><?php esc_html_e('Add photo', 'tikool-memory-page'); ?></strong>
                    <small><?php esc_html_e('Choose from your device', 'tikool-memory-page'); ?></small>
                    <input id="memory-photos" type="file" name="photos[]" accept="<?php echo esc_attr($accept); ?>" data-media-kind="photos" multiple>
                </label>
            </div>
            <div data-media-order-inputs hidden></div>
            <p class="tikool-memory-media-validation" data-media-validation="photos" aria-live="polite"></p>
        </div>
        <?php
    }

    /**
     * Render videos using the same add, preview and pending-removal card model as photos.
     *
     * @param array<int, array<string, string>> $items Existing video items.
     */
    private function render_video_group(int $page_id, string $label, array $items, string $accept): void
    {
        ?>
        <div class="tikool-memory-media-group tikool-memory-video-manager" data-video-manager data-max-videos="<?php echo esc_attr((string) Storage::VIDEO_MAX_COUNT); ?>">
            <div class="tikool-memory-media-group__heading">
                <span class="tikool-memory-media-group__icon" aria-hidden="true">▷</span>
                <label for="memory-videos"><?php echo esc_html($label); ?></label>
                <span class="tikool-memory-photo-count" data-video-count aria-live="polite"></span>
            </div>
            <p class="tikool-memory-media-help"><?php echo esc_html(sprintf(__('Please upload videos shorter than 1 minute. Up to %d videos. Drag the cards to change their order.', 'tikool-memory-page'), Storage::VIDEO_MAX_COUNT)); ?></p>
            <div class="tikool-memory-photo-grid tikool-memory-video-grid" data-video-grid>
                <?php foreach ($items as $item) :
                    $src = (string) ($item['src'] ?? '');
                    $name = (string) ($item['name'] ?? basename($src));
                    if ($src === '') {
                        continue;
                    }
                    $media_url = $this->media_url($page_id, $src);
                    ?>
                    <article class="tikool-memory-photo-card tikool-memory-video-card" data-existing-video-card data-sortable-media-card data-media-existing="<?php echo esc_attr($src); ?>">
                        <div class="tikool-memory-photo-card__preview tikool-memory-video-card__preview">
                            <video src="<?php echo esc_url($media_url); ?>" controls preload="metadata"></video>
                            <button type="button" class="tikool-memory-photo-card__drag" data-media-drag-handle aria-label="<?php esc_attr_e('Drag to reorder. You can also use the arrow keys.', 'tikool-memory-page'); ?>">⠿</button>
                            <div class="tikool-memory-photo-card__remove-overlay" aria-hidden="true"><span>×</span><strong><?php esc_html_e('Will be removed when you save', 'tikool-memory-page'); ?></strong></div>
                        </div>
                        <div class="tikool-memory-photo-card__footer">
                            <span class="tikool-memory-photo-card__name" title="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></span>
                            <button type="button" class="tikool-memory-photo-card__remove" data-toggle-existing-video><?php esc_html_e('Remove', 'tikool-memory-page'); ?></button>
                            <input class="tikool-memory-photo-remove-checkbox" type="checkbox" name="memory[remove_media][videos][]" value="<?php echo esc_attr($src); ?>" data-remove-existing-video tabindex="-1" aria-hidden="true">
                        </div>
                    </article>
                <?php endforeach; ?>
                <label class="tikool-memory-photo-add-card tikool-memory-video-add-card" data-video-add-card for="memory-videos">
                    <span class="tikool-memory-photo-add-card__icon" aria-hidden="true">+</span>
                    <strong><?php esc_html_e('Add video', 'tikool-memory-page'); ?></strong>
                    <small><?php esc_html_e('Choose from your device', 'tikool-memory-page'); ?></small>
                    <input id="memory-videos" type="file" name="videos[]" accept="<?php echo esc_attr($accept); ?>" data-media-kind="videos" multiple>
                </label>
            </div>
            <div data-media-order-inputs hidden></div>
            <p class="tikool-memory-media-validation" data-media-validation="videos" aria-live="polite"></p>
        </div>
        <?php
    }

    /**
     * Render audio using the same add, preview and pending-removal card model as photos.
     *
     * @param array<int, array<string, string>> $items Existing audio items.
     */
    private function render_audio_group(int $page_id, string $label, array $items, string $accept): void
    {
        ?>
        <div class="tikool-memory-media-group tikool-memory-audio-manager" data-audio-manager data-max-audio="<?php echo esc_attr((string) Storage::AUDIO_MAX_COUNT); ?>">
            <div class="tikool-memory-media-group__heading">
                <span class="tikool-memory-media-group__icon" aria-hidden="true">♪</span>
                <label for="memory-audio"><?php echo esc_html($label); ?></label>
                <span class="tikool-memory-photo-count" data-audio-count aria-live="polite"></span>
            </div>
            <p class="tikool-memory-media-help"><?php echo esc_html(sprintf(__('Maximum 1 minute and 20 MB per audio file, up to %d audio clips. Drag the cards to change their order.', 'tikool-memory-page'), Storage::AUDIO_MAX_COUNT)); ?></p>
            <div class="tikool-memory-photo-grid tikool-memory-audio-grid" data-audio-grid>
                <?php foreach ($items as $item) :
                    $src = (string) ($item['src'] ?? '');
                    $name = (string) ($item['name'] ?? basename($src));
                    if ($src === '') {
                        continue;
                    }
                    $media_url = $this->media_url($page_id, $src);
                    ?>
                    <article class="tikool-memory-photo-card tikool-memory-audio-card" data-existing-audio-card data-sortable-media-card data-media-existing="<?php echo esc_attr($src); ?>">
                        <div class="tikool-memory-photo-card__preview tikool-memory-audio-card__preview">
                            <span class="tikool-memory-audio-card__art" aria-hidden="true">♪</span>
                            <audio src="<?php echo esc_url($media_url); ?>" controls preload="metadata"></audio>
                            <button type="button" class="tikool-memory-photo-card__drag" data-media-drag-handle aria-label="<?php esc_attr_e('Drag to reorder. You can also use the arrow keys.', 'tikool-memory-page'); ?>">⠿</button>
                            <div class="tikool-memory-photo-card__remove-overlay" aria-hidden="true"><span>×</span><strong><?php esc_html_e('Will be removed when you save', 'tikool-memory-page'); ?></strong></div>
                        </div>
                        <div class="tikool-memory-photo-card__footer">
                            <span class="tikool-memory-photo-card__name" title="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></span>
                            <button type="button" class="tikool-memory-photo-card__remove" data-toggle-existing-audio><?php esc_html_e('Remove', 'tikool-memory-page'); ?></button>
                            <input class="tikool-memory-photo-remove-checkbox" type="checkbox" name="memory[remove_media][audio][]" value="<?php echo esc_attr($src); ?>" data-remove-existing-audio tabindex="-1" aria-hidden="true">
                        </div>
                    </article>
                <?php endforeach; ?>
                <label class="tikool-memory-photo-add-card tikool-memory-audio-add-card" data-audio-add-card for="memory-audio">
                    <span class="tikool-memory-photo-add-card__icon" aria-hidden="true">+</span>
                    <strong><?php esc_html_e('Add audio', 'tikool-memory-page'); ?></strong>
                    <small><?php esc_html_e('Choose from your device', 'tikool-memory-page'); ?></small>
                    <input id="memory-audio" type="file" name="audio[]" accept="<?php echo esc_attr($accept); ?>" data-media-kind="audio" multiple>
                </label>
            </div>
            <div data-media-order-inputs hidden></div>
            <p class="tikool-memory-media-validation" data-media-validation="audio" aria-live="polite"></p>
        </div>
        <?php
    }

    /**
     * @param int|string $index Timeline array index or template token.
     * @param array<string, string> $row Timeline row.
     */
    private function render_timeline_row($index, array $row): void
    {
        $prefix = 'memory[timeline][' . $index . ']';
        ?>
        <div class="tikool-memory-timeline-row">
            <span class="tikool-memory-timeline-row__handle" aria-hidden="true">•</span>
            <p><label><?php esc_html_e('Date or year', 'tikool-memory-page'); ?></label><input type="text" name="<?php echo esc_attr($prefix); ?>[date]" value="<?php echo esc_attr((string) ($row['date'] ?? '')); ?>" maxlength="40" placeholder="2017"></p>
            <p><label><?php esc_html_e('Moment', 'tikool-memory-page'); ?></label><input type="text" name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr((string) ($row['title'] ?? '')); ?>" maxlength="120"></p>
            <p><label><?php esc_html_e('Memory', 'tikool-memory-page'); ?></label><textarea name="<?php echo esc_attr($prefix); ?>[text]" rows="3" maxlength="2000"><?php echo esc_textarea((string) ($row['text'] ?? '')); ?></textarea></p>
            <button type="button" class="tikool-memory-remove-row" data-remove-timeline aria-label="<?php esc_attr_e('Remove this moment', 'tikool-memory-page'); ?>">&times;</button>
        </div>
        <?php
    }

    public function save(): void
    {
        if (! is_user_logged_in()) {
            wp_die(esc_html__('Please log in to edit a Memory Page.', 'tikool-memory-page'), '', ['response' => 401]);
        }

        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        check_admin_referer('tikool_save_memory_page_' . $page_id);
        if (! Memory_Pages::instance()->user_owns_page($page_id, get_current_user_id())) {
            wp_die(esc_html__('You cannot edit this Memory Page.', 'tikool-memory-page'), '', ['response' => 403]);
        }
        if (Tasks::instance()->has_active_task($page_id)) {
            $this->redirect_to_editor($page_id, __('This Memory Page is currently publishing. Please wait for it to finish.', 'tikool-memory-page'), 'error');
        }

        $input = isset($_POST['memory']) && is_array($_POST['memory']) ? wp_unslash($_POST['memory']) : [];
        $files = [];
        foreach (['photos', 'videos', 'audio'] as $kind) {
            if (isset($_FILES[$kind])) {
                $files[$kind] = $_FILES[$kind]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }
        }

        $bundle = (new Storage())->stage($page_id, $input, $files);
        if (is_wp_error($bundle)) {
            update_post_meta($page_id, '_tikool_memory_publish_status', 'failed');
            update_post_meta($page_id, '_tikool_memory_last_error', $bundle->get_error_message());
            $this->redirect_to_editor($page_id, $bundle->get_error_message(), 'error');
        }

        $task_id = Tasks::instance()->create($page_id, $bundle['token']);
        if (is_wp_error($task_id)) {
            Storage::delete_bundle($bundle['token']);
            update_post_meta($page_id, '_tikool_memory_publish_status', 'failed');
            update_post_meta($page_id, '_tikool_memory_last_error', $task_id->get_error_message());
            $this->redirect_to_editor($page_id, $task_id->get_error_message(), 'error');
        }

        $this->redirect_to_editor($page_id, __('Your changes were saved. The system is publishing your Memory Page. Please wait.', 'tikool-memory-page'), 'success');
    }

    public function serve_media(): void
    {
        if (! is_user_logged_in()) {
            status_header(401);
            exit;
        }
        $page_id = isset($_GET['page_id']) ? absint($_GET['page_id']) : 0;
        $relative = isset($_GET['file']) ? sanitize_text_field(wp_unslash((string) $_GET['file'])) : '';
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        if (! wp_verify_nonce($nonce, 'tikool_memory_media_' . $page_id . '_' . md5($relative)) || ! Memory_Pages::instance()->user_owns_page($page_id, get_current_user_id())) {
            status_header(403);
            exit;
        }

        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $file = Storage::legacy_media_file($slug, $relative);
        if ($file === '') {
            status_header(404);
            exit;
        }

        $mime = function_exists('mime_content_type') ? (string) mime_content_type($file) : 'application/octet-stream';
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: inline; filename="' . sanitize_file_name(basename($file)) . '"');
        readfile($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    private function media_url(int $page_id, string $relative): string
    {
        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        if (Storage::legacy_media_file($slug, $relative) === '') {
            return Storage::media_public_url($page_id, $relative);
        }
        return add_query_arg([
            'action'   => 'tikool_memory_media',
            'page_id'  => $page_id,
            'file'     => $relative,
            '_wpnonce' => wp_create_nonce('tikool_memory_media_' . $page_id . '_' . md5($relative)),
        ], admin_url('admin-post.php'));
    }

    private function redirect_to_editor(int $page_id, string $message = '', string $type = 'success'): void
    {
        if ($message !== '') {
            set_transient($this->notice_key($page_id), [
                'message' => sanitize_text_field($message),
                'type'    => $type === 'success' ? 'success' : 'error',
            ], 5 * MINUTE_IN_SECONDS);
        }
        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $url = wc_get_endpoint_url('memory-pages', $slug, wc_get_page_permalink('myaccount'));
        $is_xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($is_xhr) {
            wp_send_json_success(['redirect' => $url]);
        }
        wp_safe_redirect($url);
        exit;
    }

    /** @return array{message:string,type:string}|array{} */
    private function take_saved_notice(int $page_id): array
    {
        $key = $this->notice_key($page_id);
        $notice = get_transient($key);
        delete_transient($key);
        if (! is_array($notice) || empty($notice['message'])) {
            return [];
        }
        return [
            'message' => sanitize_text_field((string) $notice['message']),
            'type'    => ($notice['type'] ?? '') === 'success' ? 'success' : 'error',
        ];
    }

    private function notice_key(int $page_id): string
    {
        return 'tikool_memory_notice_' . get_current_user_id() . '_' . $page_id;
    }

    private function status_label(string $status): string
    {
        return match ($status) {
            'published' => __('Published', 'tikool-memory-page'),
            'queued' => __('Publishing', 'tikool-memory-page'),
            'running' => __('Publishing', 'tikool-memory-page'),
            'failed' => __('Needs attention', 'tikool-memory-page'),
            default => __('Not published', 'tikool-memory-page'),
        };
    }
}
