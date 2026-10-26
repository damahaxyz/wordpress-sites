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
    private const NAME_MIN_SIZE_PERCENT = 3.0;
    private const NAME_MAX_SIZE_PERCENT = 12.0;

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
        add_action('woocommerce_after_variations_table', [$this, 'render_nfc_notice'], 20);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_editor'], 4);
        add_filter('woocommerce_available_variation', [$this, 'add_variation_configuration'], 10, 3);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_customization'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 4);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_filter('woocommerce_cart_item_thumbnail', [$this, 'cart_item_thumbnail'], 10, 3);
        add_filter('woocommerce_store_api_cart_item_images', [$this, 'store_api_cart_item_images'], 10, 3);
        add_filter('woocommerce_paypal_payments_selected_button_locations', [$this, 'exclude_product_express_payment'], 10, 2);
        add_filter('woocommerce_paypal_payments_product_supports_payment_request_button', [$this, 'paypal_product_supports_express_payment'], 10, 2);
        add_filter('wc_stripe_hide_payment_request_on_product_page', [$this, 'hide_stripe_product_express_payment'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_data'], 10, 4);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_admin_order_item_meta']);
        add_action('woocommerce_after_order_itemmeta', [$this, 'render_admin_order_data'], 10, 3);
        add_action('admin_post_tikool_frame_customizer_download', [$this, 'download_order_artwork']);
        add_action('admin_post_tikool_download_customer_photo', [$this, 'download_legacy_order_artwork']);
        add_action('admin_post_tikool_frame_customizer_cart_preview', [$this, 'stream_cart_preview']);
        add_action('admin_post_nopriv_tikool_frame_customizer_cart_preview', [$this, 'stream_cart_preview']);
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
        $product_settings = Config::product_settings($product_id);
        $is_blanket = ($product_settings['renderer'] ?? 'frame') === 'blanket';

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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'maxUploadBytes' => self::MAX_UPLOAD_BYTES,
            'maxUploadLabel' => size_format(self::MAX_UPLOAD_BYTES),
            'i18n' => [
                'next' => __('Next: upload photo', 'tikool-frame-customizer'),
                'addToCart' => __('Add to cart', 'tikool-frame-customizer'),
                'preparing' => __('Preparing preview…', 'tikool-frame-customizer'),
                'missingVariation' => __('Choose all product options before continuing.', 'tikool-frame-customizer'),
                'missingName' => __('Enter your pet’s name.', 'tikool-frame-customizer'),
                'missingPhoto' => __('Choose a JPG, PNG, or WebP photo to continue.', 'tikool-frame-customizer'),
                'invalidPhoto' => sprintf(__('Use a JPG, PNG, or WebP image smaller than %s.', 'tikool-frame-customizer'), size_format(self::MAX_UPLOAD_BYTES)),
                'cropError' => __('We could not prepare this crop. Please try another photo.', 'tikool-frame-customizer'),
                'generatingStyle' => $is_blanket
                    ? __('Creating your %s blanket artwork… This usually takes about 50 seconds.', 'tikool-frame-customizer')
                    : __('Creating your %s portrait… This usually takes about 50 seconds.', 'tikool-frame-customizer'),
                'aiLoadingTitle' => $is_blanket
                    ? __('Creating your %s blanket artwork', 'tikool-frame-customizer')
                    : __('Creating your %s portrait', 'tikool-frame-customizer'),
                'aiLoadingMessages' => [
                    __('Studying your pet’s unique markings…', 'tikool-frame-customizer'),
                    __('Building the composition and background…', 'tikool-frame-customizer'),
                    __('Refining the fur, eyes, and small details…', 'tikool-frame-customizer'),
                    __('Preparing your high-resolution preview…', 'tikool-frame-customizer'),
                ],
                'aiLoadingKeepOpen' => $is_blanket
                    ? __('Keep this window open. Your blanket artwork will appear here automatically.', 'tikool-frame-customizer')
                    : __('Keep this window open. Your portrait will appear here automatically.', 'tikool-frame-customizer'),
                'styleReady' => $is_blanket
                    ? __('%s blanket preview is ready. You can reposition or zoom it before adding to cart.', 'tikool-frame-customizer')
                    : __('%s preview is ready. You can reposition or zoom it before adding to cart.', 'tikool-frame-customizer'),
                'creatingSelectedBackground' => __('Creating %s background…', 'tikool-frame-customizer'),
                'backgroundReady' => __('%s background is ready. Use the buttons beside the preview to switch.', 'tikool-frame-customizer'),
                'styleFailed' => __('We could not generate that style. Your original photo has been restored.', 'tikool-frame-customizer'),
                'tryAgain' => __('Try again', 'tikool-frame-customizer'),
                'jobStatusError' => __('We temporarily lost the status connection. The portrait is still processing; use Try again only after the current request finishes.', 'tikool-frame-customizer'),
                'retryCountdown' => __('You can try another AI style in %s.', 'tikool-frame-customizer'),
                'retryReady' => __('You can try an AI style again now.', 'tikool-frame-customizer'),
                'waitForStyle' => __('Wait for the AI portrait preview to finish before adding to cart.', 'tikool-frame-customizer'),
                'uploadingPhoto' => __('Preparing your photo…', 'tikool-frame-customizer'),
                'chooseStyle' => __('Choose a style. AI styles are generated once for this photo.', 'tikool-frame-customizer'),
                'noPhoto' => __('No photo selected', 'tikool-frame-customizer'),
                'recommended' => __('Recommended image quality', 'tikool-frame-customizer'),
                'lowResolution' => __('This image may be too small for the selected print size.', 'tikool-frame-customizer'),
                'largePreviewError' => __('We could not open the large preview. Please try again.', 'tikool-frame-customizer'),
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

    public function render_nfc_notice(): void
    {
        global $product;
        if (! $product instanceof \WC_Product
            || ! Config::is_enabled($product->get_id())
            || get_post_meta($product->get_id(), '_tikool_memory_page_enabled', true) !== 'yes') {
            return;
        }
        ?>
        <aside class="tikool-fc-nfc-notice tikool-fc-nfc-notice--product" data-tikool-fc-nfc-notice aria-label="<?php echo esc_attr__('NFC Memory Page information', 'tikool-frame-customizer'); ?>">
            <span class="tikool-fc-nfc-notice__badge" aria-hidden="true">NFC</span>
            <div>
                <strong><?php esc_html_e('Create your Memory Page after payment', 'tikool-frame-customizer'); ?></strong>
                <p><?php esc_html_e('After payment, upload the photos, videos, and audio you want to include. Our team will help create your Memory Page, and you can update it later whenever you need.', 'tikool-frame-customizer'); ?></p>
            </div>
        </aside>
        <?php
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
        $renderer = sanitize_key((string) ($settings['renderer'] ?? 'frame')) ?: 'frame';
        $is_blanket = $renderer === 'blanket';
        $is_keepsake = $renderer === 'keepsake';
        $modal_title_id = 'tikool-fc-title-' . $product_id;
        $large_preview_title_id = 'tikool-fc-large-preview-title-' . $product_id;
        $name_id = 'tikool-fc-name-' . $product_id;
        $name_overlay_id = 'tikool-fc-name-overlay-' . $product_id;
        $name_font_id = 'tikool-fc-name-font-' . $product_id;
        $name_color_id = 'tikool-fc-name-color-' . $product_id;
        $name_outline_id = 'tikool-fc-name-outline-' . $product_id;
        $name_outline_color_id = 'tikool-fc-name-outline-color-' . $product_id;
        $photo_id = 'tikool-fc-photo-' . $product_id;
        $portrait_styles = AI_Styles::styles($product_id);
        wp_nonce_field('tikool_frame_customizer_' . $product_id, 'tikool_fc_nonce');
        ?>
        <section
            class="tikool-fc-modal tikool-fc-modal--<?php echo esc_attr($renderer); ?>"
            data-tikool-fc-modal
            data-renderer="<?php echo esc_attr($renderer); ?>"
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
                        <h2 id="<?php echo esc_attr($modal_title_id); ?>"><?php echo esc_html($is_keepsake ? __('Personalize your keepsake box', 'tikool-frame-customizer') : ($is_blanket ? __('Create your custom blanket', 'tikool-frame-customizer') : __('Create your pet portrait', 'tikool-frame-customizer'))); ?></h2>
                    </div>
                    <button class="tikool-fc-modal__close" type="button" data-tikool-fc-close aria-label="<?php echo esc_attr__('Close personalization', 'tikool-frame-customizer'); ?>">&times;</button>
                </header>

                <div class="tikool-fc-modal__content">
                    <div class="tikool-fc-preview" data-tikool-fc-preview>
                        <div class="tikool-fc-preview__actions">
                            <a class="tikool-fc-view-source" data-tikool-fc-view-source href="" target="_blank" rel="noopener noreferrer" hidden>
                                <span aria-hidden="true">↗</span>
                                <?php esc_html_e('View source file', 'tikool-frame-customizer'); ?>
                            </a>
                            <button class="tikool-fc-view-large" type="button" data-tikool-fc-view-large disabled>
                                <span aria-hidden="true">⛶</span>
                                <?php esc_html_e('View larger', 'tikool-frame-customizer'); ?>
                            </button>
                        </div>
                        <?php if ($is_keepsake) : ?>
                            <div class="tikool-fc-keepsake-views" role="group" aria-label="<?php echo esc_attr__('Keepsake preview view', 'tikool-frame-customizer'); ?>">
                                <button type="button" class="is-active" data-tikool-fc-keepsake-view="inside" aria-pressed="true"><?php esc_html_e('Inside photo', 'tikool-frame-customizer'); ?></button>
                                <button type="button" data-tikool-fc-keepsake-view="engraving" aria-pressed="false"><?php esc_html_e('Lid engraving', 'tikool-frame-customizer'); ?></button>
                            </div>
                        <?php endif; ?>
                        <div class="tikool-fc-canvas-wrap" data-tikool-fc-canvas-wrap tabindex="0" aria-label="<?php echo esc_attr($is_keepsake ? __('Editable keepsake-box preview. Use Inside photo to crop the picture and Lid engraving to review the paw and pet name.', 'tikool-frame-customizer') : ($is_blanket ? __('Editable blanket preview. Drag the photo or its printed name to reposition. Use Alt and arrow keys to move the name.', 'tikool-frame-customizer') : __('Editable frame preview. Drag the photo or its printed name to reposition. Use Alt and arrow keys to move the name.', 'tikool-frame-customizer'))); ?>">
                            <canvas data-tikool-fc-canvas></canvas>
                            <button class="tikool-fc-placeholder" type="button" data-tikool-fc-placeholder aria-label="<?php echo esc_attr__('Choose a pet photo', 'tikool-frame-customizer'); ?>">
                                <span aria-hidden="true">＋</span>
                                <strong><?php esc_html_e('Add a photo', 'tikool-frame-customizer'); ?></strong>
                                <small><?php esc_html_e('Then drag it to position the crop.', 'tikool-frame-customizer'); ?></small>
                            </button>
                            <div class="tikool-fc-ai-loading" data-tikool-fc-ai-loading role="status" aria-live="polite" aria-atomic="true" hidden>
                                <div class="tikool-fc-ai-loading__visual" aria-hidden="true">
                                    <span class="tikool-fc-ai-loading__ring"></span>
                                    <span class="tikool-fc-ai-loading__ring tikool-fc-ai-loading__ring--inner"></span>
                                    <span class="tikool-fc-ai-loading__mark">AI</span>
                                </div>
                                <p class="tikool-fc-ai-loading__eyebrow"><?php echo esc_html($is_blanket ? __('Your blanket artwork is in the studio', 'tikool-frame-customizer') : __('Your portrait is in the studio', 'tikool-frame-customizer')); ?></p>
                                <strong class="tikool-fc-ai-loading__title" data-tikool-fc-ai-loading-title><?php echo esc_html($is_blanket ? __('Creating your blanket artwork', 'tikool-frame-customizer') : __('Creating your portrait', 'tikool-frame-customizer')); ?></strong>
                                <p class="tikool-fc-ai-loading__estimate"><?php esc_html_e('Usually ready in about 50 seconds', 'tikool-frame-customizer'); ?></p>
                                <p class="tikool-fc-ai-loading__message" data-tikool-fc-ai-loading-message><?php esc_html_e('Studying your pet’s unique markings…', 'tikool-frame-customizer'); ?></p>
                                <span class="tikool-fc-ai-loading__progress" aria-hidden="true"><i></i></span>
                                <small><?php echo esc_html($is_blanket ? __('Keep this window open. Your blanket artwork will appear here automatically.', 'tikool-frame-customizer') : __('Keep this window open. Your portrait will appear here automatically.', 'tikool-frame-customizer')); ?></small>
                            </div>
                        </div>
                        <p class="tikool-fc-preview__hint"><?php echo esc_html($is_keepsake ? __('Inside photo: drag to reposition and use the slider to zoom · Lid engraving: fixed paw + pet name', 'tikool-frame-customizer') : __('Drag to reposition · use the slider to zoom', 'tikool-frame-customizer')); ?></p>
                        <div class="tikool-fc-variants tikool-fc-preview__variants" data-tikool-fc-variants hidden>
                            <p class="tikool-fc-variants__title"><?php esc_html_e('Background', 'tikool-frame-customizer'); ?></p>
                            <div class="tikool-fc-variants__grid" data-tikool-fc-variants-grid></div>
                        </div>
                        <label class="tikool-fc-zoom tikool-fc-preview__zoom">
                            <span><?php esc_html_e('Zoom', 'tikool-frame-customizer'); ?></span>
                            <input type="range" min="1" max="3" step="0.01" value="1" data-tikool-fc-zoom disabled>
                        </label>
                    </div>

                    <div class="tikool-fc-controls">
                        <div class="tikool-fc-summary" data-tikool-fc-summary aria-label="<?php echo esc_attr__('Selected product options', 'tikool-frame-customizer'); ?>"></div>

                        <div class="tikool-fc-pet-name">
                            <label class="tikool-fc-pet-name__label" for="<?php echo esc_attr($name_id); ?>"><?php echo esc_html((string) $settings['name_label']); ?> <abbr class="required" title="required">*</abbr></label>
                            <input id="<?php echo esc_attr($name_id); ?>" name="tikool_fc_name" type="text" maxlength="<?php echo esc_attr((string) ($settings['name_max_length'] ?? 100)); ?>" autocomplete="off" required>
                            <small><?php echo esc_html($is_keepsake ? sprintf(__('Required for laser engraving. Maximum %d characters; a paw icon is added automatically.', 'tikool-frame-customizer'), (int) ($settings['name_max_length'] ?? 15)) : __('Required for your order. You can choose below whether to print this name on the artwork.', 'tikool-frame-customizer')); ?></small>
                        </div>

                        <div class="tikool-fc-upload">
                            <span class="tikool-fc-upload__label"><?php echo esc_html((string) $settings['photo_label']); ?> <abbr class="required" title="required">*</abbr></span>
                            <input id="<?php echo esc_attr($photo_id); ?>" class="tikool-fc-upload__input" name="tikool_fc_original" type="file" accept="image/jpeg,image/png,image/webp" required data-tikool-fc-photo>
                            <input name="tikool_fc_preview" type="file" accept="image/jpeg" hidden data-tikool-fc-preview-file>
                            <input name="tikool_fc_cart_preview" type="file" accept="image/jpeg" hidden data-tikool-fc-cart-preview-file>
                            <label class="tikool-fc-upload__button" for="<?php echo esc_attr($photo_id); ?>"><?php esc_html_e('Choose photo', 'tikool-frame-customizer'); ?></label>
                            <span class="tikool-fc-upload__filename" data-tikool-fc-filename><?php esc_html_e('No photo selected', 'tikool-frame-customizer'); ?></span>
                            <small><?php echo esc_html(sprintf(__('JPG, PNG, or WebP · maximum %s · the original and crop instructions are stored privately with your order.', 'tikool-frame-customizer'), size_format(self::MAX_UPLOAD_BYTES))); ?></small>
                        </div>

                        <fieldset class="tikool-fc-styles" data-tikool-fc-styles<?php echo $is_keepsake ? ' hidden' : ''; ?>>
                            <legend><?php echo esc_html($is_blanket ? __('Artwork style', 'tikool-frame-customizer') : __('Portrait style', 'tikool-frame-customizer')); ?></legend>
                            <p class="tikool-fc-styles__help"><?php echo esc_html($is_blanket ? __('Choose Original or create an AI blanket preview. Each generated style is prepared only once for this photo.', 'tikool-frame-customizer') : __('Choose Original or an AI style. Watercolor starts with its first background; switch backgrounds beside the preview.', 'tikool-frame-customizer')); ?></p>
                            <div class="tikool-fc-styles__grid">
                                <?php foreach ($portrait_styles as $style_key => $style) : ?>
                                    <?php $is_original = $style_key === 'original'; ?>
                                    <?php $preview_image_id = absint($style['preview_image_id'] ?? 0); ?>
                                    <?php
                                    $style_variants = [];
                                    foreach ((array) ($style['variants'] ?? []) as $variant_key => $variant) {
                                        if (! is_array($variant)) {
                                            continue;
                                        }
                                        $clean_variant_key = sanitize_key((string) $variant_key);
                                        if ($clean_variant_key !== '') {
                                            $style_variants[] = [
                                                'key' => $clean_variant_key,
                                                'label' => sanitize_text_field((string) ($variant['label'] ?? $clean_variant_key)),
                                            ];
                                        }
                                    }
                                    ?>
                                    <label class="tikool-fc-style tikool-fc-style--<?php echo esc_attr($style_key); ?>">
                                        <input
                                            type="radio"
                                            name="tikool_fc_style"
                                            value="<?php echo esc_attr($style_key); ?>"
                                            data-tikool-fc-style
                                            data-style-label="<?php echo esc_attr((string) ($style['short_label'] ?? $style['label'] ?? $style_key)); ?>"
                                            data-style-variants="<?php echo esc_attr(wp_json_encode($style_variants)); ?>"
                                            <?php checked($is_original); ?>
                                            <?php disabled(! $is_original); ?>
                                        >
                                        <span class="tikool-fc-style__art<?php echo $preview_image_id > 0 ? ' has-image' : ''; ?>" aria-hidden="true">
                                            <?php if ($preview_image_id > 0) : ?>
                                                <?php echo wp_get_attachment_image($preview_image_id, 'thumbnail', false, ['class' => 'tikool-fc-style__image', 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tikool-fc-style__copy">
                                            <strong><?php echo esc_html((string) ($style['short_label'] ?? $style['label'] ?? $style_key)); ?></strong>
                                            <small><?php echo esc_html((string) ($style['description'] ?? '')); ?></small>
                                        </span>
                                        <span class="tikool-fc-style__check" aria-hidden="true"></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="tikool-fc-styles__status" data-tikool-fc-style-status aria-live="polite"><?php esc_html_e('Upload a photo to unlock AI styles.', 'tikool-frame-customizer'); ?></p>
                            <button class="tikool-fc-style-retry" type="button" data-tikool-fc-style-retry hidden><?php esc_html_e('Try again', 'tikool-frame-customizer'); ?></button>
                        </fieldset>

                        <div class="tikool-fc-name-overlay"<?php echo $is_keepsake ? ' hidden' : ''; ?>>
                            <label class="tikool-fc-name-overlay__toggle" for="<?php echo esc_attr($name_overlay_id); ?>">
                                <input id="<?php echo esc_attr($name_overlay_id); ?>" type="checkbox" data-tikool-fc-name-overlay aria-controls="tikool-fc-name-options-<?php echo esc_attr((string) $product_id); ?>" aria-expanded="false">
                                <span><?php echo esc_html($is_blanket ? __('Add a name to the blanket', 'tikool-frame-customizer') : __('Add pet name to the portrait', 'tikool-frame-customizer')); ?></span>
                            </label>
                            <p class="tikool-fc-name-overlay__help"><?php echo esc_html($is_blanket ? __('Optional: print the pet name inside the blanket artwork. You can drag it in the preview to move it.', 'tikool-frame-customizer') : __('Optional: print the pet name inside the photo. You can drag it in the preview to move it.', 'tikool-frame-customizer')); ?></p>
                            <div id="tikool-fc-name-options-<?php echo esc_attr((string) $product_id); ?>" class="tikool-fc-name-overlay__options" data-tikool-fc-name-options hidden>
                                <label for="<?php echo esc_attr($name_font_id); ?>"><?php esc_html_e('Font', 'tikool-frame-customizer'); ?></label>
                                <select id="<?php echo esc_attr($name_font_id); ?>" data-tikool-fc-name-font>
                                    <?php foreach (self::name_fonts() as $font_key => $font_label) : ?>
                                        <option value="<?php echo esc_attr($font_key); ?>"><?php echo esc_html($font_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="tikool-fc-name-overlay__outline" for="<?php echo esc_attr($name_outline_id); ?>">
                                    <input id="<?php echo esc_attr($name_outline_id); ?>" type="checkbox" checked data-tikool-fc-name-outline>
                                    <span><?php esc_html_e('Add an outline for better contrast', 'tikool-frame-customizer'); ?></span>
                                </label>
                                <div class="tikool-fc-name-overlay__colors">
                                    <label class="tikool-fc-name-overlay__color" for="<?php echo esc_attr($name_color_id); ?>">
                                        <span><?php esc_html_e('Text color', 'tikool-frame-customizer'); ?></span>
                                        <input id="<?php echo esc_attr($name_color_id); ?>" type="color" value="#2d2522" data-tikool-fc-name-color>
                                    </label>
                                    <label class="tikool-fc-name-overlay__color" for="<?php echo esc_attr($name_outline_color_id); ?>" data-tikool-fc-name-outline-color-wrap>
                                        <span><?php esc_html_e('Outline color', 'tikool-frame-customizer'); ?></span>
                                        <input id="<?php echo esc_attr($name_outline_color_id); ?>" type="color" value="#ffffff" data-tikool-fc-name-outline-color>
                                    </label>
                                </div>
                                <label class="tikool-fc-name-overlay__size">
                                    <span><?php esc_html_e('Text size', 'tikool-frame-customizer'); ?> <output data-tikool-fc-name-size-value>6%</output></span>
                                    <input type="range" min="3" max="12" step="0.5" value="6" data-tikool-fc-name-size>
                                </label>
                            </div>
                        </div>
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
            <div class="tikool-fc-lightbox" data-tikool-fc-lightbox aria-hidden="true" hidden>
                <button class="tikool-fc-lightbox__backdrop" type="button" data-tikool-fc-lightbox-close tabindex="-1" aria-label="<?php echo esc_attr__('Close large preview', 'tikool-frame-customizer'); ?>"></button>
                <div class="tikool-fc-lightbox__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($large_preview_title_id); ?>">
                    <header class="tikool-fc-lightbox__header">
                        <div>
                            <span><?php esc_html_e('Your custom artwork', 'tikool-frame-customizer'); ?></span>
                            <h3 id="<?php echo esc_attr($large_preview_title_id); ?>"><?php echo esc_html($is_keepsake ? __('Large keepsake-box preview', 'tikool-frame-customizer') : ($is_blanket ? __('Large blanket preview', 'tikool-frame-customizer') : __('Large portrait preview', 'tikool-frame-customizer'))); ?></h3>
                        </div>
                        <button class="tikool-fc-lightbox__close" type="button" data-tikool-fc-lightbox-close aria-label="<?php echo esc_attr__('Close large preview', 'tikool-frame-customizer'); ?>">&times;</button>
                    </header>
                    <div class="tikool-fc-lightbox__image-wrap" data-tikool-fc-large-stage tabindex="0" aria-label="<?php echo esc_attr__('Zoomable portrait preview. Drag to inspect the image, or use the zoom controls.', 'tikool-frame-customizer'); ?>">
                        <img data-tikool-fc-large-image draggable="false" alt="<?php echo esc_attr($is_blanket ? __('Large preview of your customized blanket', 'tikool-frame-customizer') : __('Large preview of your customized framed portrait', 'tikool-frame-customizer')); ?>">
                    </div>
                    <div class="tikool-fc-lightbox__toolbar" aria-label="<?php echo esc_attr__('Large preview controls', 'tikool-frame-customizer'); ?>">
                        <button type="button" data-tikool-fc-large-zoom-out aria-label="<?php echo esc_attr__('Zoom out', 'tikool-frame-customizer'); ?>">−</button>
                        <output data-tikool-fc-large-zoom-value>100%</output>
                        <button type="button" data-tikool-fc-large-zoom-in aria-label="<?php echo esc_attr__('Zoom in', 'tikool-frame-customizer'); ?>">＋</button>
                        <button class="tikool-fc-lightbox__toolbar-text" type="button" data-tikool-fc-large-actual><?php esc_html_e('Actual size', 'tikool-frame-customizer'); ?></button>
                        <button class="tikool-fc-lightbox__toolbar-text" type="button" data-tikool-fc-large-fit><?php esc_html_e('Fit', 'tikool-frame-customizer'); ?></button>
                        <small><?php esc_html_e('Drag or pinch to inspect details', 'tikool-frame-customizer'); ?></small>
                    </div>
                </div>
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

        $name = sanitize_text_field(wp_unslash((string) ($_POST['tikool_fc_name'] ?? '')));
        $settings = Config::product_settings($product_id);
        $is_keepsake = sanitize_key((string) ($settings['renderer'] ?? 'frame')) === 'keepsake';
        $raw_recipe = wp_unslash((string) ($_POST['tikool_fc_crop_recipe'] ?? ''));
        if ($name === '') {
            wc_add_notice(__('Enter your pet’s name.', 'tikool-frame-customizer'), 'error');
            return false;
        }
        $name_length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        $name_max_length = max(1, (int) ($settings['name_max_length'] ?? 100));
        if ($is_keepsake && ($name_length > $name_max_length || preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s&\'’.-]*$/u', $name) !== 1)) {
            wc_add_notice(sprintf(__('Use no more than %d letters or numbers for the engraved pet name. Spaces, apostrophes, periods, ampersands, and hyphens are allowed.', 'tikool-frame-customizer'), $name_max_length), 'error');
            return false;
        }

        $recipe = $this->sanitize_recipe($raw_recipe, $name);
        if ($recipe === null) {
            wc_add_notice(__('Review the photo crop and pet-name settings before adding this item to cart.', 'tikool-frame-customizer'), 'error');
            return false;
        }
        if ($is_keepsake) {
            $recipe['nameOverlay'] = ['enabled' => false];
        }

        $styles = AI_Styles::styles($product_id);
        $style_key = $is_keepsake ? 'original' : sanitize_key((string) ($_POST['tikool_fc_style'] ?? 'original'));
        if (! isset($styles[$style_key])) {
            wc_add_notice(__('Choose a valid portrait style.', 'tikool-frame-customizer'), 'error');
            return false;
        }
        $style_label = sanitize_text_field((string) ($styles[$style_key]['label'] ?? $style_key));
        $variants = (array) ($styles[$style_key]['variants'] ?? []);
        $variant_key = sanitize_key((string) ($recipe['variant'] ?? ''));
        if ($variants !== [] && $variant_key === '') {
            $variant_key = (string) array_key_first($variants);
        }
        if ($variants !== [] && (! isset($variants[$variant_key]) || ! is_array($variants[$variant_key]))) {
            wc_add_notice(__('Choose a valid portrait background.', 'tikool-frame-customizer'), 'error');
            return false;
        }
        if ($variants === []) {
            $variant_key = '';
        }
        $variant_label = $variant_key !== ''
            ? sanitize_text_field((string) ($variants[$variant_key]['label'] ?? $variant_key))
            : '';
        $recipe['style'] = $style_key;
        $recipe['variant'] = $variant_key;

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

        $cart_preview_upload = $_FILES['tikool_fc_cart_preview'] ?? null;
        $cart_preview = is_array($cart_preview_upload) && (int) ($cart_preview_upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ? $this->store_private_image($cart_preview_upload, 'cart-preview')
            : null;

        $customized_product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (! $customized_product instanceof \WC_Product) {
            @unlink($original['path']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink($preview['path']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if (is_array($cart_preview)) {
                @unlink($cart_preview['path']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
            return false;
        }

        $snapshot = $customized_product instanceof \WC_Product_Variation
            ? Config::variation_snapshot($customized_product)
            : Config::product_snapshot($customized_product);
        $quality = $this->image_quality($original, $snapshot);

        $this->pending_uploads[$product_id] = [
            'schema_version' => Config::SCHEMA_VERSION,
            'renderer' => sanitize_key((string) ($snapshot['renderer'] ?? 'frame')),
            'template' => sanitize_key((string) ($snapshot['template'] ?? 'wall-frame')),
            'name' => $name,
            'name_label' => (string) $settings['name_label'],
            'style_key' => $style_key,
            'style_label' => $style_label,
            'variant_key' => $variant_key,
            'variant_label' => $variant_label,
            'original_path' => $original['path'],
            'original_name' => $original['name'],
            'preview_path' => $preview['path'],
            'preview_name' => $preview['name'],
            'cart_preview_path' => $cart_preview['path'] ?? $preview['path'],
            'cart_preview_name' => $cart_preview['name'] ?? $preview['name'],
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
        $style_label = sanitize_text_field((string) ($customization['style_label'] ?? ''));
        $variant_label = sanitize_text_field((string) ($customization['variant_label'] ?? ''));
        $renderer = sanitize_key((string) ($customization['renderer'] ?? 'frame')) ?: 'frame';
        $is_blanket = $renderer === 'blanket';
        $is_keepsake = $renderer === 'keepsake';
        if ($style_label !== '' && ! $is_keepsake) {
            $item_data[] = [
                'key' => $is_blanket ? __('Artwork style', 'tikool-frame-customizer') : __('Portrait style', 'tikool-frame-customizer'),
                'value' => esc_html($style_label),
                'display' => esc_html($style_label),
            ];
        }
        if ($variant_label !== '') {
            $item_data[] = [
                'key' => __('Portrait background', 'tikool-frame-customizer'),
                'value' => esc_html($variant_label),
                'display' => esc_html($variant_label),
            ];
        }
        if ($name !== '') {
            $item_data[] = [
                'key' => sanitize_text_field((string) ($customization['name_label'] ?? __('Name', 'tikool-frame-customizer'))),
                'value' => esc_html($name),
                'display' => esc_html($name),
            ];
        }
        if ($is_keepsake && $name !== '') {
            $item_data[] = [
                'key' => __('Lid engraving', 'tikool-frame-customizer'),
                'value' => esc_html(sprintf(__('Paw + %s', 'tikool-frame-customizer'), $name)),
                'display' => esc_html(sprintf(__('Paw + %s', 'tikool-frame-customizer'), $name)),
            ];
        }
        $recipe = (array) ($customization['recipe'] ?? []);
        $overlay = (array) ($recipe['nameOverlay'] ?? []);
        if (! empty($overlay['enabled'])) {
            $fonts = self::name_fonts();
            $appearance = [
                $fonts[$overlay['font'] ?? ''] ?? $fonts['georgia'],
                (string) ($overlay['sizePercent'] ?? 6) . '%',
                sprintf(__('Text %s', 'tikool-frame-customizer'), strtoupper((string) ($overlay['color'] ?? '#2d2522'))),
            ];
            if (! empty($overlay['outlineEnabled'])) {
                $appearance[] = sprintf(__('Outline %s', 'tikool-frame-customizer'), strtoupper((string) ($overlay['outlineColor'] ?? '#ffffff')));
            } else {
                $appearance[] = __('No outline', 'tikool-frame-customizer');
            }
            $item_data[] = [
                'key' => __('Name on portrait', 'tikool-frame-customizer'),
                'value' => esc_html(implode(' · ', $appearance)),
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
     * Keep express payment on cart/checkout, but not ahead of the required photo editor.
     *
     * @param string[] $locations
     * @return string[]
     */
    public function exclude_product_express_payment(array $locations, string $setting = ''): array
    {
        unset($setting);
        if (! $this->is_customized_product_page()) {
            return $locations;
        }
        return array_values(array_diff($locations, ['product']));
    }

    public function paypal_product_supports_express_payment(bool $enabled, \WC_Product $product): bool
    {
        return Config::is_enabled($product->get_id()) ? false : $enabled;
    }

    public function hide_stripe_product_express_payment(bool $hide, $post = null): bool
    {
        $product_id = $post instanceof \WP_Post ? $post->ID : 0;
        return ($product_id > 0 && Config::is_enabled($product_id)) || $this->is_customized_product_page() ? true : $hide;
    }

    private function is_customized_product_page(): bool
    {
        return function_exists('is_product') && is_product() && Config::is_enabled(get_queried_object_id());
    }

    /**
     * @param array<string, mixed> $cart_item
     */
    public function cart_item_thumbnail(string $thumbnail, array $cart_item, string $cart_item_key): string
    {
        if (! $this->cart_item_has_preview($cart_item)) {
            return $thumbnail;
        }
        return sprintf(
            '<img class="tikool-fc-cart-preview" src="%s" alt="%s" width="96" height="96" loading="lazy" decoding="async" style="object-fit:contain;background:#f7f3eb">',
            esc_url($this->cart_preview_url($cart_item_key)),
            esc_attr__('Your personalized portrait preview', 'tikool-frame-customizer')
        );
    }

    /**
     * @param array<int, object> $images
     * @param array<string, mixed> $cart_item
     * @return array<int, object>
     */
    public function store_api_cart_item_images(array $images, array $cart_item, string $cart_item_key): array
    {
        if (! $this->cart_item_has_preview($cart_item)) {
            return $images;
        }
        $url = $this->cart_preview_url($cart_item_key);
        $product = $cart_item['data'] ?? null;
        return [(object) [
            'id' => $product instanceof \WC_Product ? (int) $product->get_image_id() : 0,
            'src' => $url,
            'thumbnail' => $url,
            'srcset' => '',
            'sizes' => '',
            'thumbnail_srcset' => '',
            'thumbnail_sizes' => '',
            'name' => __('Personalized portrait preview', 'tikool-frame-customizer'),
            'alt' => __('Your personalized portrait preview', 'tikool-frame-customizer'),
        ]];
    }

    public function stream_cart_preview(): void
    {
        $key = sanitize_text_field(wp_unslash((string) ($_GET['cart_item_key'] ?? '')));
        if (! preg_match('/^[a-f0-9]{32}$/', $key)) {
            wp_die(esc_html__('Image unavailable.', 'tikool-frame-customizer'), '', ['response' => 404]);
        }
        if (function_exists('wc_load_cart') && (WC()->session === null || WC()->cart === null)) {
            wc_load_cart();
        }
        $session_cart = WC()->session ? WC()->session->get('cart', []) : [];
        $cart_item = is_array($session_cart) ? ($session_cart[$key] ?? null) : null;
        if (! is_array($cart_item) || ! $this->cart_item_has_preview($cart_item)) {
            wp_die(esc_html__('Image unavailable.', 'tikool-frame-customizer'), '', ['response' => 404]);
        }
        $path = $this->cart_item_preview_path($cart_item);
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="portrait-preview.jpg"');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($path);
        exit;
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
        $style_key = sanitize_key((string) ($customization['style_key'] ?? 'original'));
        $style_label = sanitize_text_field((string) ($customization['style_label'] ?? ''));
        $variant_key = sanitize_key((string) ($customization['variant_key'] ?? ''));
        $variant_label = sanitize_text_field((string) ($customization['variant_label'] ?? ''));
        $renderer = sanitize_key((string) ($customization['renderer'] ?? 'frame')) ?: 'frame';
        if ($style_label !== '' && $renderer !== 'keepsake') {
            $item->add_meta_data($renderer === 'blanket' ? __('Artwork style', 'tikool-frame-customizer') : __('Portrait style', 'tikool-frame-customizer'), $style_label, true);
        }
        if ($variant_label !== '') {
            $item->add_meta_data(__('Portrait background', 'tikool-frame-customizer'), $variant_label, true);
        }
        $item->add_meta_data('_tikool_fc_style', $style_key, true);
        $item->add_meta_data('_tikool_fc_style_label', $style_label, true);
        $item->add_meta_data('_tikool_fc_variant', $variant_key, true);
        $item->add_meta_data('_tikool_fc_variant_label', $variant_label, true);
        $item->add_meta_data('_tikool_fc_renderer', $renderer, true);
        $item->add_meta_data('_tikool_fc_template', sanitize_key((string) ($customization['template'] ?? 'wall-frame')), true);
        $item->add_meta_data('_tikool_fc_schema_version', (int) ($customization['schema_version'] ?? Config::SCHEMA_VERSION), true);
        $item->add_meta_data('_tikool_fc_original_path', sanitize_text_field((string) ($customization['original_path'] ?? '')), true);
        $item->add_meta_data('_tikool_fc_original_name', sanitize_file_name((string) ($customization['original_name'] ?? 'customer-photo')), true);
        $item->add_meta_data('_tikool_fc_preview_path', sanitize_text_field((string) ($customization['preview_path'] ?? '')), true);
        $item->add_meta_data('_tikool_fc_preview_name', sanitize_file_name((string) ($customization['preview_name'] ?? 'artwork-preview.jpg')), true);
        $item->add_meta_data('_tikool_fc_cart_preview_path', sanitize_text_field((string) ($customization['cart_preview_path'] ?? '')), true);
        $item->add_meta_data('_tikool_fc_cart_preview_name', sanitize_file_name((string) ($customization['cart_preview_name'] ?? 'framed-preview.jpg')), true);
        $item->add_meta_data('_tikool_fc_crop_recipe', wp_json_encode($customization['recipe'] ?? []), true);
        $item->add_meta_data('_tikool_fc_snapshot', wp_json_encode($customization['snapshot'] ?? []), true);
        $item->add_meta_data('_tikool_fc_quality', wp_json_encode($customization['quality'] ?? []), true);
        if ($renderer === 'keepsake' && $name !== '') {
            $item->add_meta_data(__('Lid engraving', 'tikool-frame-customizer'), sprintf(__('Paw + %s', 'tikool-frame-customizer'), $name), true);
            $engraving = $this->create_engraving_assets($name, (array) ($customization['snapshot'] ?? []));
            foreach ($engraving as $key => $value) {
                $item->add_meta_data('_tikool_fc_' . $key, $value, true);
            }
        }
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
        $cart_preview_path = (string) $item->get_meta('_tikool_fc_cart_preview_path', true);
        $has_cart_preview = $cart_preview_path !== '' && $this->is_private_path($cart_preview_path) && is_file($cart_preview_path);
        $has_preview = $preview_path !== '' && $this->is_private_path($preview_path) && is_file($preview_path);
        if ($order_id > 0 && ($has_cart_preview || $has_preview)) {
            $type = $has_cart_preview ? 'cart-preview' : 'preview';
            $renderer = sanitize_key((string) $item->get_meta('_tikool_fc_renderer', true)) ?: 'frame';
            $label = $has_cart_preview
                ? ($renderer === 'keepsake' ? __('收藏盒定制效果预览', 'tikool-frame-customizer') : ($renderer === 'blanket' ? __('毛毯效果预览', 'tikool-frame-customizer') : __('相框效果预览', 'tikool-frame-customizer')))
                : __('画面预览', 'tikool-frame-customizer');
            $image_url = $this->download_url($order_id, $item_id, $type, true);
            echo '<figure class="tikool-fc-order-preview" style="margin:12px 0;max-width:220px">';
            echo '<a href="' . esc_url($image_url) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr(sprintf(__('查看%s大图', 'tikool-frame-customizer'), $label)) . '">';
            echo '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($label) . '" loading="lazy" style="display:block;width:100%;height:auto;max-height:260px;object-fit:contain;border:1px solid #dcdcde;border-radius:4px;background:#fff" />';
            echo '</a><figcaption style="margin-top:5px;color:#646970">' . esc_html($label . ' · ' . __('点击查看大图', 'tikool-frame-customizer')) . '</figcaption></figure>';
        }
        if ($order_id > 0 && $original_path !== '' && is_file($original_path)) {
            echo '<p class="tikool-fc-order-downloads">';
            echo '<a class="button" href="' . esc_url($this->download_url($order_id, $item_id, 'original')) . '">' . esc_html__('下载顾客原图', 'tikool-frame-customizer') . '</a> ';
            if ($preview_path !== '' && is_file($preview_path)) {
                $renderer = sanitize_key((string) $item->get_meta('_tikool_fc_renderer', true)) ?: 'frame';
                echo '<a class="button" href="' . esc_url($this->download_url($order_id, $item_id, 'preview')) . '">' . esc_html($renderer === 'keepsake' ? __('下载打印照片', 'tikool-frame-customizer') : __('下载画面预览图', 'tikool-frame-customizer')) . '</a>';
            }
            if ($cart_preview_path !== '' && $cart_preview_path !== $preview_path && is_file($cart_preview_path)) {
                $renderer = sanitize_key((string) $item->get_meta('_tikool_fc_renderer', true)) ?: 'frame';
                echo ' <a class="button" href="' . esc_url($this->download_url($order_id, $item_id, 'cart-preview')) . '">' . esc_html($renderer === 'keepsake' ? __('下载收藏盒效果图', 'tikool-frame-customizer') : ($renderer === 'blanket' ? __('下载毛毯效果图', 'tikool-frame-customizer') : __('下载相框效果图', 'tikool-frame-customizer'))) . '</a>';
            }
            foreach (['engraving-svg' => __('下载雕刻 SVG', 'tikool-frame-customizer'), 'engraving-png' => __('下载雕刻 PNG', 'tikool-frame-customizer')] as $type => $label) {
                $meta_type = str_replace('-', '_', $type);
                $asset_path = (string) $item->get_meta('_tikool_fc_' . $meta_type . '_path', true);
                if ($asset_path !== '' && $this->is_private_path($asset_path) && is_file($asset_path)) {
                    echo ' <a class="button" href="' . esc_url($this->download_url($order_id, $item_id, $type)) . '">' . esc_html($label) . '</a>';
                }
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
            echo '<p><a class="button" href="' . esc_url($legacy_url) . '">' . esc_html__('下载旧版顾客照片', 'tikool-frame-customizer') . '</a></p>';
        }
    }

    /**
     * @param string[] $hidden_keys
     * @return string[]
     */
    public function hide_admin_order_item_meta(array $hidden_keys): array
    {
        return array_values(array_unique(array_merge($hidden_keys, [
            '_tikool_fc_schema_version',
            '_tikool_fc_original_path',
            '_tikool_fc_original_name',
            '_tikool_fc_preview_path',
            '_tikool_fc_preview_name',
            '_tikool_fc_cart_preview_path',
            '_tikool_fc_cart_preview_name',
            '_tikool_fc_crop_recipe',
            '_tikool_fc_snapshot',
            '_tikool_fc_quality',
            '_tikool_fc_style',
            '_tikool_fc_style_label',
            '_tikool_fc_variant',
            '_tikool_fc_variant_label',
            '_tikool_fc_renderer',
            '_tikool_fc_template',
            '_tikool_fc_engraving_svg_path',
            '_tikool_fc_engraving_svg_name',
            '_tikool_fc_engraving_png_path',
            '_tikool_fc_engraving_png_name',
            '_tikool_pet_photo_path',
            '_tikool_pet_photo_original_name',
        ])));
    }

    public function download_order_artwork(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('你没有权限下载此文件。', 'tikool-frame-customizer'), 403);
        }
        $order_id = absint($_GET['order_id'] ?? 0);
        $item_id = absint($_GET['item_id'] ?? 0);
        $type = sanitize_key((string) ($_GET['type'] ?? 'original'));
        if (! in_array($type, ['original', 'preview', 'cart-preview', 'engraving-svg', 'engraving-png'], true)
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'tikool_fc_download_' . $order_id . '_' . $item_id . '_' . $type)) {
            wp_die(esc_html__('下载链接无效或已过期。', 'tikool-frame-customizer'), 403);
        }
        $order = wc_get_order($order_id);
        $item = $order instanceof \WC_Order ? $order->get_item($item_id) : null;
        $meta_type = str_replace('-', '_', $type);
        $path = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_fc_' . $meta_type . '_path', true) : '';
        $name = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_fc_' . $meta_type . '_name', true) : '';
        $inline = $type !== 'original' && isset($_GET['inline']) && $_GET['inline'] === '1';
        $this->stream_private_file($path, $name ?: $type . '-photo', $inline);
    }

    public function download_legacy_order_artwork(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('你没有权限下载此文件。', 'tikool-frame-customizer'), 403);
        }
        $order_id = absint($_GET['order_id'] ?? 0);
        $item_id = absint($_GET['item_id'] ?? 0);
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'tikool_download_customer_photo_' . $order_id . '_' . $item_id)) {
            wp_die(esc_html__('下载链接无效或已过期。', 'tikool-frame-customizer'), 403);
        }
        $order = wc_get_order($order_id);
        $item = $order instanceof \WC_Order ? $order->get_item($item_id) : null;
        $path = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_pet_photo_path', true) : '';
        $name = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta('_tikool_pet_photo_original_name', true) : '';
        $this->stream_private_file($path, $name ?: 'pet-photo');
    }

    /**
     * Create production-ready engraving artwork from trusted server-side shapes and text.
     * The SVG remains editable for the laser operator; the PNG is a high-resolution fallback.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, string>
     */
    private function create_engraving_assets(string $name, array $snapshot): array
    {
        $directory = WP_CONTENT_DIR . '/uploads/' . self::PRIVATE_DIRECTORY;
        if (! wp_mkdir_p($directory)) {
            return [];
        }

        $geometry = (array) ($snapshot['geometry'] ?? []);
        $width_mm = max(1.0, (float) ($geometry['engraving_width_mm'] ?? 36));
        $height_mm = max(1.0, (float) ($geometry['engraving_height_mm'] ?? 36));
        $safe_name = sanitize_file_name($name) ?: 'pet';
        $token = wp_generate_password(32, false, false);
        $svg_path = trailingslashit($directory) . $token . '-engraving.svg';
        $png_path = trailingslashit($directory) . $token . '-engraving.png';
        $svg_download_name = $safe_name . '-paw-name-engraving.svg';
        $png_download_name = $safe_name . '-paw-name-engraving.png';
        $escaped_name = htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $name_length = max(1, function_exists('mb_strlen') ? mb_strlen($name) : strlen($name));
        $font_size = max(45, min(82, (int) floor(860 / max(7, $name_length))));
        $svg = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" width="%1$smm" height="%2$smm" viewBox="0 0 600 600" role="img" aria-label="Paw and pet name engraving">\n<g fill="#000">\n<ellipse cx="226" cy="171" rx="34" ry="47" transform="rotate(-22 226 171)"/>\n<ellipse cx="281" cy="139" rx="32" ry="48" transform="rotate(-8 281 139)"/>\n<ellipse cx="342" cy="139" rx="32" ry="48" transform="rotate(8 342 139)"/>\n<ellipse cx="397" cy="171" rx="34" ry="47" transform="rotate(22 397 171)"/>\n<path d="M213 281C217 217 261 195 300 230C339 195 383 217 387 281C392 346 348 378 300 351C252 378 208 346 213 281Z"/>\n<text x="300" y="480" text-anchor="middle" font-family="Montserrat,Arial,sans-serif" font-size="%3$d" font-weight="600" letter-spacing="1">%4$s</text>\n</g>\n</svg>\n',
            rtrim(rtrim(number_format($width_mm, 1, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($height_mm, 1, '.', ''), '0'), '.'),
            $font_size,
            $escaped_name
        );
        if (file_put_contents($svg_path, $svg, LOCK_EX) === false) {
            return [];
        }

        $assets = [
            'engraving_svg_path' => $svg_path,
            'engraving_svg_name' => $svg_download_name,
        ];
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return $assets;
        }

        $canvas_size = 1800;
        $image = imagecreatetruecolor($canvas_size, $canvas_size);
        if (! $image) {
            return $assets;
        }
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }
        imagefilledellipse($image, 675, 510, 190, 250, $black);
        imagefilledellipse($image, 835, 410, 180, 270, $black);
        imagefilledellipse($image, 1015, 410, 180, 270, $black);
        imagefilledellipse($image, 1175, 510, 190, 250, $black);
        imagefilledellipse($image, 925, 790, 570, 500, $black);

        $font = TIKOOL_FC_DIR . 'assets/fonts/Montserrat-variable.ttf';
        $rendered_text = false;
        if (function_exists('imagettftext') && is_file($font)) {
            $png_font_size = 210;
            do {
                $bounds = imagettfbbox($png_font_size, 0, $font, $name);
                $text_width = is_array($bounds) ? abs((int) $bounds[4] - (int) $bounds[0]) : 0;
                if ($text_width <= 1440 || $png_font_size <= 92) {
                    break;
                }
                $png_font_size -= 8;
            } while (true);
            if (is_array($bounds)) {
                $text_x = (int) (($canvas_size - $text_width) / 2);
                $rendered_text = imagettftext($image, $png_font_size, 0, $text_x, 1450, $black, $font, $name) !== false;
            }
        }
        if (! $rendered_text) {
            $fallback = strtoupper(substr($safe_name, 0, 24));
            $text_width = imagefontwidth(5) * strlen($fallback);
            imagestring($image, 5, max(20, (int) (($canvas_size - $text_width) / 2)), 1400, $fallback, $black);
        }
        if (imagepng($image, $png_path, 9)) {
            $assets['engraving_png_path'] = $png_path;
            $assets['engraving_png_name'] = $png_download_name;
        }
        imagedestroy($image);
        return $assets;
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

        $allowed = $kind === 'original'
            ? ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp']
            : ['jpg|jpeg|jpe' => 'image/jpeg'];
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
     * @return array<string, mixed>|null
     */
    private function sanitize_recipe(string $json, string $name): ?array
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

        $recipe['variant'] = sanitize_key((string) ($decoded['variant'] ?? ''));
        if ($recipe['x'] < 0 || $recipe['y'] < 0 || $recipe['width'] <= 0 || $recipe['height'] <= 0
            || $recipe['x'] + $recipe['width'] > 1.001 || $recipe['y'] + $recipe['height'] > 1.001
            || $recipe['zoom'] < 1 || $recipe['zoom'] > 3.01) {
            return null;
        }

        $overlay = $decoded['nameOverlay'] ?? null;
        if ($overlay !== null && ! is_array($overlay)) {
            return null;
        }
        if (is_array($overlay) && ! empty($overlay['enabled'])) {
            $font = sanitize_key((string) ($overlay['font'] ?? ''));
            $size = $overlay['sizePercent'] ?? null;
            $text = sanitize_text_field((string) ($overlay['text'] ?? ''));
            $position_x = $overlay['positionX'] ?? 0.5;
            $position_y = $overlay['positionY'] ?? 0.94;
            $color = sanitize_hex_color((string) ($overlay['color'] ?? '#2d2522'));
            $outline_color = sanitize_hex_color((string) ($overlay['outlineColor'] ?? '#ffffff'));
            if (! is_string($color) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)
                || ! is_string($outline_color) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $outline_color)
                || (array_key_exists('outlineEnabled', $overlay) && ! is_bool($overlay['outlineEnabled']))) {
                return null;
            }
            $outline_enabled = (bool) ($overlay['outlineEnabled'] ?? true);
            if ($name === '' || $text !== $name || ! array_key_exists($font, self::name_fonts())
                || ! is_numeric($size) || (float) $size < self::NAME_MIN_SIZE_PERCENT
                || (float) $size > self::NAME_MAX_SIZE_PERCENT
                || ! is_numeric($position_x) || ! is_numeric($position_y)
                || (float) $position_x < 0 || (float) $position_x > 1
                || (float) $position_y < 0 || (float) $position_y > 1) {
                return null;
            }
            $recipe['nameOverlay'] = [
                'enabled' => true,
                'text' => $name,
                'font' => $font,
                'sizePercent' => (float) $size,
                'positionX' => (float) $position_x,
                'positionY' => (float) $position_y,
                'color' => $color,
                'outlineEnabled' => $outline_enabled,
                'outlineColor' => $outline_color,
            ];
        } else {
            $recipe['nameOverlay'] = ['enabled' => false];
        }
        return $recipe;
    }

    /**
     * @return array<string, string>
     */
    private static function name_fonts(): array
    {
        return [
            'georgia' => __('Classic serif (Georgia)', 'tikool-frame-customizer'),
            'arial' => __('Clean sans (Arial)', 'tikool-frame-customizer'),
            'trebuchet' => __('Soft sans (Trebuchet)', 'tikool-frame-customizer'),
            'brush' => __('Handwritten (Brush Script)', 'tikool-frame-customizer'),
            'oswald' => __('Bold condensed capitals (Oswald)', 'tikool-frame-customizer'),
            'playfair' => __('Elegant serif (Playfair Display)', 'tikool-frame-customizer'),
            'cormorant' => __('Editorial serif (Cormorant Garamond)', 'tikool-frame-customizer'),
            'montserrat' => __('Modern geometric (Montserrat)', 'tikool-frame-customizer'),
            'great-vibes' => __('Elegant script (Great Vibes)', 'tikool-frame-customizer'),
        ];
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

    private function download_url(int $order_id, int $item_id, string $type, bool $inline = false): string
    {
        $args = ['action' => 'tikool_frame_customizer_download', 'order_id' => $order_id, 'item_id' => $item_id, 'type' => $type];
        if ($inline) {
            $args['inline'] = '1';
        }
        return wp_nonce_url(
            add_query_arg($args, admin_url('admin-post.php')),
            'tikool_fc_download_' . $order_id . '_' . $item_id . '_' . $type
        );
    }

    /**
     * @param array<string, mixed> $cart_item
     */
    private function cart_item_has_preview(array $cart_item): bool
    {
        return $this->cart_item_preview_path($cart_item) !== '';
    }

    /**
     * @param array<string, mixed> $cart_item
     */
    private function cart_item_preview_path(array $cart_item): string
    {
        $customization = $cart_item['_tikool_frame_customization'] ?? null;
        if (! is_array($customization)) {
            return '';
        }
        foreach (['cart_preview_path', 'preview_path'] as $key) {
            $path = (string) ($customization[$key] ?? '');
            if ($path !== '' && str_contains(basename($path), '-preview.')
                && $this->is_private_path($path) && is_file($path)) {
                return $path;
            }
        }
        return '';
    }

    private function cart_preview_url(string $cart_item_key): string
    {
        return add_query_arg(
            ['action' => 'tikool_frame_customizer_cart_preview', 'cart_item_key' => $cart_item_key],
            admin_url('admin-post.php')
        );
    }

    private function stream_private_file(string $path, string $name, bool $inline = false): void
    {
        if ($path === '' || ! $this->is_private_path($path) || ! is_file($path)) {
            wp_die(esc_html__('顾客图片不可用。', 'tikool-frame-customizer'), 404);
        }
        $filename = sanitize_file_name($name) ?: 'customer-artwork';
        $mime = (string) wp_check_filetype($filename)['type'];
        if (str_ends_with(strtolower($filename), '.svg')) {
            $mime = 'image/svg+xml';
        }
        if ($inline) {
            $actual_mime = (string) wp_get_image_mime($path);
            if (! in_array($actual_mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                wp_die(esc_html__('预览图片格式不受支持。', 'tikool-frame-customizer'), 415);
            }
            $mime = $actual_mime;
        }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($filename) . '"');
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
        $recipe = json_decode((string) $item->get_meta('_tikool_fc_crop_recipe', true), true);
        if (! is_array($snapshot)) {
            return;
        }
        $geometry = (array) ($snapshot['geometry'] ?? []);
        $renderer = sanitize_key((string) ($snapshot['renderer'] ?? 'frame')) ?: 'frame';
        echo '<details class="tikool-fc-production-summary"><summary>' . esc_html__('生产参数', 'tikool-frame-customizer') . '</summary><table class="widefat striped" style="margin-top:8px;max-width:620px"><tbody>';
        if ($renderer === 'keepsake') {
            $roles = (array) ($snapshot['roles'] ?? []);
            $rows = [
                __('定制模板', 'tikool-frame-customizer') => (string) ($snapshot['templateLabel'] ?? $snapshot['template'] ?? ''),
                __('木材', 'tikool-frame-customizer') => (string) ($roles['wood'] ?? '—'),
                __('盒子尺寸', 'tikool-frame-customizer') => sprintf('%s × %s × %s mm', $geometry['outer_width_mm'] ?? '—', $geometry['outer_height_mm'] ?? '—', $geometry['box_height_mm'] ?? '—'),
                __('盒内照片尺寸', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['print_width_mm'] ?? '—', $geometry['print_height_mm'] ?? '—'),
                __('玻璃瓶数量', 'tikool-frame-customizer') => (string) ($geometry['vial_count'] ?? '—'),
                __('雕刻安全区', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['engraving_width_mm'] ?? '—', $geometry['engraving_height_mm'] ?? '—'),
                __('盒盖雕刻', 'tikool-frame-customizer') => (string) $item->get_meta(__('Lid engraving', 'tikool-frame-customizer'), true),
            ];
        } elseif ($renderer === 'blanket') {
            $rows = [
                __('定制模板', 'tikool-frame-customizer') => (string) ($snapshot['templateLabel'] ?? $snapshot['template'] ?? ''),
                __('毛毯成品尺寸', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['print_width_mm'] ?? '—', $geometry['print_height_mm'] ?? '—'),
                __('毛毯成品尺寸（英寸）', 'tikool-frame-customizer') => sprintf(
                    '%s × %s in',
                    isset($geometry['print_width_mm']) ? round((float) $geometry['print_width_mm'] / 25.4, 1) : '—',
                    isset($geometry['print_height_mm']) ? round((float) $geometry['print_height_mm'] / 25.4, 1) : '—'
                ),
                __('印刷画面尺寸', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['artwork_width_mm'] ?? '—', $geometry['artwork_height_mm'] ?? '—'),
            ];
        } else {
            $rows = [
                __('定制模板', 'tikool-frame-customizer') => (string) ($snapshot['templateLabel'] ?? $snapshot['template'] ?? ''),
                __('外框尺寸', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['outer_width_mm'] ?? '—', $geometry['outer_height_mm'] ?? '—'),
                __('框内可视尺寸', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['inner_width_mm'] ?? '—', $geometry['inner_height_mm'] ?? '—'),
                __('画面可视尺寸', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['artwork_width_mm'] ?? '—', $geometry['artwork_height_mm'] ?? '—'),
                __('打印照片尺寸', 'tikool-frame-customizer') => sprintf('%s × %s mm', $geometry['print_width_mm'] ?? '—', $geometry['print_height_mm'] ?? '—'),
                __('卡纸边宽（上／右／下／左）', 'tikool-frame-customizer') => sprintf('%s / %s / %s / %s mm', $geometry['mat_top_mm'] ?? 0, $geometry['mat_right_mm'] ?? 0, $geometry['mat_bottom_mm'] ?? 0, $geometry['mat_left_mm'] ?? 0),
                __('卡纸厚度', 'tikool-frame-customizer') => sprintf('%s mm', $geometry['mat_thickness_mm'] ?? 0),
            ];
        }
        $style_label = (string) $item->get_meta('_tikool_fc_style_label', true);
        if ($style_label !== '' && $renderer !== 'keepsake') {
            $rows = array_slice($rows, 0, 1, true)
                + [$renderer === 'blanket' ? __('画面风格', 'tikool-frame-customizer') : __('肖像风格', 'tikool-frame-customizer') => $style_label]
                + array_slice($rows, 1, null, true);
        }
        $variant_label = (string) $item->get_meta('_tikool_fc_variant_label', true);
        if ($variant_label !== '') {
            $rows[__('肖像背景', 'tikool-frame-customizer')] = $variant_label;
        }
        $overlay = is_array($recipe) ? (array) ($recipe['nameOverlay'] ?? []) : [];
        if (! empty($overlay['enabled'])) {
            $fonts = self::name_fonts();
            $outline = ! empty($overlay['outlineEnabled'])
                ? sprintf(__('描边 %s', 'tikool-frame-customizer'), strtoupper((string) ($overlay['outlineColor'] ?? '#ffffff')))
                : __('无描边', 'tikool-frame-customizer');
            $rows[__('画芯印字', 'tikool-frame-customizer')] = sprintf(
                '%s · %s · %s%% · %s · %s',
                (string) ($overlay['text'] ?? ''),
                $fonts[$overlay['font'] ?? ''] ?? '—',
                (string) ($overlay['sizePercent'] ?? '—'),
                sprintf(__('字色 %s', 'tikool-frame-customizer'), strtoupper((string) ($overlay['color'] ?? '#2d2522'))),
                $outline
            );
        }
        if (is_array($quality)) {
            $rows[__('原图像素', 'tikool-frame-customizer')] = sprintf('%s × %s px', $quality['source_width'] ?? '—', $quality['source_height'] ?? '—');
            $rows[__('预估 DPI', 'tikool-frame-customizer')] = sprintf('%s（%s）', $quality['effective_dpi'] ?? '—', ! empty($quality['meets_minimum']) ? __('合格', 'tikool-frame-customizer') : __('需要检查', 'tikool-frame-customizer'));
        }
        foreach ($rows as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }
}
