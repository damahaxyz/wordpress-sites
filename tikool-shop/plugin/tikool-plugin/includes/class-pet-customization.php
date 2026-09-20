<?php
/**
 * Pet-photo personalization collected with a WooCommerce order.
 *
 * Customer reference images remain outside the public upload path. Store staff
 * can download them only from the corresponding WooCommerce order screen.
 *
 * @package TikoolShopPlugin
 */

declare(strict_types=1);

namespace Tikool\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Pet_Customization
{
    private const PRODUCT_META = '_tikool_requires_pet_photo';
    private const MAX_UPLOAD_BYTES = 10 * MB_IN_BYTES;

    private static ?self $instance = null;

    /** @var array<int, array{pet_name: string, file: string, original_name: string}> */
    private array $pending_uploads = [];

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
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_setting']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_setting']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_fields'], 4);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_customization'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 4);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_data'], 10, 4);
        add_action('woocommerce_after_order_itemmeta', [$this, 'render_admin_photo_link'], 10, 3);
        add_action('admin_post_tikool_download_customer_photo', [$this, 'download_customer_photo']);
    }

    public function render_product_setting(): void
    {
        woocommerce_wp_checkbox([
            'id'          => self::PRODUCT_META,
            'label'       => __('Require pet photo', 'tikool-plugin'),
            'description' => __('Show a required pet name and photo upload before this product can be added to cart.', 'tikool-plugin'),
        ]);
    }

    public function save_product_setting(int $product_id): void
    {
        update_post_meta($product_id, self::PRODUCT_META, isset($_POST[self::PRODUCT_META]) ? 'yes' : 'no');
    }

    public function enqueue_assets(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        $product_id = get_queried_object_id();
        if ($product_id < 1 || ! $this->requires_customization($product_id)) {
            return;
        }

        $css = TIKOOL_PLUGIN_DIR . 'assets/css/pet-customization.css';
        $js = TIKOOL_PLUGIN_DIR . 'assets/js/pet-customization.js';
        wp_enqueue_style(
            'tikool-pet-customization',
            TIKOOL_PLUGIN_URL . 'assets/css/pet-customization.css',
            [],
            is_file($css) ? (string) filemtime($css) : TIKOOL_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tikool-pet-customization',
            TIKOOL_PLUGIN_URL . 'assets/js/pet-customization.js',
            ['jquery', 'wc-add-to-cart-variation'],
            is_file($js) ? (string) filemtime($js) : TIKOOL_PLUGIN_VERSION,
            true
        );
    }

    public function render_fields(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || ! $this->requires_customization($product->get_id())) {
            return;
        }

        $product_id = $product->get_id();
        $modal_title_id = 'tikool-personalization-title-' . $product_id;
        $pet_name_id = 'tikool-pet-name-' . $product_id;
        $pet_photo_id = 'tikool-pet-photo-' . $product_id;
        wp_nonce_field('tikool_pet_customization_' . $product_id, 'tikool_pet_customization_nonce');
        ?>
        <section
            class="tikool-personalization-modal"
            data-tikool-personalization-modal
            data-missing-variation="<?php echo esc_attr__('Choose all product options before continuing.', 'tikool-plugin'); ?>"
            data-missing-name="<?php echo esc_attr__('Enter your pet\'s name.', 'tikool-plugin'); ?>"
            data-missing-photo="<?php echo esc_attr__('Choose a JPG, PNG, or WebP photo to continue.', 'tikool-plugin'); ?>"
            data-invalid-photo="<?php echo esc_attr__('Use a JPG, PNG, or WebP image smaller than 10 MB.', 'tikool-plugin'); ?>"
            data-crop-error="<?php echo esc_attr__('We could not prepare this crop. Please try another photo.', 'tikool-plugin'); ?>"
            role="dialog"
            aria-modal="true"
            aria-labelledby="<?php echo esc_attr($modal_title_id); ?>"
            hidden
        >
            <div class="tikool-personalization-modal__backdrop" data-tikool-modal-close></div>
            <div class="tikool-personalization-modal__dialog" role="document">
                <header class="tikool-personalization-modal__header">
                    <div>
                        <p class="tikool-personalization-modal__step"><?php esc_html_e('Step 2 of 2 · Personalize', 'tikool-plugin'); ?></p>
                        <h2 id="<?php echo esc_attr($modal_title_id); ?>"><?php esc_html_e('Position your pet photo', 'tikool-plugin'); ?></h2>
                    </div>
                    <button class="tikool-personalization-modal__close" type="button" data-tikool-modal-close aria-label="<?php echo esc_attr__('Close personalization', 'tikool-plugin'); ?>">&times;</button>
                </header>

                <div class="tikool-personalization-modal__content">
                    <div class="tikool-crop-preview" data-tikool-crop-preview data-frame="natural" data-mat="mat">
                        <span class="tikool-crop-preview__badge"><?php esc_html_e('Live preview', 'tikool-plugin'); ?></span>
                        <div class="tikool-frame-preview">
                            <div class="tikool-frame-preview__mat">
                                <div class="tikool-crop-viewport" data-tikool-crop-viewport tabindex="0" aria-label="<?php echo esc_attr__('Photo crop area. Drag the image to reposition it.', 'tikool-plugin'); ?>">
                                    <img class="tikool-crop-image" data-tikool-crop-image alt="" draggable="false">
                                    <div class="tikool-crop-placeholder" data-tikool-crop-placeholder>
                                        <span aria-hidden="true">＋</span>
                                        <strong><?php esc_html_e('Add a pet photo', 'tikool-plugin'); ?></strong>
                                        <small><?php esc_html_e('Then drag to position it inside the frame.', 'tikool-plugin'); ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="tikool-crop-preview__hint"><?php esc_html_e('Drag to reposition · use the slider to zoom', 'tikool-plugin'); ?></p>
                    </div>

                    <div class="tikool-personalization-controls">
                        <div class="tikool-personalization-summary" aria-label="<?php echo esc_attr__('Selected product options', 'tikool-plugin'); ?>">
                            <div><span><?php esc_html_e('Size', 'tikool-plugin'); ?></span><strong data-tikool-summary-size>—</strong></div>
                            <div><span><?php esc_html_e('Frame', 'tikool-plugin'); ?></span><strong data-tikool-summary-frame>—</strong></div>
                            <div><span><?php esc_html_e('Mat', 'tikool-plugin'); ?></span><strong data-tikool-summary-mat>—</strong></div>
                            <div><span><?php esc_html_e('Price', 'tikool-plugin'); ?></span><strong data-tikool-summary-price>—</strong></div>
                        </div>

                        <p class="form-row form-row-wide">
                            <label for="<?php echo esc_attr($pet_name_id); ?>"><?php esc_html_e("Pet's name", 'tikool-plugin'); ?> <abbr class="required" title="required">*</abbr></label>
                            <input id="<?php echo esc_attr($pet_name_id); ?>" name="tikool_pet_name" type="text" maxlength="80" autocomplete="off" required>
                        </p>

                        <div class="tikool-photo-upload">
                            <span class="tikool-photo-upload__label"><?php esc_html_e('Pet photo', 'tikool-plugin'); ?> <abbr class="required" title="required">*</abbr></span>
                            <input id="<?php echo esc_attr($pet_photo_id); ?>" class="tikool-photo-upload__input" name="tikool_pet_photo" type="file" accept="image/jpeg,image/png,image/webp" required data-tikool-photo-input>
                            <label class="tikool-photo-upload__button" for="<?php echo esc_attr($pet_photo_id); ?>"><?php esc_html_e('Choose photo', 'tikool-plugin'); ?></label>
                            <span class="tikool-photo-upload__filename" data-tikool-photo-filename><?php esc_html_e('No photo selected', 'tikool-plugin'); ?></span>
                            <small><?php esc_html_e('JPG, PNG, or WebP · maximum 10 MB · the final crop is stored privately with your order.', 'tikool-plugin'); ?></small>
                        </div>

                        <label class="tikool-zoom-control">
                            <span><?php esc_html_e('Zoom', 'tikool-plugin'); ?></span>
                            <input type="range" min="1" max="3" step="0.01" value="1" data-tikool-crop-zoom disabled>
                        </label>
                        <input type="hidden" name="tikool_crop_data" value="" data-tikool-crop-data>
                        <p class="tikool-personalization-error" data-tikool-personalization-error role="alert" aria-live="polite"></p>
                    </div>
                </div>

                <footer class="tikool-personalization-modal__footer">
                    <button class="tikool-personalization-back" type="button" data-tikool-modal-close><?php esc_html_e('Back to options', 'tikool-plugin'); ?></button>
                    <div class="tikool-personalization-purchase" data-tikool-purchase-slot></div>
                </footer>
            </div>
        </section>
        <?php
    }

    public function validate_customization(bool $valid, int $product_id, int $quantity, int $variation_id = 0, array $variations = []): bool
    {
        if (! $valid || ! $this->requires_customization($product_id)) {
            return $valid;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['tikool_pet_customization_nonce'] ?? '')));
        $pet_name = sanitize_text_field(wp_unslash((string) ($_POST['tikool_pet_name'] ?? '')));
        if (! wp_verify_nonce($nonce, 'tikool_pet_customization_' . $product_id) || $pet_name === '') {
            wc_add_notice(__('Please enter your pet\'s name before adding this item to cart.', 'tikool-plugin'), 'error');
            return false;
        }

        $upload = $_FILES['tikool_pet_photo'] ?? null;
        if (! is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wc_add_notice(__('Please upload one clear pet photo before adding this item to cart.', 'tikool-plugin'), 'error');
            return false;
        }

        $file = $this->store_private_photo($upload);
        if ($file === null) {
            wc_add_notice(__('Your photo could not be accepted. Use a JPG, PNG, or WebP image smaller than 10 MB.', 'tikool-plugin'), 'error');
            return false;
        }

        $this->pending_uploads[$product_id] = [
            'pet_name'      => $pet_name,
            'file'          => $file,
            'original_name' => sanitize_file_name((string) ($upload['name'] ?? 'pet-photo')),
        ];

        return true;
    }

    /**
     * @param array<string, mixed> $cart_item_data
     * @return array<string, mixed>
     */
    public function add_cart_item_data(array $cart_item_data, int $product_id, int $variation_id, int $quantity): array
    {
        if (! isset($this->pending_uploads[$product_id])) {
            return $cart_item_data;
        }

        $customization = $this->pending_uploads[$product_id];
        unset($this->pending_uploads[$product_id]);
        $cart_item_data['_tikool_pet_customization'] = $customization;
        $cart_item_data['_tikool_pet_customization_key'] = wp_generate_uuid4();

        return $cart_item_data;
    }

    /**
     * @param array<int, array<string, string>> $item_data
     * @param array<string, mixed> $cart_item
     * @return array<int, array<string, string>>
     */
    public function display_cart_item_data(array $item_data, array $cart_item): array
    {
        $customization = $cart_item['_tikool_pet_customization'] ?? null;
        if (! is_array($customization)) {
            return $item_data;
        }

        $item_data[] = [
            'key'     => __('Pet name', 'tikool-plugin'),
            'value'   => esc_html((string) ($customization['pet_name'] ?? '')),
            'display' => esc_html((string) ($customization['pet_name'] ?? '')),
        ];
        $item_data[] = [
            'key'     => __('Final photo crop', 'tikool-plugin'),
            'value'   => __('Crop received', 'tikool-plugin'),
            'display' => __('Crop received', 'tikool-plugin'),
        ];

        return $item_data;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $cart_item_key
     */
    public function save_order_item_data(\WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order): void
    {
        $customization = $values['_tikool_pet_customization'] ?? null;
        if (! is_array($customization)) {
            return;
        }

        $item->add_meta_data(__('Pet name', 'tikool-plugin'), sanitize_text_field((string) ($customization['pet_name'] ?? '')), true);
        $item->add_meta_data(__('Final photo crop', 'tikool-plugin'), __('Uploaded securely', 'tikool-plugin'), true);
        $item->add_meta_data('_tikool_pet_photo_path', sanitize_text_field((string) ($customization['file'] ?? '')), true);
        $item->add_meta_data('_tikool_pet_photo_original_name', sanitize_file_name((string) ($customization['original_name'] ?? 'pet-photo')), true);
    }

    public function render_admin_photo_link(int $item_id, \WC_Order_Item $item, ?\WC_Product $product): void
    {
        if (! is_admin() || ! current_user_can('manage_woocommerce') || ! $item instanceof \WC_Order_Item_Product) {
            return;
        }

        $path = (string) $item->get_meta('_tikool_pet_photo_path', true);
        $order_id = $item->get_order_id();
        if ($path === '' || $order_id < 1 || ! is_file($path)) {
            return;
        }

        $url = wp_nonce_url(
            add_query_arg([
                'action'   => 'tikool_download_customer_photo',
                'order_id' => $order_id,
                'item_id'  => $item_id,
            ], admin_url('admin-post.php')),
            'tikool_download_customer_photo_' . $order_id . '_' . $item_id
        );
        printf('<p><a class="button" href="%s">%s</a></p>', esc_url($url), esc_html__('Download customer photo', 'tikool-plugin'));
    }

    public function download_customer_photo(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to download this file.', 'tikool-plugin'), 403);
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        $item_id = absint($_GET['item_id'] ?? 0);
        if ($order_id < 1 || $item_id < 1 || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'tikool_download_customer_photo_' . $order_id . '_' . $item_id)) {
            wp_die(esc_html__('This download link is invalid or expired.', 'tikool-plugin'), 403);
        }

        $order = wc_get_order($order_id);
        $item = $order instanceof \WC_Order ? $order->get_item($item_id) : null;
        $path = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_pet_photo_path', true) : '';
        if ($path === '' || ! $this->is_private_path($path) || ! is_file($path)) {
            wp_die(esc_html__('The customer photo is unavailable.', 'tikool-plugin'), 404);
        }

        $filename = sanitize_file_name((string) $item->get_meta('_tikool_pet_photo_original_name', true)) ?: 'pet-photo';
        $mime = (string) wp_check_filetype($filename)['type'];
        nocache_headers();
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        readfile($path);
        exit;
    }

    private function requires_customization(int $product_id): bool
    {
        $parent_id = wp_get_post_parent_id($product_id);
        return get_post_meta($product_id, self::PRODUCT_META, true) === 'yes'
            || ($parent_id > 0 && get_post_meta($parent_id, self::PRODUCT_META, true) === 'yes');
    }

    /**
     * @param array<string, mixed> $upload
     */
    private function store_private_photo(array $upload): ?string
    {
        $size = (int) ($upload['size'] ?? 0);
        $temporary_file = (string) ($upload['tmp_name'] ?? '');
        $original_name = (string) ($upload['name'] ?? '');
        if ($size < 1 || $size > self::MAX_UPLOAD_BYTES || ! is_uploaded_file($temporary_file)) {
            return null;
        }

        $allowed = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $checked = wp_check_filetype_and_ext($temporary_file, $original_name, $allowed);
        if (empty($checked['ext']) || empty($checked['type']) || ! @getimagesize($temporary_file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return null;
        }

        $directory = WP_CONTENT_DIR . '/uploads/tikool-customer-photos';
        if (! wp_mkdir_p($directory)) {
            return null;
        }

        $destination = trailingslashit($directory) . wp_generate_password(32, false, false) . '.' . $checked['ext'];
        return move_uploaded_file($temporary_file, $destination) ? $destination : null;
    }

    private function is_private_path(string $path): bool
    {
        $directory = realpath(WP_CONTENT_DIR . '/uploads/tikool-customer-photos');
        $file = realpath($path);
        return $directory !== false && $file !== false && str_starts_with($file, trailingslashit($directory));
    }
}
