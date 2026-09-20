<?php
/**
 * Storefront editor, private uploads, cart data, and order production data.
 */

declare(strict_types=1);

namespace Tikool\FrameCustomizer;

if (! defined('ABSPATH')) {
    exit;
}

final class Customizer
{
    private const MAX_UPLOAD_BYTES = 20 * MB_IN_BYTES;
    private const PRIVATE_DIRECTORY = 'tikool-customer-photos';

    private static ?self $instance = null;

    /** @var array<int, array<string, mixed>> */
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
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_editor'], 4);
        add_filter('woocommerce_available_variation', [$this, 'add_variation_configuration'], 10, 3);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_customization'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 4);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_data'], 10, 4);
        add_action('woocommerce_after_order_itemmeta', [$this, 'render_admin_order_data'], 10, 3);
        add_action('admin_post_tikool_frame_customizer_download', [$this, 'download_order_artwork']);
        add_action('admin_post_tikool_download_customer_photo', [$this, 'download_legacy_order_artwork']);
    }

    public function enqueue_assets(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }
        $product_id = get_queried_object_id();
        if ($product_id < 1 || ! Config::is_enabled($product_id)) {
            return;
        }

        $css = TIKOOL_FC_DIR . 'assets/css/customizer.css';
        $js = TIKOOL_FC_DIR . 'assets/js/customizer.js';
        wp_enqueue_style(
            'tikool-frame-customizer',
            TIKOOL_FC_URL . 'assets/css/customizer.css',
            [],
            is_file($css) ? (string) filemtime($css) : TIKOOL_FC_VERSION
        );
        wp_enqueue_script(
            'tikool-frame-customizer',
            TIKOOL_FC_URL . 'assets/js/customizer.js',
            ['jquery', 'wc-add-to-cart-variation'],
            is_file($js) ? (string) filemtime($js) : TIKOOL_FC_VERSION,
            true
        );
        wp_localize_script('tikool-frame-customizer', 'tikoolFrameCustomizer', [
            'maxUploadBytes' => self::MAX_UPLOAD_BYTES,
            'maxUploadLabel' => size_format(self::MAX_UPLOAD_BYTES),
            'i18n' => [
                'next' => __('Next: upload photo', 'tikool-frame-customizer'),
                'addToCart' => __('Add to cart', 'tikool-frame-customizer'),
                'preparing' => __('Preparing preview…', 'tikool-frame-customizer'),
                'missingVariation' => __('Choose all product options before continuing.', 'tikool-frame-customizer'),
                'missingName' => __('Complete the required name field.', 'tikool-frame-customizer'),
                'missingPhoto' => __('Choose a JPG, PNG, or WebP photo to continue.', 'tikool-frame-customizer'),
                'invalidPhoto' => sprintf(__('Use a JPG, PNG, or WebP image smaller than %s.', 'tikool-frame-customizer'), size_format(self::MAX_UPLOAD_BYTES)),
                'cropError' => __('We could not prepare this crop. Please try another photo.', 'tikool-frame-customizer'),
                'recommended' => __('Recommended image quality', 'tikool-frame-customizer'),
                'lowResolution' => __('This image may be too small for the selected print size.', 'tikool-frame-customizer'),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function add_variation_configuration(array $data, \WC_Product_Variable $product, \WC_Product_Variation $variation): array
    {
        if (Config::is_enabled($product->get_id())) {
            $data['tikool_frame_customizer'] = Config::variation_snapshot($variation);
        }
        return $data;
    }

    public function render_editor(): void
    {
        global $product;
        if (! $product instanceof \WC_Product || ! Config::is_enabled($product->get_id())) {
            return;
        }

        $product_id = $product->get_id();
        $settings = Config::product_settings($product_id);
        $initial = Config::product_snapshot($product);
        $modal_title_id = 'tikool-fc-title-' . $product_id;
        $name_id = 'tikool-fc-name-' . $product_id;
        $photo_id = 'tikool-fc-photo-' . $product_id;
        wp_nonce_field('tikool_frame_customizer_' . $product_id, 'tikool_fc_nonce');
        ?>
        <section
            class="tikool-fc-modal"
            data-tikool-fc-modal
            data-initial-config="<?php echo esc_attr(wp_json_encode($initial)); ?>"
            role="dialog"
            aria-modal="true"
            aria-labelledby="<?php echo esc_attr($modal_title_id); ?>"
            hidden
        >
            <div class="tikool-fc-modal__backdrop" data-tikool-fc-close></div>
            <div class="tikool-fc-modal__dialog" role="document">
                <header class="tikool-fc-modal__header">
                    <div>
                        <p class="tikool-fc-modal__step"><?php esc_html_e('Step 2 of 2 · Personalize', 'tikool-frame-customizer'); ?></p>
                        <h2 id="<?php echo esc_attr($modal_title_id); ?>"><?php esc_html_e('Position your photo', 'tikool-frame-customizer'); ?></h2>
                    </div>
                    <button class="tikool-fc-modal__close" type="button" data-tikool-fc-close aria-label="<?php echo esc_attr__('Close personalization', 'tikool-frame-customizer'); ?>">&times;</button>
                </header>

                <div class="tikool-fc-modal__content">
                    <div class="tikool-fc-preview" data-tikool-fc-preview>
                        <span class="tikool-fc-preview__badge"><?php esc_html_e('Live preview', 'tikool-frame-customizer'); ?></span>
                        <div class="tikool-fc-canvas-wrap" data-tikool-fc-canvas-wrap tabindex="0" aria-label="<?php echo esc_attr__('Editable frame preview. Drag the photo to reposition it.', 'tikool-frame-customizer'); ?>">
                            <canvas data-tikool-fc-canvas></canvas>
                            <div class="tikool-fc-placeholder" data-tikool-fc-placeholder>
                                <span aria-hidden="true">＋</span>
                                <strong><?php esc_html_e('Add a photo', 'tikool-frame-customizer'); ?></strong>
                                <small><?php esc_html_e('Then drag it to position the crop.', 'tikool-frame-customizer'); ?></small>
                            </div>
                        </div>
                        <p class="tikool-fc-preview__hint"><?php esc_html_e('Drag to reposition · use the slider to zoom', 'tikool-frame-customizer'); ?></p>
                    </div>

                    <div class="tikool-fc-controls">
                        <div class="tikool-fc-summary" data-tikool-fc-summary aria-label="<?php echo esc_attr__('Selected product options', 'tikool-frame-customizer'); ?>"></div>

                        <p class="form-row form-row-wide tikool-fc-name-row">
                            <label for="<?php echo esc_attr($name_id); ?>"><?php echo esc_html((string) $settings['name_label']); ?><?php if ($settings['name_required']) : ?> <abbr class="required" title="required">*</abbr><?php endif; ?></label>
                            <input id="<?php echo esc_attr($name_id); ?>" name="tikool_fc_name" type="text" maxlength="100" autocomplete="off" <?php echo $settings['name_required'] ? 'required' : ''; ?>>
                        </p>

                        <div class="tikool-fc-upload">
                            <span class="tikool-fc-upload__label"><?php echo esc_html((string) $settings['photo_label']); ?> <abbr class="required" title="required">*</abbr></span>
                            <input id="<?php echo esc_attr($photo_id); ?>" class="tikool-fc-upload__input" name="tikool_fc_original" type="file" accept="image/jpeg,image/png,image/webp" required data-tikool-fc-photo>
                            <input name="tikool_fc_preview" type="file" accept="image/jpeg" hidden data-tikool-fc-preview-file>
                            <label class="tikool-fc-upload__button" for="<?php echo esc_attr($photo_id); ?>"><?php esc_html_e('Choose photo', 'tikool-frame-customizer'); ?></label>
                            <span class="tikool-fc-upload__filename" data-tikool-fc-filename><?php esc_html_e('No photo selected', 'tikool-frame-customizer'); ?></span>
                            <small><?php echo esc_html(sprintf(__('JPG, PNG, or WebP · maximum %s · the original and crop instructions are stored privately with your order.', 'tikool-frame-customizer'), size_format(self::MAX_UPLOAD_BYTES))); ?></small>
                        </div>

                        <label class="tikool-fc-zoom">
                            <span><?php esc_html_e('Zoom', 'tikool-frame-customizer'); ?></span>
                            <input type="range" min="1" max="3" step="0.01" value="1" data-tikool-fc-zoom disabled>
                        </label>
                        <p class="tikool-fc-quality" data-tikool-fc-quality aria-live="polite"></p>
                        <input type="hidden" name="tikool_fc_crop_recipe" value="" data-tikool-fc-recipe>
                        <p class="tikool-fc-error" data-tikool-fc-error role="alert" aria-live="assertive"></p>
                    </div>
                </div>

                <footer class="tikool-fc-modal__footer">
                    <button class="tikool-fc-back" type="button" data-tikool-fc-close><?php esc_html_e('Back to options', 'tikool-frame-customizer'); ?></button>
                    <div class="tikool-fc-purchase" data-tikool-fc-purchase></div>
                </footer>
            </div>
        </section>
        <?php
    }

    public function validate_customization(bool $valid, int $product_id, int $quantity, int $variation_id = 0, array $variations = []): bool
    {
        unset($quantity, $variations);
        if (! $valid || ! Config::is_enabled($product_id)) {
            return $valid;
        }

        $nonce = sanitize_text_field(wp_unslash((string) ($_POST['tikool_fc_nonce'] ?? '')));
        if (! wp_verify_nonce($nonce, 'tikool_frame_customizer_' . $product_id)) {
            wc_add_notice(__('The customization session expired. Refresh the product page and try again.', 'tikool-frame-customizer'), 'error');
            return false;
        }

        $settings = Config::product_settings($product_id);
        $name = sanitize_text_field(wp_unslash((string) ($_POST['tikool_fc_name'] ?? '')));
        if ($settings['name_required'] && $name === '') {
            wc_add_notice(sprintf(__('Please complete “%s”.', 'tikool-frame-customizer'), $settings['name_label']), 'error');
            return false;
        }

        $recipe = $this->sanitize_recipe(wp_unslash((string) ($_POST['tikool_fc_crop_recipe'] ?? '')));
        if ($recipe === null) {
            wc_add_notice(__('Position and crop your photo before adding this item to cart.', 'tikool-frame-customizer'), 'error');
            return false;
        }

        $original_upload = $_FILES['tikool_fc_original'] ?? null;
        $preview_upload = $_FILES['tikool_fc_preview'] ?? null;
        if (! is_array($original_upload) || ! is_array($preview_upload)) {
            wc_add_notice(__('Upload a valid photo before adding this item to cart.', 'tikool-frame-customizer'), 'error');
            return false;
        }

        $original = $this->store_private_image($original_upload, 'original');
        if ($original === null) {
            wc_add_notice(__('The original photo could not be accepted. Use a JPG, PNG, or WebP image within the upload limit.', 'tikool-frame-customizer'), 'error');
            return false;
        }

        $preview = $this->store_private_image($preview_upload, 'preview');
        if ($preview === null) {
            @unlink($original['path']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            wc_add_notice(__('The crop preview could not be prepared. Please try again.', 'tikool-frame-customizer'), 'error');
            return false;
        }

        $customized_product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (! $customized_product instanceof \WC_Product) {
            @unlink($original['path']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink($preview['path']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return false;
        }

        $snapshot = $customized_product instanceof \WC_Product_Variation
            ? Config::variation_snapshot($customized_product)
            : Config::product_snapshot($customized_product);
        $quality = $this->image_quality($original, $snapshot);

        $this->pending_uploads[$product_id] = [
            'schema_version' => Config::SCHEMA_VERSION,
            'name' => $name,
            'name_label' => (string) $settings['name_label'],
            'original_path' => $original['path'],
            'original_name' => $original['name'],
            'preview_path' => $preview['path'],
            'preview_name' => $preview['name'],
            'recipe' => $recipe,
            'snapshot' => $snapshot,
            'quality' => $quality,
        ];

        return true;
    }

    /**
     * @param array<string, mixed> $cart_item_data
     * @return array<string, mixed>
     */
    public function add_cart_item_data(array $cart_item_data, int $product_id, int $variation_id, int $quantity): array
    {
        unset($variation_id, $quantity);
        if (! isset($this->pending_uploads[$product_id])) {
            return $cart_item_data;
        }

        $cart_item_data['_tikool_frame_customization'] = $this->pending_uploads[$product_id];
        $cart_item_data['_tikool_frame_customization_key'] = wp_generate_uuid4();
        unset($this->pending_uploads[$product_id]);
        return $cart_item_data;
    }

    /**
     * @param array<int, array<string, string>> $item_data
     * @param array<string, mixed> $cart_item
     * @return array<int, array<string, string>>
     */
    public function display_cart_item_data(array $item_data, array $cart_item): array
    {
        $customization = $cart_item['_tikool_frame_customization'] ?? null;
        if (! is_array($customization)) {
            return $item_data;
        }

        $name = sanitize_text_field((string) ($customization['name'] ?? ''));
        if ($name !== '') {
            $item_data[] = [
                'key' => sanitize_text_field((string) ($customization['name_label'] ?? __('Name', 'tikool-frame-customizer'))),
                'value' => esc_html($name),
                'display' => esc_html($name),
            ];
        }
        $item_data[] = [
            'key' => __('Custom photo', 'tikool-frame-customizer'),
            'value' => __('Crop received', 'tikool-frame-customizer'),
            'display' => __('Crop received', 'tikool-frame-customizer'),
        ];

        return $item_data;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save_order_item_data(\WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order): void
    {
        unset($cart_item_key, $order);
        $customization = $values['_tikool_frame_customization'] ?? null;
        if (! is_array($customization)) {
            return;
        }

        $name = sanitize_text_field((string) ($customization['name'] ?? ''));
        if ($name !== '') {
            $item->add_meta_data(sanitize_text_field((string) ($customization['name_label'] ?? __('Name', 'tikool-frame-customizer'))), $name, true);
        }
        $item->add_meta_data(__('Custom photo', 'tikool-frame-customizer'), __('Uploaded securely', 'tikool-frame-customizer'), true);
        $item->add_meta_data('_tikool_fc_schema_version', (int) ($customization['schema_version'] ?? Config::SCHEMA_VERSION), true);
        $item->add_meta_data('_tikool_fc_original_path', sanitize_text_field((string) ($customization['original_path'] ?? '')), true);
        $item->add_meta_data('_tikool_fc_original_name', sanitize_file_name((string) ($customization['original_name'] ?? 'customer-photo')), true);
        $item->add_meta_data('_tikool_fc_preview_path', sanitize_text_field((string) ($customization['preview_path'] ?? '')), true);
        $item->add_meta_data('_tikool_fc_preview_name', sanitize_file_name((string) ($customization['preview_name'] ?? 'crop-preview.jpg')), true);
        $item->add_meta_data('_tikool_fc_crop_recipe', wp_json_encode($customization['recipe'] ?? []), true);
        $item->add_meta_data('_tikool_fc_snapshot', wp_json_encode($customization['snapshot'] ?? []), true);
        $item->add_meta_data('_tikool_fc_quality', wp_json_encode($customization['quality'] ?? []), true);
    }

    public function render_admin_order_data(int $item_id, \WC_Order_Item $item, ?\WC_Product $product): void
    {
        unset($product);
        if (! is_admin() || ! current_user_can('manage_woocommerce') || ! $item instanceof \WC_Order_Item_Product) {
            return;
        }

        $order_id = $item->get_order_id();
        $original_path = (string) $item->get_meta('_tikool_fc_original_path', true);
        $preview_path = (string) $item->get_meta('_tikool_fc_preview_path', true);
        if ($order_id > 0 && $original_path !== '' && is_file($original_path)) {
            echo '<p class="tikool-fc-order-downloads">';
            echo '<a class="button" href="' . esc_url($this->download_url($order_id, $item_id, 'original')) . '">' . esc_html__('Download original photo', 'tikool-frame-customizer') . '</a> ';
            if ($preview_path !== '' && is_file($preview_path)) {
                echo '<a class="button" href="' . esc_url($this->download_url($order_id, $item_id, 'preview')) . '">' . esc_html__('Download crop preview', 'tikool-frame-customizer') . '</a>';
            }
            echo '</p>';
            $this->render_production_summary($item);
            return;
        }

        $legacy_path = (string) $item->get_meta('_tikool_pet_photo_path', true);
        if ($order_id > 0 && $legacy_path !== '' && is_file($legacy_path)) {
            $legacy_url = wp_nonce_url(
                add_query_arg(['action' => 'tikool_download_customer_photo', 'order_id' => $order_id, 'item_id' => $item_id], admin_url('admin-post.php')),
                'tikool_download_customer_photo_' . $order_id . '_' . $item_id
            );
            echo '<p><a class="button" href="' . esc_url($legacy_url) . '">' . esc_html__('Download legacy customer photo', 'tikool-frame-customizer') . '</a></p>';
        }
    }

    public function download_order_artwork(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to download this file.', 'tikool-frame-customizer'), 403);
        }
        $order_id = absint($_GET['order_id'] ?? 0);
        $item_id = absint($_GET['item_id'] ?? 0);
        $type = sanitize_key((string) ($_GET['type'] ?? 'original'));
        if (! in_array($type, ['original', 'preview'], true)
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'tikool_fc_download_' . $order_id . '_' . $item_id . '_' . $type)) {
            wp_die(esc_html__('This download link is invalid or expired.', 'tikool-frame-customizer'), 403);
        }
        $order = wc_get_order($order_id);
        $item = $order instanceof \WC_Order ? $order->get_item($item_id) : null;
        $path = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_fc_' . $type . '_path', true) : '';
        $name = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_fc_' . $type . '_name', true) : '';
        $this->stream_private_file($path, $name ?: $type . '-photo');
    }

    public function download_legacy_order_artwork(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to download this file.', 'tikool-frame-customizer'), 403);
        }
        $order_id = absint($_GET['order_id'] ?? 0);
        $item_id = absint($_GET['item_id'] ?? 0);
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'tikool_download_customer_photo_' . $order_id . '_' . $item_id)) {
            wp_die(esc_html__('This download link is invalid or expired.', 'tikool-frame-customizer'), 403);
        }
        $order = wc_get_order($order_id);
        $item = $order instanceof \WC_Order ? $order->get_item($item_id) : null;
        $path = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_pet_photo_path', true) : '';
        $name = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_pet_photo_original_name', true) : '';
        $this->stream_private_file($path, $name ?: 'pet-photo');
    }

    /**
     * @param array<string, mixed> $upload
     * @return array{path: string, name: string, width: int, height: int}|null
     */
    private function store_private_image(array $upload, string $kind): ?array
    {
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        $size = (int) ($upload['size'] ?? 0);
        $temporary = (string) ($upload['tmp_name'] ?? '');
        $original_name = sanitize_file_name((string) ($upload['name'] ?? $kind . '-photo'));
        if ($size < 1 || $size > self::MAX_UPLOAD_BYTES || ! is_uploaded_file($temporary)) {
            return null;
        }

        $allowed = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $checked = wp_check_filetype_and_ext($temporary, $original_name, $allowed);
        $dimensions = @getimagesize($temporary); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if (empty($checked['ext']) || empty($checked['type']) || ! is_array($dimensions)) {
            return null;
        }

        $directory = WP_CONTENT_DIR . '/uploads/' . self::PRIVATE_DIRECTORY;
        if (! wp_mkdir_p($directory)) {
            return null;
        }
        $destination = trailingslashit($directory) . wp_generate_password(32, false, false) . '-' . $kind . '.' . $checked['ext'];
        if (! move_uploaded_file($temporary, $destination)) {
            return null;
        }

        return [
            'path' => $destination,
            'name' => $original_name,
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
        ];
    }

    /**
     * @return array<string, float>|null
     */
    private function sanitize_recipe(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }
        $recipe = [];
        foreach (['x', 'y', 'width', 'height', 'zoom'] as $key) {
            if (! isset($decoded[$key]) || ! is_numeric($decoded[$key])) {
                return null;
            }
            $recipe[$key] = (float) $decoded[$key];
        }
        if ($recipe['x'] < 0 || $recipe['y'] < 0 || $recipe['width'] <= 0 || $recipe['height'] <= 0
            || $recipe['x'] + $recipe['width'] > 1.001 || $recipe['y'] + $recipe['height'] > 1.001
            || $recipe['zoom'] < 1 || $recipe['zoom'] > 3.01) {
            return null;
        }
        return $recipe;
    }

    /**
     * @param array{width: int, height: int} $original
     * @param array<string, mixed> $snapshot
     * @return array<string, float|int|bool>
     */
    private function image_quality(array $original, array $snapshot): array
    {
        $geometry = (array) ($snapshot['geometry'] ?? []);
        $print_width_inches = max(0.01, (float) ($geometry['print_width_mm'] ?? 1) / 25.4);
        $print_height_inches = max(0.01, (float) ($geometry['print_height_mm'] ?? 1) / 25.4);
        $effective_dpi = min($original['width'] / $print_width_inches, $original['height'] / $print_height_inches);
        $minimum = (float) ($geometry['min_dpi'] ?? 180);
        return [
            'source_width' => $original['width'],
            'source_height' => $original['height'],
            'effective_dpi' => round($effective_dpi, 1),
            'minimum_dpi' => $minimum,
            'meets_minimum' => $effective_dpi >= $minimum,
        ];
    }

    private function download_url(int $order_id, int $item_id, string $type): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => 'tikool_frame_customizer_download', 'order_id' => $order_id, 'item_id' => $item_id, 'type' => $type], admin_url('admin-post.php')),
            'tikool_fc_download_' . $order_id . '_' . $item_id . '_' . $type
        );
    }

    private function stream_private_file(string $path, string $name): void
    {
        if ($path === '' || ! $this->is_private_path($path) || ! is_file($path)) {
            wp_die(esc_html__('The customer artwork is unavailable.', 'tikool-frame-customizer'), 404);
        }
        $filename = sanitize_file_name($name) ?: 'customer-artwork';
        $mime = (string) wp_check_filetype($filename)['type'];
        nocache_headers();
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        readfile($path);
        exit;
    }

    private function is_private_path(string $path): bool
    {
        $directory = realpath(WP_CONTENT_DIR . '/uploads/' . self::PRIVATE_DIRECTORY);
        $file = realpath($path);
        return $directory !== false && $file !== false && str_starts_with($file, trailingslashit($directory));
    }

    private function render_production_summary(\WC_Order_Item_Product $item): void
    {
        $snapshot = json_decode((string) $item->get_meta('_tikool_fc_snapshot', true), true);
        $quality = json_decode((string) $item->get_meta('_tikool_fc_quality', true), true);
        if (! is_array($snapshot)) {
            return;
        }
        $geometry = (array) ($snapshot['geometry'] ?? []);
        echo '<details class="tikool-fc-production-summary"><summary>' . esc_html__('Production specification', 'tikool-frame-customizer') . '</summary><table class="widefat striped" style="margin-top:8px;max-width:620px"><tbody>';
        $rows = [
            __('Template', 'tikool-frame-customizer') => (string) ($snapshot['templateLabel'] ?? $snapshot['template'] ?? ''),
            __('Outer frame', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['outer_width_mm'] ?? '—', $geometry['outer_height_mm'] ?? '—'),
            __('Inner opening', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['inner_width_mm'] ?? '—', $geometry['inner_height_mm'] ?? '—'),
            __('Visible artwork', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['artwork_width_mm'] ?? '—', $geometry['artwork_height_mm'] ?? '—'),
            __('Print size', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['print_width_mm'] ?? '—', $geometry['print_height_mm'] ?? '—'),
            __('Mat borders T/R/B/L', 'tikool-frame-customizer') => sprintf('%s / %s / %s / %s mm', $geometry['mat_top_mm'] ?? 0, $geometry['mat_right_mm'] ?? 0, $geometry['mat_bottom_mm'] ?? 0, $geometry['mat_left_mm'] ?? 0),
            __('Mat thickness', 'tikool-frame-customizer') => sprintf('%s mm', $geometry['mat_thickness_mm'] ?? 0),
        ];
        if (is_array($quality)) {
            $rows[__('Source image', 'tikool-frame-customizer')] = sprintf('%s × %s px', $quality['source_width'] ?? '—', $quality['source_height'] ?? '—');
            $rows[__('Estimated DPI', 'tikool-frame-customizer')] = sprintf('%s (%s)', $quality['effective_dpi'] ?? '—', ! empty($quality['meets_minimum']) ? __('acceptable', 'tikool-frame-customizer') : __('review required', 'tikool-frame-customizer'));
        }
        foreach ($rows as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }
}
