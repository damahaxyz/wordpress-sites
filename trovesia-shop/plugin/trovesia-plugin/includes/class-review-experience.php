<?php
/**
 * Editorial review management and the product review storefront experience.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

namespace Trovesia\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Review_Experience
{
    private const FEATURED_META = '_trovesia_featured_review';

    private const MEDIA_META = '_trovesia_review_media_attachment_id';

    private const LEGACY_MEDIA_META = '_trovesia_loox_media_attachment_id';

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
        add_action('woocommerce_product_reviews_table_column_trovesia_featured', [$this, 'render_product_review_featured_column']);

        add_action('admin_post_nopriv_trovesia_submit_review', [$this, 'handle_review_submission']);
        add_action('admin_post_trovesia_submit_review', [$this, 'handle_review_submission']);
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

        $css = TROVESIA_PLUGIN_DIR . 'assets/css/reviews.css';
        $js = TROVESIA_PLUGIN_DIR . 'assets/js/reviews.js';

        wp_enqueue_style(
            'trovesia-reviews',
            TROVESIA_PLUGIN_URL . 'assets/css/reviews.css',
            [],
            is_file($css) ? (string) filemtime($css) : TROVESIA_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'trovesia-reviews',
            TROVESIA_PLUGIN_URL . 'assets/js/reviews.js',
            [],
            is_file($js) ? (string) filemtime($js) : TROVESIA_PLUGIN_VERSION,
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
            'trovesia-review-admin',
            TROVESIA_PLUGIN_URL . 'assets/js/review-admin.js',
            ['media-editor'],
            TROVESIA_PLUGIN_VERSION,
            true
        );
        wp_enqueue_style(
            'trovesia-review-admin',
            TROVESIA_PLUGIN_URL . 'assets/css/review-admin.css',
            [],
            TROVESIA_PLUGIN_VERSION
        );
    }

    public function add_review_meta_box(\WP_Comment $comment): void
    {
        if (! $this->is_product_review((int) $comment->comment_ID)) {
            return;
        }

        add_meta_box(
            'trovesia-review-editor',
            __('Trovesia review settings', 'trovesia-plugin'),
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

        wp_nonce_field('trovesia_edit_review_' . $comment_id, 'trovesia_review_editor_nonce');
        ?>
        <div class="trovesia-review-editor">
            <label class="trovesia-review-editor__featured">
                <input name="trovesia_featured_review" type="checkbox" value="1" <?php checked($featured); ?>>
                <span><strong><?php esc_html_e('Featured review', 'trovesia-plugin'); ?></strong><small><?php esc_html_e('Show this review in the featured cards above the product description.', 'trovesia-plugin'); ?></small></span>
            </label>
            <div class="trovesia-review-media-editor">
                <strong><?php esc_html_e('Review image', 'trovesia-plugin'); ?></strong>
                <input class="trovesia-review-media-editor__id" name="trovesia_review_media_attachment_id" type="hidden" value="<?php echo esc_attr((string) $attachment_id); ?>">
                <div class="trovesia-review-media-editor__preview">
                    <?php if ($image) : ?>
                        <img src="<?php echo esc_url($image); ?>" alt="">
                    <?php else : ?>
                        <span><?php esc_html_e('No image selected', 'trovesia-plugin'); ?></span>
                    <?php endif; ?>
                </div>
                <p>
                    <button class="button trovesia-review-media-editor__choose" type="button"><?php esc_html_e('Choose or upload image', 'trovesia-plugin'); ?></button>
                    <button class="button-link-delete trovesia-review-media-editor__remove" type="button"<?php echo $attachment_id > 0 ? '' : ' hidden'; ?>><?php esc_html_e('Remove image', 'trovesia-plugin'); ?></button>
                </p>
                <p class="description"><?php esc_html_e('Changing this replaces the image shown on the storefront review card. The original media-library file is not deleted.', 'trovesia-plugin'); ?></p>
            </div>
        </div>
        <?php
    }

    public function save_review_fields(int $comment_id): void
    {
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['trovesia_review_editor_nonce'] ?? '')));
        if (
            $nonce === ''
            || ! wp_verify_nonce($nonce, 'trovesia_edit_review_' . $comment_id)
            || ! current_user_can('edit_comment', $comment_id)
            || ! $this->is_product_review($comment_id)
        ) {
            return;
        }

        if (isset($_POST['trovesia_featured_review'])) {
            update_comment_meta($comment_id, self::FEATURED_META, '1');
        } else {
            delete_comment_meta($comment_id, self::FEATURED_META);
        }

        $attachment_id = absint($_POST['trovesia_review_media_attachment_id'] ?? 0);
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
        $featured = ['trovesia_featured' => __('Featured', 'trovesia-plugin')];

        if (isset($columns['trovesia_media'])) {
            $media = ['trovesia_media' => $columns['trovesia_media']];
            unset($columns['trovesia_media']);

            return $columns + $featured + $media;
        }

        return $columns + $featured;
    }

    public function render_comment_featured_column(string $column_name, int $comment_id): void
    {
        if ($column_name === 'trovesia_featured') {
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
        <div class="trovesia-product-content-stack">
            <?php if ($featured !== []) : ?>
                <section class="trovesia-featured-reviews" aria-labelledby="trovesia-featured-reviews-heading">
                    <header class="trovesia-review-section__header">
                        <div>
                            <p><?php esc_html_e('Customer favorites', 'trovesia-plugin'); ?></p>
                            <h2 id="trovesia-featured-reviews-heading"><?php esc_html_e('Featured reviews', 'trovesia-plugin'); ?></h2>
                        </div>
                        <div class="trovesia-review-carousel__controls" aria-label="<?php esc_attr_e('Featured review controls', 'trovesia-plugin'); ?>">
                            <button type="button" data-review-carousel-previous aria-label="<?php esc_attr_e('Previous featured review', 'trovesia-plugin'); ?>">&larr;</button>
                            <button type="button" data-review-carousel-next aria-label="<?php esc_attr_e('Next featured review', 'trovesia-plugin'); ?>">&rarr;</button>
                        </div>
                    </header>
                    <div class="trovesia-review-grid trovesia-review-grid--featured" data-review-carousel>
                        <?php foreach ($featured as $comment) : ?>
                            <?php $this->render_review_card($comment, true); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="trovesia-direct-description" id="description" aria-labelledby="trovesia-description-heading">
                <p class="trovesia-section-kicker"><?php esc_html_e('Product details', 'trovesia-plugin'); ?></p>
                <h2 id="trovesia-description-heading"><?php esc_html_e('Description', 'trovesia-plugin'); ?></h2>
                <div class="trovesia-direct-description__content">
                    <?php echo apply_filters('the_content', $product->get_description()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </section>

            <section class="trovesia-all-reviews<?php echo $total === 0 ? ' trovesia-all-reviews--empty' : ''; ?>" id="reviews" <?php echo $total > 0 ? 'aria-labelledby="trovesia-all-reviews-heading"' : 'aria-label="' . esc_attr__('Product reviews', 'trovesia-plugin') . '"'; ?>>
                <?php if ($total === 0) : ?>
                    <?php $this->render_submission_notice(); ?>
                    <button class="trovesia-review-modal-open" type="button" data-review-modal-open><?php esc_html_e('Write a review', 'trovesia-plugin'); ?></button>
                <?php else : ?>
                    <header class="trovesia-review-section__header">
                        <div>
                            <p><?php esc_html_e('Real routines, real feedback', 'trovesia-plugin'); ?></p>
                            <h2 id="trovesia-all-reviews-heading"><?php printf(esc_html__('Customer reviews (%d)', 'trovesia-plugin'), esc_html($total)); ?></h2>
                        </div>
                        <button class="trovesia-review-modal-open" type="button" data-review-modal-open><?php esc_html_e('Write a review', 'trovesia-plugin'); ?></button>
                    </header>

                    <?php $this->render_submission_notice(); ?>

                    <div class="trovesia-review-grid">
                        <?php foreach ($reviews as $comment) : ?>
                            <?php $this->render_review_card($comment); ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($pages > 1) : ?>
                        <nav class="trovesia-review-pagination" aria-label="<?php esc_attr_e('Review pages', 'trovesia-plugin'); ?>">
                            <?php
                            echo wp_kses_post(paginate_links([
                                'base' => add_query_arg('review-page', '%#%', get_permalink($product_id)),
                                'current' => min($page, $pages),
                                'format' => '',
                                'total' => $pages,
                                'type' => 'list',
                                'add_fragment' => '#reviews',
                                'prev_text' => __('Previous', 'trovesia-plugin'),
                                'next_text' => __('Next', 'trovesia-plugin'),
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
        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['trovesia_review_nonce'] ?? '')));

        if ($product_id < 1 || ! wp_verify_nonce($nonce, 'trovesia_submit_review_' . $product_id)) {
            $this->redirect_review($redirect, 'security');
        }

        $product = wc_get_product($product_id);
        if (! $product || get_post_status($product_id) !== 'publish' || ! comments_open($product_id)) {
            $this->redirect_review($redirect, 'closed');
        }

        if (trim((string) ($_POST['trovesia_review_website'] ?? '')) !== '') {
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

        $upload = $_FILES['trovesia_review_image'] ?? null;
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
                'trovesia_review_image',
                $product_id,
                sprintf(__('Review photo from %s', 'trovesia-plugin'), $author)
            );

            if (is_wp_error($attachment_id)) {
                wp_delete_comment((int) $comment_id, true);
                $this->redirect_review($redirect, 'image');
            }

            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', sprintf(__('Review photo from %s', 'trovesia-plugin'), $author));
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
        $verified = get_comment_meta($comment_id, '_trovesia_loox_verified', true) === '1';
        if (! $verified && function_exists('wc_customer_bought_product')) {
            $verified = wc_customer_bought_product(
                (string) $comment->comment_author_email,
                (int) $comment->user_id,
                (int) $comment->comment_post_ID
            );
        }
        ?>
        <article class="trovesia-review-card<?php echo $featured ? ' trovesia-review-card--featured' : ''; ?>">
            <?php if ($attachment_id > 0) : ?>
                <a class="trovesia-review-card__media" href="<?php echo esc_url((string) wp_get_attachment_image_url($attachment_id, 'full')); ?>" target="_blank" rel="noopener">
                    <?php echo wp_get_attachment_image($attachment_id, 'medium_large', false, ['loading' => 'lazy', 'alt' => sprintf(__('Review photo from %s', 'trovesia-plugin'), $comment->comment_author)]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </a>
            <?php endif; ?>
            <div class="trovesia-review-card__body">
                <div class="trovesia-review-card__rating" aria-label="<?php echo esc_attr(sprintf(__('%d out of 5 stars', 'trovesia-plugin'), $rating)); ?>">
                    <span aria-hidden="true"><?php echo esc_html(str_repeat('★', $rating) . str_repeat('☆', 5 - $rating)); ?></span>
                </div>
                <blockquote><?php echo wpautop(esc_html($comment->comment_content)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></blockquote>
                <footer>
                    <span class="trovesia-review-card__avatar" aria-hidden="true"><?php echo esc_html(mb_strtoupper(mb_substr($comment->comment_author, 0, 1))); ?></span>
                    <span><strong><?php echo esc_html($comment->comment_author); ?></strong><small><?php echo esc_html(get_comment_date('', $comment)); ?></small></span>
                    <?php if ($verified) : ?><span class="trovesia-review-card__verified"><?php esc_html_e('Verified', 'trovesia-plugin'); ?></span><?php endif; ?>
                </footer>
            </div>
        </article>
        <?php
    }

    private function render_review_modal(\WC_Product $product): void
    {
        $user = wp_get_current_user();
        ?>
        <dialog class="trovesia-review-modal" id="trovesia-review-modal" aria-labelledby="trovesia-review-modal-title">
            <div class="trovesia-review-modal__panel">
                <button class="trovesia-review-modal__close" type="button" data-review-modal-close aria-label="<?php esc_attr_e('Close review form', 'trovesia-plugin'); ?>">&times;</button>
                <p class="trovesia-section-kicker"><?php esc_html_e('Share your ritual', 'trovesia-plugin'); ?></p>
                <h2 id="trovesia-review-modal-title"><?php esc_html_e('Write a review', 'trovesia-plugin'); ?></h2>
                <p><?php echo esc_html($product->get_name()); ?></p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="trovesia_submit_review">
                    <input type="hidden" name="comment_post_ID" value="<?php echo esc_attr((string) $product->get_id()); ?>">
                    <?php wp_nonce_field('trovesia_submit_review_' . $product->get_id(), 'trovesia_review_nonce'); ?>
                    <input class="trovesia-review-honeypot" name="trovesia_review_website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">

                    <fieldset class="trovesia-review-rating">
                        <legend><?php esc_html_e('Your rating', 'trovesia-plugin'); ?></legend>
                        <div>
                            <?php for ($rating = 5; $rating >= 1; $rating--) : ?>
                                <input id="trovesia-rating-<?php echo esc_attr((string) $rating); ?>" name="rating" type="radio" value="<?php echo esc_attr((string) $rating); ?>" required>
                                <label for="trovesia-rating-<?php echo esc_attr((string) $rating); ?>" title="<?php echo esc_attr(sprintf(__('%d stars', 'trovesia-plugin'), $rating)); ?>">★</label>
                            <?php endfor; ?>
                        </div>
                    </fieldset>

                    <?php if (! $user->exists()) : ?>
                        <div class="trovesia-review-form__row">
                            <label><?php esc_html_e('Name', 'trovesia-plugin'); ?><input name="author" type="text" maxlength="80" autocomplete="name" required></label>
                            <label><?php esc_html_e('Email', 'trovesia-plugin'); ?><input name="email" type="email" maxlength="100" autocomplete="email" required></label>
                        </div>
                    <?php endif; ?>

                    <label><?php esc_html_e('Your review', 'trovesia-plugin'); ?><textarea name="comment" rows="5" maxlength="3000" required></textarea></label>
                    <label class="trovesia-review-upload">
                        <span><strong><?php esc_html_e('Add a photo', 'trovesia-plugin'); ?></strong><small><?php esc_html_e('Optional · JPG, PNG or WebP · maximum 5 MB', 'trovesia-plugin'); ?></small></span>
                        <input name="trovesia_review_image" type="file" accept="image/jpeg,image/png,image/webp" data-review-image-input>
                    </label>
                    <div class="trovesia-review-upload-preview" data-review-image-preview hidden></div>
                    <button class="trovesia-review-submit" type="submit"><?php esc_html_e('Submit review', 'trovesia-plugin'); ?></button>
                </form>
            </div>
        </dialog>
        <?php
    }

    private function render_submission_notice(): void
    {
        $status = sanitize_key((string) ($_GET['review-status'] ?? ''));
        $messages = [
            'published' => __('Thank you. Your review is now live.', 'trovesia-plugin'),
            'moderation' => __('Thank you. Your review was received and is awaiting approval.', 'trovesia-plugin'),
            'required' => __('Please add a rating and review text.', 'trovesia-plugin'),
            'identity' => __('Please enter a valid name and email address.', 'trovesia-plugin'),
            'image' => __('The review photo must be a JPG, PNG or WebP image under 5 MB.', 'trovesia-plugin'),
            'closed' => __('Reviews are not currently open for this product.', 'trovesia-plugin'),
            'security' => __('The review form expired. Please try again.', 'trovesia-plugin'),
            'failed' => __('The review could not be submitted. Please try again.', 'trovesia-plugin'),
        ];

        if (! isset($messages[$status])) {
            return;
        }

        $success = in_array($status, ['published', 'moderation'], true);
        printf(
            '<p class="trovesia-review-notice trovesia-review-notice--%1$s" role="status">%2$s</p>',
            $success ? 'success' : 'error',
            esc_html($messages[$status])
        );
    }

    private function render_featured_status(int $comment_id): void
    {
        if (get_comment_meta($comment_id, self::FEATURED_META, true) === '1') {
            echo '<span class="trovesia-admin-featured-review" title="' . esc_attr__('Featured review', 'trovesia-plugin') . '">★</span>';
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
