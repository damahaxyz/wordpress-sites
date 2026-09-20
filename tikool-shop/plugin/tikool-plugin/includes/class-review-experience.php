<?php
/**
 * Editorial review management and the product review storefront experience.
 *
 * @package TikoolShopPlugin
 */

declare(strict_types=1);

namespace Tikool\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Review_Experience
{
    private const FEATURED_META = '_tikool_featured_review';

    private const MEDIA_META = '_tikool_review_media_attachment_id';

    private const LEGACY_MEDIA_META = '_tikool_loox_media_attachment_id';

    private const REVIEWS_PER_PAGE = 12;

    private const MAX_UPLOAD_BYTES = 5 * MB_IN_BYTES;

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
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('wp', [$this, 'configure_product_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_storefront_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('add_meta_boxes_comment', [$this, 'add_review_meta_box']);
        add_action('edit_comment', [$this, 'save_review_fields']);

        add_filter('manage_edit-comments_columns', [$this, 'add_featured_column']);
        add_action('manage_comments_custom_column', [$this, 'render_comment_featured_column'], 10, 2);
        add_filter('woocommerce_product_reviews_table_columns', [$this, 'add_featured_column']);
        add_action('woocommerce_product_reviews_table_column_tikool_featured', [$this, 'render_product_review_featured_column']);

        add_action('admin_post_nopriv_tikool_submit_review', [$this, 'handle_review_submission']);
        add_action('admin_post_tikool_submit_review', [$this, 'handle_review_submission']);
    }

    public function configure_product_page(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10);
        add_action('woocommerce_after_single_product_summary', [$this, 'render_product_content'], 8);
    }

    public function enqueue_storefront_assets(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        $css = TIKOOL_PLUGIN_DIR . 'assets/css/reviews.css';
        $js = TIKOOL_PLUGIN_DIR . 'assets/js/reviews.js';

        wp_enqueue_style(
            'tikool-reviews',
            TIKOOL_PLUGIN_URL . 'assets/css/reviews.css',
            [],
            is_file($css) ? (string) filemtime($css) : TIKOOL_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tikool-reviews',
            TIKOOL_PLUGIN_URL . 'assets/js/reviews.js',
            [],
            is_file($js) ? (string) filemtime($js) : TIKOOL_PLUGIN_VERSION,
            true
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'comment.php') {
            return;
        }

        $comment_id = absint($_GET['c'] ?? 0);
        if (! $this->is_product_review($comment_id)) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'tikool-review-admin',
            TIKOOL_PLUGIN_URL . 'assets/js/review-admin.js',
            ['media-editor'],
            TIKOOL_PLUGIN_VERSION,
            true
        );
        wp_enqueue_style(
            'tikool-review-admin',
            TIKOOL_PLUGIN_URL . 'assets/css/review-admin.css',
            [],
            TIKOOL_PLUGIN_VERSION
        );
    }

    public function add_review_meta_box(\WP_Comment $comment): void
    {
        if (! $this->is_product_review((int) $comment->comment_ID)) {
            return;
        }

        add_meta_box(
            'tikool-review-editor',
            __('Tikool review settings', 'tikool-plugin'),
            [$this, 'render_review_meta_box'],
            'comment',
            'normal',
            'high'
        );
    }

    public function render_review_meta_box(\WP_Comment $comment): void
    {
        $comment_id = (int) $comment->comment_ID;
        $featured = get_comment_meta($comment_id, self::FEATURED_META, true) === '1';
        $attachment_id = self::media_attachment_id($comment_id);
        $image = $attachment_id > 0 ? wp_get_attachment_image_url($attachment_id, 'medium') : false;

        wp_nonce_field('tikool_edit_review_' . $comment_id, 'tikool_review_editor_nonce');
        ?>
        <div class="tikool-review-editor">
            <label class="tikool-review-editor__featured">
                <input name="tikool_featured_review" type="checkbox" value="1" <?php checked($featured); ?>>
                <span><strong><?php esc_html_e('Featured review', 'tikool-plugin'); ?></strong><small><?php esc_html_e('Show this review in the featured cards above the product description.', 'tikool-plugin'); ?></small></span>
            </label>
            <div class="tikool-review-media-editor">
                <strong><?php esc_html_e('Review image', 'tikool-plugin'); ?></strong>
                <input class="tikool-review-media-editor__id" name="tikool_review_media_attachment_id" type="hidden" value="<?php echo esc_attr((string) $attachment_id); ?>">
                <div class="tikool-review-media-editor__preview">
                    <?php if ($image) : ?>
                        <img src="<?php echo esc_url($image); ?>" alt="">
                    <?php else : ?>
                        <span><?php esc_html_e('No image selected', 'tikool-plugin'); ?></span>
                    <?php endif; ?>
                </div>
                <p>
                    <button class="button tikool-review-media-editor__choose" type="button"><?php esc_html_e('Choose or upload image', 'tikool-plugin'); ?></button>
                    <button class="button-link-delete tikool-review-media-editor__remove" type="button"<?php echo $attachment_id > 0 ? '' : ' hidden'; ?>><?php esc_html_e('Remove image', 'tikool-plugin'); ?></button>
                </p>
                <p class="description"><?php esc_html_e('Changing this replaces the image shown on the storefront review card. The original media-library file is not deleted.', 'tikool-plugin'); ?></p>
            </div>
        </div>
        <?php
    }

    public function save_review_fields(int $comment_id): void
    {
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['tikool_review_editor_nonce'] ?? '')));
        if (
            $nonce === ''
            || ! wp_verify_nonce($nonce, 'tikool_edit_review_' . $comment_id)
            || ! current_user_can('edit_comment', $comment_id)
            || ! $this->is_product_review($comment_id)
        ) {
            return;
        }

        if (isset($_POST['tikool_featured_review'])) {
            update_comment_meta($comment_id, self::FEATURED_META, '1');
        } else {
            delete_comment_meta($comment_id, self::FEATURED_META);
        }

        $attachment_id = absint($_POST['tikool_review_media_attachment_id'] ?? 0);
        if ($attachment_id > 0 && get_post_type($attachment_id) === 'attachment' && wp_attachment_is_image($attachment_id)) {
            update_comment_meta($comment_id, self::MEDIA_META, $attachment_id);
            update_comment_meta($comment_id, self::LEGACY_MEDIA_META, $attachment_id);
        } else {
            delete_comment_meta($comment_id, self::MEDIA_META);
            delete_comment_meta($comment_id, self::LEGACY_MEDIA_META);
        }
    }

    /**
     * @param array<string, string> $columns Admin review table columns.
     * @return array<string, string>
     */
    public function add_featured_column(array $columns): array
    {
        $featured = ['tikool_featured' => __('Featured', 'tikool-plugin')];

        if (isset($columns['tikool_media'])) {
            $media = ['tikool_media' => $columns['tikool_media']];
            unset($columns['tikool_media']);

            return $columns + $featured + $media;
        }

        return $columns + $featured;
    }

    public function render_comment_featured_column(string $column_name, int $comment_id): void
    {
        if ($column_name === 'tikool_featured') {
            $this->render_featured_status($comment_id);
        }
    }

    public function render_product_review_featured_column(\WP_Comment $comment): void
    {
        $this->render_featured_status((int) $comment->comment_ID);
    }

    public function render_product_content(): void
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            return;
        }

        $product_id = $product->get_id();
        $featured = get_comments([
            'meta_key' => self::FEATURED_META,
            'meta_value' => '1',
            'number' => 6,
            'order' => 'DESC',
            'orderby' => 'comment_date_gmt',
            'post_id' => $product_id,
            'status' => 'approve',
            'type' => 'review',
        ]);
        $review_args = [
            'number' => self::REVIEWS_PER_PAGE,
            'offset' => 0,
            'order' => 'DESC',
            'orderby' => 'comment_date_gmt',
            'post_id' => $product_id,
            'status' => 'approve',
            'type' => 'review',
        ];
        $total = (int) get_comments(array_replace($review_args, ['count' => true, 'number' => 0, 'offset' => 0]));
        $pages = max(1, (int) ceil($total / self::REVIEWS_PER_PAGE));
        $page = min($pages, max(1, absint($_GET['review-page'] ?? 1)));
        $review_args['offset'] = ($page - 1) * self::REVIEWS_PER_PAGE;
        $reviews = get_comments($review_args);
        ?>
        <div class="tikool-product-content-stack">
            <?php if ($featured !== []) : ?>
                <section class="tikool-featured-reviews" aria-labelledby="tikool-featured-reviews-heading">
                    <header class="tikool-review-section__header">
                        <div>
                            <p><?php esc_html_e('Customer favorites', 'tikool-plugin'); ?></p>
                            <h2 id="tikool-featured-reviews-heading"><?php esc_html_e('Featured reviews', 'tikool-plugin'); ?></h2>
                        </div>
                        <div class="tikool-review-carousel__controls" aria-label="<?php esc_attr_e('Featured review controls', 'tikool-plugin'); ?>">
                            <button type="button" data-review-carousel-previous aria-label="<?php esc_attr_e('Previous featured review', 'tikool-plugin'); ?>">&larr;</button>
                            <button type="button" data-review-carousel-next aria-label="<?php esc_attr_e('Next featured review', 'tikool-plugin'); ?>">&rarr;</button>
                        </div>
                    </header>
                    <div class="tikool-review-grid tikool-review-grid--featured" data-review-carousel>
                        <?php foreach ($featured as $comment) : ?>
                            <?php $this->render_review_card($comment, true); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="tikool-direct-description" id="description" aria-labelledby="tikool-description-heading">
                <p class="tikool-section-kicker"><?php esc_html_e('Product details', 'tikool-plugin'); ?></p>
                <h2 id="tikool-description-heading"><?php esc_html_e('Description', 'tikool-plugin'); ?></h2>
                <div class="tikool-direct-description__content">
                    <?php echo apply_filters('the_content', $product->get_description()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </section>

            <section class="tikool-all-reviews<?php echo $total === 0 ? ' tikool-all-reviews--empty' : ''; ?>" id="reviews" <?php echo $total > 0 ? 'aria-labelledby="tikool-all-reviews-heading"' : 'aria-label="' . esc_attr__('Product reviews', 'tikool-plugin') . '"'; ?>>
                <?php if ($total === 0) : ?>
                    <?php $this->render_submission_notice(); ?>
                    <button class="tikool-review-modal-open" type="button" data-review-modal-open><?php esc_html_e('Write a review', 'tikool-plugin'); ?></button>
                <?php else : ?>
                    <header class="tikool-review-section__header">
                        <div>
                            <p><?php esc_html_e('Real routines, real feedback', 'tikool-plugin'); ?></p>
                            <h2 id="tikool-all-reviews-heading"><?php printf(esc_html__('Customer reviews (%d)', 'tikool-plugin'), esc_html($total)); ?></h2>
                        </div>
                        <button class="tikool-review-modal-open" type="button" data-review-modal-open><?php esc_html_e('Write a review', 'tikool-plugin'); ?></button>
                    </header>

                    <?php $this->render_submission_notice(); ?>

                    <div class="tikool-review-grid">
                        <?php foreach ($reviews as $comment) : ?>
                            <?php $this->render_review_card($comment); ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($pages > 1) : ?>
                        <nav class="tikool-review-pagination" aria-label="<?php esc_attr_e('Review pages', 'tikool-plugin'); ?>">
                            <?php
                            echo wp_kses_post(paginate_links([
                                'base' => add_query_arg('review-page', '%#%', get_permalink($product_id)),
                                'current' => min($page, $pages),
                                'format' => '',
                                'total' => $pages,
                                'type' => 'list',
                                'add_fragment' => '#reviews',
                                'prev_text' => __('Previous', 'tikool-plugin'),
                                'next_text' => __('Next', 'tikool-plugin'),
                            ]));
                            ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php $this->render_review_modal($product); ?>
        <?php
    }

    public function handle_review_submission(): void
    {
        $product_id = absint($_POST['comment_post_ID'] ?? 0);
        $redirect = $product_id > 0 ? get_permalink($product_id) : home_url('/');
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['tikool_review_nonce'] ?? '')));

        if ($product_id < 1 || ! wp_verify_nonce($nonce, 'tikool_submit_review_' . $product_id)) {
            $this->redirect_review($redirect, 'security');
        }

        $product = wc_get_product($product_id);
        if (! $product || get_post_status($product_id) !== 'publish' || ! comments_open($product_id)) {
            $this->redirect_review($redirect, 'closed');
        }

        if (trim((string) ($_POST['tikool_review_website'] ?? '')) !== '') {
            $this->redirect_review($redirect, 'received');
        }

        $content = sanitize_textarea_field(wp_unslash((string) ($_POST['comment'] ?? '')));
        $rating = absint($_POST['rating'] ?? 0);
        if ($content === '' || $rating < 1 || $rating > 5) {
            $this->redirect_review($redirect, 'required');
        }

        $user = wp_get_current_user();
        $author = $user->exists()
            ? $user->display_name
            : sanitize_text_field(wp_unslash((string) ($_POST['author'] ?? '')));
        $email = $user->exists()
            ? $user->user_email
            : sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));

        if ((! $user->exists() && get_option('require_name_email') && ($author === '' || ! is_email($email))) || $author === '') {
            $this->redirect_review($redirect, 'identity');
        }

        $upload = $_FILES['tikool_review_image'] ?? null;
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) ($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
                $this->redirect_review($redirect, 'image');
            }

            $allowed = [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ];
            $checked = wp_check_filetype_and_ext((string) $upload['tmp_name'], (string) $upload['name'], $allowed);
            if (empty($checked['ext']) || empty($checked['type'])) {
                $this->redirect_review($redirect, 'image');
            }
        }

        $comment_id = wp_new_comment(wp_slash([
            'comment_agent' => sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'comment_author' => $author,
            'comment_author_email' => $email,
            'comment_author_IP' => sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
            'comment_content' => $content,
            'comment_parent' => 0,
            'comment_post_ID' => $product_id,
            'comment_type' => 'review',
            'user_id' => $user->exists() ? $user->ID : 0,
        ]), true);

        if (is_wp_error($comment_id)) {
            $this->redirect_review($redirect, 'failed');
        }

        update_comment_meta((int) $comment_id, 'rating', $rating);

        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_handle_upload(
                'tikool_review_image',
                $product_id,
                sprintf(__('Review photo from %s', 'tikool-plugin'), $author)
            );

            if (is_wp_error($attachment_id)) {
                wp_delete_comment((int) $comment_id, true);
                $this->redirect_review($redirect, 'image');
            }

            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sprintf(__('Review photo from %s', 'tikool-plugin'), $author));
            update_comment_meta((int) $comment_id, self::MEDIA_META, (int) $attachment_id);
        }

        if (class_exists('WC_Comments')) {
            \WC_Comments::clear_transients($product_id);
        }
        wc_delete_product_transients($product_id);

        $approved = wp_get_comment_status((int) $comment_id) === 'approved';
        $this->redirect_review($redirect, $approved ? 'published' : 'moderation');
    }

    public static function media_attachment_id(int $comment_id): int
    {
        $attachment_id = (int) get_comment_meta($comment_id, self::MEDIA_META, true);
        if ($attachment_id < 1) {
            $attachment_id = (int) get_comment_meta($comment_id, self::LEGACY_MEDIA_META, true);
        }

        return $attachment_id > 0 && wp_attachment_is_image($attachment_id) ? $attachment_id : 0;
    }

    private function render_review_card(\WP_Comment $comment, bool $featured = false): void
    {
        $comment_id = (int) $comment->comment_ID;
        $rating = min(5, max(1, (int) get_comment_meta($comment_id, 'rating', true)));
        $attachment_id = self::media_attachment_id($comment_id);
        $verified = get_comment_meta($comment_id, '_tikool_loox_verified', true) === '1';
        if (! $verified && function_exists('wc_customer_bought_product')) {
            $verified = wc_customer_bought_product(
                (string) $comment->comment_author_email,
                (int) $comment->user_id,
                (int) $comment->comment_post_ID
            );
        }
        ?>
        <article class="tikool-review-card<?php echo $featured ? ' tikool-review-card--featured' : ''; ?>">
            <?php if ($attachment_id > 0) : ?>
                <a class="tikool-review-card__media" href="<?php echo esc_url((string) wp_get_attachment_image_url($attachment_id, 'full')); ?>" target="_blank" rel="noopener">
                    <?php echo wp_get_attachment_image($attachment_id, 'medium_large', false, ['loading' => 'lazy', 'alt' => sprintf(__('Review photo from %s', 'tikool-plugin'), $comment->comment_author)]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </a>
            <?php endif; ?>
            <div class="tikool-review-card__body">
                <div class="tikool-review-card__rating" aria-label="<?php echo esc_attr(sprintf(__('%d out of 5 stars', 'tikool-plugin'), $rating)); ?>">
                    <span aria-hidden="true"><?php echo esc_html(str_repeat('★', $rating) . str_repeat('☆', 5 - $rating)); ?></span>
                </div>
                <blockquote><?php echo wpautop(esc_html($comment->comment_content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></blockquote>
                <footer>
                    <span class="tikool-review-card__avatar" aria-hidden="true"><?php echo esc_html(mb_strtoupper(mb_substr($comment->comment_author, 0, 1))); ?></span>
                    <span><strong><?php echo esc_html($comment->comment_author); ?></strong><small><?php echo esc_html(get_comment_date('', $comment)); ?></small></span>
                    <?php if ($verified) : ?><span class="tikool-review-card__verified"><?php esc_html_e('Verified', 'tikool-plugin'); ?></span><?php endif; ?>
                </footer>
            </div>
        </article>
        <?php
    }

    private function render_review_modal(\WC_Product $product): void
    {
        $user = wp_get_current_user();
        ?>
        <dialog class="tikool-review-modal" id="tikool-review-modal" aria-labelledby="tikool-review-modal-title">
            <div class="tikool-review-modal__panel">
                <button class="tikool-review-modal__close" type="button" data-review-modal-close aria-label="<?php esc_attr_e('Close review form', 'tikool-plugin'); ?>">&times;</button>
                <p class="tikool-section-kicker"><?php esc_html_e('Share your ritual', 'tikool-plugin'); ?></p>
                <h2 id="tikool-review-modal-title"><?php esc_html_e('Write a review', 'tikool-plugin'); ?></h2>
                <p><?php echo esc_html($product->get_name()); ?></p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="tikool_submit_review">
                    <input type="hidden" name="comment_post_ID" value="<?php echo esc_attr((string) $product->get_id()); ?>">
                    <?php wp_nonce_field('tikool_submit_review_' . $product->get_id(), 'tikool_review_nonce'); ?>
                    <input class="tikool-review-honeypot" name="tikool_review_website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">

                    <fieldset class="tikool-review-rating">
                        <legend><?php esc_html_e('Your rating', 'tikool-plugin'); ?></legend>
                        <div>
                            <?php for ($rating = 5; $rating >= 1; $rating--) : ?>
                                <input id="tikool-rating-<?php echo esc_attr((string) $rating); ?>" name="rating" type="radio" value="<?php echo esc_attr((string) $rating); ?>" required>
                                <label for="tikool-rating-<?php echo esc_attr((string) $rating); ?>" title="<?php echo esc_attr(sprintf(__('%d stars', 'tikool-plugin'), $rating)); ?>">★</label>
                            <?php endfor; ?>
                        </div>
                    </fieldset>

                    <?php if (! $user->exists()) : ?>
                        <div class="tikool-review-form__row">
                            <label><?php esc_html_e('Name', 'tikool-plugin'); ?><input name="author" type="text" maxlength="80" autocomplete="name" required></label>
                            <label><?php esc_html_e('Email', 'tikool-plugin'); ?><input name="email" type="email" maxlength="100" autocomplete="email" required></label>
                        </div>
                    <?php endif; ?>

                    <label><?php esc_html_e('Your review', 'tikool-plugin'); ?><textarea name="comment" rows="5" maxlength="3000" required></textarea></label>
                    <label class="tikool-review-upload">
                        <span><strong><?php esc_html_e('Add a photo', 'tikool-plugin'); ?></strong><small><?php esc_html_e('Optional · JPG, PNG or WebP · maximum 5 MB', 'tikool-plugin'); ?></small></span>
                        <input name="tikool_review_image" type="file" accept="image/jpeg,image/png,image/webp" data-review-image-input>
                    </label>
                    <div class="tikool-review-upload-preview" data-review-image-preview hidden></div>
                    <button class="tikool-review-submit" type="submit"><?php esc_html_e('Submit review', 'tikool-plugin'); ?></button>
                </form>
            </div>
        </dialog>
        <?php
    }

    private function render_submission_notice(): void
    {
        $status = sanitize_key((string) ($_GET['review-status'] ?? ''));
        $messages = [
            'published' => __('Thank you. Your review is now live.', 'tikool-plugin'),
            'moderation' => __('Thank you. Your review was received and is awaiting approval.', 'tikool-plugin'),
            'required' => __('Please add a rating and review text.', 'tikool-plugin'),
            'identity' => __('Please enter a valid name and email address.', 'tikool-plugin'),
            'image' => __('The review photo must be a JPG, PNG or WebP image under 5 MB.', 'tikool-plugin'),
            'closed' => __('Reviews are not currently open for this product.', 'tikool-plugin'),
            'security' => __('The review form expired. Please try again.', 'tikool-plugin'),
            'failed' => __('The review could not be submitted. Please try again.', 'tikool-plugin'),
        ];

        if (! isset($messages[$status])) {
            return;
        }

        $success = in_array($status, ['published', 'moderation'], true);
        printf(
            '<p class="tikool-review-notice tikool-review-notice--%1$s" role="status">%2$s</p>',
            $success ? 'success' : 'error',
            esc_html($messages[$status])
        );
    }

    private function render_featured_status(int $comment_id): void
    {
        if (get_comment_meta($comment_id, self::FEATURED_META, true) === '1') {
            echo '<span class="tikool-admin-featured-review" title="' . esc_attr__('Featured review', 'tikool-plugin') . '">★</span>';
            return;
        }

        echo '<span aria-hidden="true">—</span>';
    }

    private function is_product_review(int $comment_id): bool
    {
        $comment = get_comment($comment_id);

        return $comment instanceof \WP_Comment
            && $comment->comment_type === 'review'
            && get_post_type((int) $comment->comment_post_ID) === 'product';
    }

    private function redirect_review(string $redirect, string $status): void
    {
        wp_safe_redirect(add_query_arg('review-status', sanitize_key($status), $redirect) . '#reviews');
        exit;
    }
}
