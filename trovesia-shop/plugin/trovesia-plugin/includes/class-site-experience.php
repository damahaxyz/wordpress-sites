<?php
/**
 * Persistent storefront features for Trovesia.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

namespace Trovesia\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Site_Experience
{
    private const ANNOUNCEMENT_TEXT_OPTION = 'trovesia_site_announcement_text';

    private const ANNOUNCEMENT_URL_OPTION = 'trovesia_site_announcement_url';

    private const ANNOUNCEMENT_MIGRATION_OPTION = 'trovesia_site_announcement_migration_version';

    private const ANNOUNCEMENT_MIGRATION_VERSION = '1.0.0';

    private const CHECKOUT_DELIVERY_TITLE_OPTION = 'trovesia_checkout_delivery_title';

    private const CHECKOUT_DELIVERY_DESCRIPTION_OPTION = 'trovesia_checkout_delivery_description';

    private const POLICY_PAGES_VERSION_OPTION = 'trovesia_policy_pages_version';

    private const POLICY_PAGES_VERSION = '1.0.0';

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

    public static function activate(): void
    {
        self::ensure_page(
            'track-order',
            __('Track your order', 'trovesia-plugin'),
            '[trovesia_tracking_form]'
        );
        self::ensure_page(
            'contact-us',
            __('Contact us', 'trovesia-plugin'),
            '[trovesia_contact_form]'
        );
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_shortcode('trovesia_tracking_form', [$this, 'tracking_form']);
        add_shortcode('trovesia_contact_form', [$this, 'contact_form']);
        add_action('init', [$this, 'register_inquiry_type']);
        add_action('init', [$this, 'install_policy_pages'], 20);
        add_action('init', [$this, 'install_default_announcement'], 21);
        add_action('template_redirect', [$this, 'redirect_legacy_shopify_paths']);

        add_action('admin_post_nopriv_trovesia_track_order', [$this, 'handle_tracking']);
        add_action('admin_post_trovesia_track_order', [$this, 'handle_tracking']);
        add_action('admin_post_nopriv_trovesia_contact', [$this, 'handle_contact']);
        add_action('admin_post_trovesia_contact', [$this, 'handle_contact']);

        add_action('admin_menu', [$this, 'add_checkout_settings_page']);
        add_action('admin_init', [$this, 'register_checkout_settings']);

        add_action('add_meta_boxes_product', [$this, 'add_product_meta_box']);
        add_action('save_post_product', [$this, 'save_product_meta']);
        add_action('woocommerce_single_product_summary', [$this, 'render_product_eyebrow'], 4);
        add_action('woocommerce_single_product_summary', [$this, 'render_product_benefits'], 22);
        add_action('woocommerce_single_product_summary', [$this, 'render_product_assurance'], 35);
        add_action('woocommerce_before_variations_form', [$this, 'render_variant_picker'], 5);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_generic_addons'], 5);
        add_action('woocommerce_review_after_comment_text', [$this, 'render_review_media'], 20);
        add_action('woocommerce_checkout_before_order_review', [$this, 'render_checkout_assurance'], 5);
        add_filter('render_block_woocommerce/checkout', [$this, 'render_checkout_assurance_block'], 10, 2);
        add_filter('woocommerce_sale_flash', [$this, 'sale_badge'], 10, 3);
        add_filter('woocommerce_review_is_from_verified_owner', [$this, 'review_is_verified'], 10, 2);
        add_filter('get_comment_date', [$this, 'review_date_label'], 10, 3);

        add_filter('manage_edit-comments_columns', [$this, 'add_admin_review_media_column']);
        add_action('manage_comments_custom_column', [$this, 'render_admin_comment_media_column'], 10, 2);
        add_filter('woocommerce_product_reviews_table_columns', [$this, 'add_admin_review_media_column']);
        add_action('woocommerce_product_reviews_table_column_trovesia_media', [$this, 'render_admin_product_review_media_column']);
        add_action('admin_head', [$this, 'render_admin_review_media_styles']);

        add_action('admin_notices', [$this, 'woocommerce_notice']);
    }

    public function install_policy_pages(): void
    {
        if ((string) get_option(self::POLICY_PAGES_VERSION_OPTION, '') === self::POLICY_PAGES_VERSION) {
            return;
        }

        $policies = require TROVESIA_PLUGIN_DIR . 'data/policies.php';
        if (! is_array($policies)) {
            return;
        }

        $privacy_page_id = 0;
        $installed = 0;
        $replacements = [
            '{{site_url}}'     => home_url('/'),
            '{{support_email}}' => 'service@trovesia.com',
        ];

        foreach ($policies as $slug => $policy) {
            if (! is_array($policy) || empty($policy['title']) || empty($policy['content'])) {
                continue;
            }

            $existing = get_page_by_path((string) $slug, OBJECT, 'page');
            $post_data = [
                'post_content' => wp_slash(strtr((string) $policy['content'], $replacements)),
                'post_name'    => sanitize_title((string) $slug),
                'post_status'  => 'publish',
                'post_title'   => sanitize_text_field((string) $policy['title']),
                'post_type'    => 'page',
            ];

            if ($existing instanceof \WP_Post) {
                $post_data['ID'] = $existing->ID;
                $page_id = wp_update_post($post_data, true);
            } else {
                $page_id = wp_insert_post($post_data, true);
            }

            if (is_wp_error($page_id) || (int) $page_id < 1) {
                continue;
            }

            ++$installed;
            if ($slug === 'privacy-policy') {
                $privacy_page_id = (int) $page_id;
            }
        }

        if ($privacy_page_id > 0) {
            update_option('wp_page_for_privacy_policy', $privacy_page_id);
        }

        if ($installed === count($policies)) {
            update_option(self::POLICY_PAGES_VERSION_OPTION, self::POLICY_PAGES_VERSION, false);
        }
    }

    public function add_checkout_settings_page(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Trovesia checkout settings', 'trovesia-plugin'),
            __('Trovesia settings', 'trovesia-plugin'),
            'manage_woocommerce',
            'trovesia-settings',
            [$this, 'render_checkout_settings_page']
        );
    }

    public function register_checkout_settings(): void
    {
        register_setting('trovesia_checkout_settings', self::ANNOUNCEMENT_TEXT_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
        register_setting('trovesia_checkout_settings', self::ANNOUNCEMENT_URL_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);
        register_setting('trovesia_checkout_settings', self::CHECKOUT_DELIVERY_TITLE_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => self::default_checkout_delivery_title(),
        ]);
        register_setting('trovesia_checkout_settings', self::CHECKOUT_DELIVERY_DESCRIPTION_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => self::default_checkout_delivery_description(),
        ]);

        add_settings_section(
            'trovesia_site_announcement',
            __('Site announcement', 'trovesia-plugin'),
            [$this, 'render_announcement_settings_intro'],
            'trovesia-checkout-settings'
        );
        add_settings_field(
            self::ANNOUNCEMENT_TEXT_OPTION,
            __('Announcement text', 'trovesia-plugin'),
            [$this, 'render_announcement_text_field'],
            'trovesia-checkout-settings',
            'trovesia_site_announcement'
        );
        add_settings_field(
            self::ANNOUNCEMENT_URL_OPTION,
            __('Announcement link', 'trovesia-plugin'),
            [$this, 'render_announcement_url_field'],
            'trovesia-checkout-settings',
            'trovesia_site_announcement'
        );

        add_settings_section(
            'trovesia_checkout_delivery',
            __('Checkout delivery message', 'trovesia-plugin'),
            [$this, 'render_checkout_delivery_settings_intro'],
            'trovesia-checkout-settings'
        );
        add_settings_field(
            self::CHECKOUT_DELIVERY_TITLE_OPTION,
            __('Delivery title', 'trovesia-plugin'),
            [$this, 'render_checkout_delivery_title_field'],
            'trovesia-checkout-settings',
            'trovesia_checkout_delivery'
        );
        add_settings_field(
            self::CHECKOUT_DELIVERY_DESCRIPTION_OPTION,
            __('Delivery description', 'trovesia-plugin'),
            [$this, 'render_checkout_delivery_description_field'],
            'trovesia-checkout-settings',
            'trovesia_checkout_delivery'
        );
    }

    public function render_checkout_settings_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Trovesia settings', 'trovesia-plugin'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('trovesia_checkout_settings');
                do_settings_sections('trovesia-checkout-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_announcement_settings_intro(): void
    {
        echo '<p>' . esc_html__('Shown in the slim bar above the site header. Leave the announcement text empty to hide the entire bar.', 'trovesia-plugin') . '</p>';
    }

    public function render_announcement_text_field(): void
    {
        printf(
            '<input class="large-text" name="%1$s" type="text" value="%2$s" placeholder="%3$s">',
            esc_attr(self::ANNOUNCEMENT_TEXT_OPTION),
            esc_attr(self::announcement_text()),
            esc_attr__('Complimentary shipping · 30-day returns · Secure checkout', 'trovesia-plugin')
        );
    }

    public function render_announcement_url_field(): void
    {
        printf(
            '<input class="large-text code" name="%1$s" type="url" value="%2$s" placeholder="%3$s"><p class="description">%4$s</p>',
            esc_attr(self::ANNOUNCEMENT_URL_OPTION),
            esc_attr(self::announcement_url()),
            esc_attr(home_url('/shop/')),
            esc_html__('Optional. When provided, clicking the announcement opens this URL.', 'trovesia-plugin')
        );
    }

    /**
     * Preserve the information that was previously hard-coded in the theme.
     * Clearing the saved field later keeps the announcement hidden.
     */
    public function install_default_announcement(): void
    {
        if ((string) get_option(self::ANNOUNCEMENT_MIGRATION_OPTION, '') === self::ANNOUNCEMENT_MIGRATION_VERSION) {
            return;
        }

        if (false === get_option(self::ANNOUNCEMENT_TEXT_OPTION, false)) {
            update_option(
                self::ANNOUNCEMENT_TEXT_OPTION,
                __('Complimentary shipping · 30-day returns · Secure checkout', 'trovesia-plugin')
            );
        }

        update_option(self::ANNOUNCEMENT_MIGRATION_OPTION, self::ANNOUNCEMENT_MIGRATION_VERSION, false);
    }

    public static function announcement_text(): string
    {
        return trim((string) get_option(self::ANNOUNCEMENT_TEXT_OPTION, ''));
    }

    public static function announcement_url(): string
    {
        return trim((string) get_option(self::ANNOUNCEMENT_URL_OPTION, ''));
    }

    public function render_checkout_delivery_settings_intro(): void
    {
        echo '<p>' . esc_html__('These two lines appear in the assurance panel above the checkout form.', 'trovesia-plugin') . '</p>';
    }

    public function render_checkout_delivery_title_field(): void
    {
        printf(
            '<input class="regular-text" name="%1$s" type="text" value="%2$s">',
            esc_attr(self::CHECKOUT_DELIVERY_TITLE_OPTION),
            esc_attr(self::checkout_delivery_title())
        );
    }

    public function render_checkout_delivery_description_field(): void
    {
        printf(
            '<textarea class="large-text" name="%1$s" rows="4">%2$s</textarea>',
            esc_attr(self::CHECKOUT_DELIVERY_DESCRIPTION_OPTION),
            esc_textarea(self::checkout_delivery_description())
        );
    }

    private static function default_checkout_delivery_title(): string
    {
        return __('Tracked delivery in 7–12 days', 'trovesia-plugin');
    }

    private static function default_checkout_delivery_description(): string
    {
        return __('Orders are processed within 24–72 hours. Tracking is emailed when your parcel ships.', 'trovesia-plugin');
    }

    private static function checkout_delivery_title(): string
    {
        $value = trim((string) get_option(self::CHECKOUT_DELIVERY_TITLE_OPTION, ''));

        return $value !== '' ? $value : self::default_checkout_delivery_title();
    }

    private static function checkout_delivery_description(): string
    {
        $value = trim((string) get_option(self::CHECKOUT_DELIVERY_DESCRIPTION_OPTION, ''));

        return $value !== '' ? $value : self::default_checkout_delivery_description();
    }

    /**
     * Keep the small set of useful Shopify paths working after migration.
     */
    public function redirect_legacy_shopify_paths(): void
    {
        if (is_admin()) {
            return;
        }

        $path = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $redirects = [
            'pages/contact-us'          => home_url('/contact-us/'),
            'pages/track-order'         => home_url('/track-order/'),
            'collections/frontpage'     => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/#shop'),
            'collections/all-products'  => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/#shop'),
        ];

        if (isset($redirects[$path])) {
            wp_safe_redirect($redirects[$path], 301);
            exit;
        }
    }

    public function tracking_form(): string
    {
        $status = sanitize_key((string) ($_GET['tracking_status'] ?? ''));

        ob_start();
        ?>
        <section class="trovesia-form-card trovesia-form-card--tracking">
            <p class="trovesia-form-card__eyebrow"><?php esc_html_e('Delivery updates', 'trovesia-plugin'); ?></p>
            <h2><?php esc_html_e('Track your order', 'trovesia-plugin'); ?></h2>
            <p><?php esc_html_e('Enter the tracking number from your shipping confirmation email.', 'trovesia-plugin'); ?></p>
            <?php if ($status === 'invalid') : ?>
                <p class="trovesia-form-notice trovesia-form-notice--error"><?php esc_html_e('Please check the tracking number and try again.', 'trovesia-plugin'); ?></p>
            <?php endif; ?>
            <form class="trovesia-inline-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="trovesia_track_order">
                <?php wp_nonce_field('trovesia_track_order', 'trovesia_tracking_nonce'); ?>
                <label class="screen-reader-text" for="trovesia-tracking-number"><?php esc_html_e('Tracking number', 'trovesia-plugin'); ?></label>
                <input id="trovesia-tracking-number" name="tracking_number" type="text" minlength="5" maxlength="50" placeholder="<?php esc_attr_e('Tracking number', 'trovesia-plugin'); ?>" autocomplete="off" required>
                <button type="submit"><?php esc_html_e('Track parcel', 'trovesia-plugin'); ?></button>
            </form>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public function handle_tracking(): void
    {
        check_admin_referer('trovesia_track_order', 'trovesia_tracking_nonce');

        $number = strtoupper(sanitize_text_field((string) ($_POST['tracking_number'] ?? '')));
        $number = preg_replace('/[^A-Z0-9-]/', '', $number) ?: '';

        if (strlen($number) < 5 || strlen($number) > 50) {
            wp_safe_redirect(add_query_arg('tracking_status', 'invalid', home_url('/track-order/')));
            exit;
        }

        $template = (string) apply_filters(
            'trovesia_tracking_url_template',
            'https://t.17track.net/en#nums=%s'
        );
        $tracking_url = sprintf($template, rawurlencode($number));

        wp_redirect(esc_url_raw($tracking_url), 302, 'Trovesia'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    public function contact_form(): string
    {
        $status = sanitize_key((string) ($_GET['contact_status'] ?? ''));

        ob_start();
        ?>
        <section class="trovesia-form-card trovesia-form-card--contact">
            <p class="trovesia-form-card__eyebrow"><?php esc_html_e('Customer care', 'trovesia-plugin'); ?></p>
            <h2><?php esc_html_e('How can we help?', 'trovesia-plugin'); ?></h2>
            <p><?php esc_html_e('Questions about a product or an existing order? Send us a note and our team will get back to you.', 'trovesia-plugin'); ?></p>
            <?php if ($status === 'sent') : ?>
                <p class="trovesia-form-notice trovesia-form-notice--success"><?php esc_html_e('Thank you. Your message has been sent.', 'trovesia-plugin'); ?></p>
            <?php elseif ($status === 'error') : ?>
                <p class="trovesia-form-notice trovesia-form-notice--error"><?php esc_html_e('We could not send your message. Please check the fields and try again.', 'trovesia-plugin'); ?></p>
            <?php endif; ?>
            <form class="trovesia-contact-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="trovesia_contact">
                <?php wp_nonce_field('trovesia_contact', 'trovesia_contact_nonce'); ?>
                <div class="trovesia-contact-form__grid">
                    <p>
                        <label for="trovesia-contact-name"><?php esc_html_e('Name', 'trovesia-plugin'); ?></label>
                        <input id="trovesia-contact-name" name="name" type="text" maxlength="100" autocomplete="name" required>
                    </p>
                    <p>
                        <label for="trovesia-contact-email"><?php esc_html_e('Email', 'trovesia-plugin'); ?></label>
                        <input id="trovesia-contact-email" name="email" type="email" maxlength="190" autocomplete="email" required>
                    </p>
                </div>
                <p>
                    <label for="trovesia-contact-phone"><?php esc_html_e('Phone number (optional)', 'trovesia-plugin'); ?></label>
                    <input id="trovesia-contact-phone" name="phone" type="tel" maxlength="40" autocomplete="tel">
                </p>
                <p>
                    <label for="trovesia-contact-message"><?php esc_html_e('Message', 'trovesia-plugin'); ?></label>
                    <textarea id="trovesia-contact-message" name="message" rows="6" maxlength="3000" required></textarea>
                </p>
                <p class="trovesia-contact-form__website" aria-hidden="true">
                    <label for="trovesia-contact-website">Website</label>
                    <input id="trovesia-contact-website" name="website" type="text" tabindex="-1" autocomplete="off">
                </p>
                <button type="submit"><?php esc_html_e('Send message', 'trovesia-plugin'); ?></button>
            </form>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public function handle_contact(): void
    {
        check_admin_referer('trovesia_contact', 'trovesia_contact_nonce');

        $redirect = wp_get_referer() ?: home_url('/contact-us/');
        $redirect = remove_query_arg('contact_status', $redirect);

        if ((string) ($_POST['website'] ?? '') !== '') {
            wp_safe_redirect(add_query_arg('contact_status', 'sent', $redirect));
            exit;
        }

        $rate_key = 'trovesia_contact_' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (get_transient($rate_key)) {
            wp_safe_redirect(add_query_arg('contact_status', 'error', $redirect));
            exit;
        }

        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $email = sanitize_email((string) ($_POST['email'] ?? ''));
        $phone = sanitize_text_field((string) ($_POST['phone'] ?? ''));
        $message = sanitize_textarea_field((string) ($_POST['message'] ?? ''));

        if ($name === '' || ! is_email($email) || $message === '') {
            wp_safe_redirect(add_query_arg('contact_status', 'error', $redirect));
            exit;
        }

        $inquiry_id = wp_insert_post([
            'post_content' => $message,
            'post_status'  => 'private',
            'post_title'   => sprintf('%s — %s', $name, wp_date('Y-m-d H:i')),
            'post_type'    => 'trovesia_inquiry',
        ], true);

        if (is_wp_error($inquiry_id)) {
            wp_safe_redirect(add_query_arg('contact_status', 'error', $redirect));
            exit;
        }

        update_post_meta((int) $inquiry_id, '_trovesia_contact_name', $name);
        update_post_meta((int) $inquiry_id, '_trovesia_contact_email', $email);
        update_post_meta((int) $inquiry_id, '_trovesia_contact_phone', $phone);

        $subject = sprintf(__('Trovesia enquiry from %s', 'trovesia-plugin'), $name);
        $body = sprintf(
            "Name: %s\nEmail: %s\nPhone: %s\n\n%s",
            $name,
            $email,
            $phone !== '' ? $phone : '—',
            $message
        );
        $headers = ['Reply-To: ' . $name . ' <' . $email . '>'];
        wp_mail((string) get_option('admin_email'), $subject, $body, $headers);
        set_transient($rate_key, 1, MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg('contact_status', 'sent', $redirect));
        exit;
    }

    public function register_inquiry_type(): void
    {
        $capabilities = [
            'create_posts'       => 'do_not_allow',
            'delete_others_posts' => 'manage_woocommerce',
            'delete_post'        => 'manage_woocommerce',
            'delete_posts'       => 'manage_woocommerce',
            'edit_others_posts'  => 'manage_woocommerce',
            'edit_post'          => 'manage_woocommerce',
            'edit_posts'         => 'manage_woocommerce',
            'publish_posts'      => 'manage_woocommerce',
            'read_post'          => 'manage_woocommerce',
            'read_private_posts' => 'manage_woocommerce',
        ];

        register_post_type('trovesia_inquiry', [
            'capabilities'       => $capabilities,
            'has_archive'        => false,
            'labels'             => [
                'name'          => __('Customer inquiries', 'trovesia-plugin'),
                'singular_name' => __('Customer inquiry', 'trovesia-plugin'),
                'menu_name'     => __('Inquiries', 'trovesia-plugin'),
            ],
            'map_meta_cap'       => false,
            'menu_icon'          => 'dashicons-email-alt',
            'public'             => false,
            'show_in_menu'       => true,
            'show_ui'            => true,
            'supports'           => ['title', 'editor'],
        ]);
    }

    public function add_product_meta_box(): void
    {
        add_meta_box(
            'trovesia-product-story',
            __('Trovesia product presentation', 'trovesia-plugin'),
            [$this, 'render_product_meta_box'],
            'product',
            'normal',
            'default'
        );
    }

    public function render_product_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('trovesia_product_meta', 'trovesia_product_meta_nonce');

        $eyebrow = (string) get_post_meta($post->ID, '_trovesia_eyebrow', true);
        $benefits = (string) get_post_meta($post->ID, '_trovesia_benefits', true);
        $guarantee = (string) get_post_meta($post->ID, '_trovesia_guarantee', true);
        $shipping = (string) get_post_meta($post->ID, '_trovesia_shipping', true);
        $order_activity = (string) get_post_meta($post->ID, '_trovesia_order_activity', true);
        ?>
        <p>
            <label for="trovesia-eyebrow"><strong><?php esc_html_e('Eyebrow', 'trovesia-plugin'); ?></strong></label><br>
            <input class="widefat" id="trovesia-eyebrow" name="trovesia_eyebrow" type="text" value="<?php echo esc_attr($eyebrow); ?>">
        </p>
        <p>
            <label for="trovesia-benefits"><strong><?php esc_html_e('Key benefits', 'trovesia-plugin'); ?></strong></label><br>
            <textarea class="widefat" id="trovesia-benefits" name="trovesia_benefits" rows="5"><?php echo esc_textarea($benefits); ?></textarea>
            <span class="description"><?php esc_html_e('Enter one concise benefit per line. Avoid medical claims.', 'trovesia-plugin'); ?></span>
        </p>
        <p>
            <label for="trovesia-guarantee"><strong><?php esc_html_e('Returns and guarantee note', 'trovesia-plugin'); ?></strong></label><br>
            <textarea class="widefat" id="trovesia-guarantee" name="trovesia_guarantee" rows="3"><?php echo esc_textarea($guarantee); ?></textarea>
        </p>
        <p>
            <label for="trovesia-shipping"><strong><?php esc_html_e('Shipping note', 'trovesia-plugin'); ?></strong></label><br>
            <textarea class="widefat" id="trovesia-shipping" name="trovesia_shipping" rows="3"><?php echo esc_textarea($shipping); ?></textarea>
        </p>
        <p>
            <label for="trovesia-order-activity"><strong><?php esc_html_e('Order activity notice', 'trovesia-plugin'); ?></strong></label><br>
            <input class="widefat" id="trovesia-order-activity" name="trovesia_order_activity" type="text" value="<?php echo esc_attr($order_activity); ?>" placeholder="<?php esc_attr_e('Orders are processed daily', 'trovesia-plugin'); ?>">
            <span class="description"><?php esc_html_e('Optional. When filled, this text replaces the automatic past-24-hours order message on the product page. Leave blank to keep the automatic count and its default fallback.', 'trovesia-plugin'); ?></span>
        </p>
        <?php
    }

    public function save_product_meta(int $post_id): void
    {
        if (
            ! isset($_POST['trovesia_product_meta_nonce'])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['trovesia_product_meta_nonce'])), 'trovesia_product_meta')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || ! current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        $fields = [
            'trovesia_eyebrow'   => '_trovesia_eyebrow',
            'trovesia_benefits'  => '_trovesia_benefits',
            'trovesia_guarantee' => '_trovesia_guarantee',
            'trovesia_shipping'  => '_trovesia_shipping',
            'trovesia_order_activity' => '_trovesia_order_activity',
        ];

        foreach ($fields as $field => $meta_key) {
            $value = sanitize_textarea_field(wp_unslash((string) ($_POST[$field] ?? '')));

            if ($value === '') {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
        }
    }

    public function render_product_eyebrow(): void
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            return;
        }

        $eyebrow = (string) get_post_meta($product->get_id(), '_trovesia_eyebrow', true);
        if ($eyebrow !== '') {
            printf('<p class="trovesia-product-eyebrow">%s</p>', esc_html($eyebrow));
        }
    }

    public function render_product_benefits(): void
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            return;
        }

        $benefits = array_filter(array_map('trim', explode("\n", (string) get_post_meta($product->get_id(), '_trovesia_benefits', true))));
        if ($benefits === []) {
            return;
        }

        echo '<ul class="trovesia-product-benefits">';
        foreach ($benefits as $benefit) {
            printf('<li><span aria-hidden="true">✓</span>%s</li>', esc_html($benefit));
        }
        echo '</ul>';
    }

    public function render_product_assurance(): void
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            return;
        }

        $start = wp_date('M j', strtotime('+7 days'));
        $end = wp_date('M j', strtotime('+12 days'));
        $guarantee = (string) get_post_meta($product->get_id(), '_trovesia_guarantee', true);
        $shipping = (string) get_post_meta($product->get_id(), '_trovesia_shipping', true);
        $order_activity = trim((string) get_post_meta($product->get_id(), '_trovesia_order_activity', true));
        $order_count = $this->orders_last_24_hours();
        ?>
        <div class="trovesia-order-activity">
            <span aria-hidden="true">▣</span>
            <strong>
                <?php if ($order_activity !== '') : ?>
                    <?php echo esc_html($order_activity); ?>
                <?php elseif ($order_count > 0) : ?>
                    <?php printf(esc_html__('%d orders placed in the past 24 hours', 'trovesia-plugin'), esc_html($order_count)); ?>
                <?php else : ?>
                    <?php esc_html_e('Orders are processed daily', 'trovesia-plugin'); ?>
                <?php endif; ?>
            </strong>
        </div>
        <div class="trovesia-assurance-grid" aria-label="<?php esc_attr_e('Purchase assurances', 'trovesia-plugin'); ?>">
            <div><span aria-hidden="true">◇</span><strong><?php esc_html_e('Thoughtful formulas', 'trovesia-plugin'); ?></strong><small><?php esc_html_e('Ingredient-led care', 'trovesia-plugin'); ?></small></div>
            <div><span aria-hidden="true">↺</span><strong><?php esc_html_e('30-day returns', 'trovesia-plugin'); ?></strong><small><?php esc_html_e('Simple support', 'trovesia-plugin'); ?></small></div>
            <div><span aria-hidden="true">⌁</span><strong><?php esc_html_e('Secure checkout', 'trovesia-plugin'); ?></strong><small><?php esc_html_e('Protected payment', 'trovesia-plugin'); ?></small></div>
        </div>
        <p class="trovesia-delivery-estimate">
            <span aria-hidden="true">→</span>
            <?php
            printf(
                esc_html__('Estimated delivery: %1$s–%2$s', 'trovesia-plugin'),
                esc_html($start),
                esc_html($end)
            );
            ?>
        </p>
        <div class="trovesia-product-accordions">
            <details>
                <summary><?php esc_html_e('Returns & care promise', 'trovesia-plugin'); ?></summary>
                <p><?php echo esc_html($guarantee !== '' ? $guarantee : __('If you are not satisfied within 30 days, contact us for return instructions and a full refund. Return shipping is covered.', 'trovesia-plugin')); ?></p>
            </details>
            <details>
                <summary><?php esc_html_e('Shipping & delivery', 'trovesia-plugin'); ?></summary>
                <p><?php echo esc_html($shipping !== '' ? $shipping : __('Orders are processed within 24–72 hours and delivered in 7–12 days. Tracking details are emailed when the parcel ships.', 'trovesia-plugin')); ?></p>
            </details>
        </div>
        <?php
    }

    public function render_variant_picker(): void
    {
        global $product;

        if (
            ! $product instanceof \WC_Product_Variable
            || get_post_meta($product->get_id(), '_trovesia_source_handle', true) !== 'viorine-advanced-batana-hair-regrowth-therapy'
        ) {
            return;
        }

        $variations = $product->get_available_variations();
        $bundles = [];

        foreach ($variations as $variation) {
            $attributes = (array) ($variation['attributes'] ?? []);
            $bundle = (string) ($attributes['attribute_bundle'] ?? '');
            if ($bundle !== '') {
                $bundles[$bundle] = $variation;
            }
        }

        if ($bundles === []) {
            return;
        }

        ?>
        <section class="trovesia-variant-picker" data-default-bundle="<?php echo esc_attr((string) array_key_first($bundles)); ?>">
            <div class="trovesia-variant-picker__heading">
                <span></span><strong><?php esc_html_e('Choose your bundle', 'trovesia-plugin'); ?></strong><span></span>
            </div>
            <div class="trovesia-bundle-options" role="group" aria-label="<?php esc_attr_e('Bundle size', 'trovesia-plugin'); ?>">
                <?php foreach ($bundles as $bundle => $variation) : ?>
                    <?php
                    $display_price = (float) $variation['display_price'];
                    $regular_price = (float) $variation['display_regular_price'];
                    $saving = $regular_price > $display_price ? (int) round((($regular_price - $display_price) / $regular_price) * 100) : 0;
                    $variation_product = wc_get_product((int) ($variation['variation_id'] ?? 0));
                    $variation_description = $variation_product instanceof \WC_Product_Variation
                        ? trim(wp_strip_all_tags($variation_product->get_description()))
                        : '';

                    if ($variation_description === '') {
                        preg_match_all('/\d+/', $bundle, $bundle_quantities);
                        $bottle_count = array_sum(array_map('intval', $bundle_quantities[0] ?? []));
                        $variation_description = $bottle_count > 0
                            ? sprintf(_n('%d bottle bundle', '%d bottle bundle', $bottle_count, 'trovesia-plugin'), $bottle_count)
                            : __('Bundle option', 'trovesia-plugin');
                    }
                    ?>
                    <button class="trovesia-bundle-card" type="button" data-attribute-name="attribute_bundle" data-attribute-value="<?php echo esc_attr($bundle); ?>" aria-pressed="false">
                        <span class="trovesia-bundle-card__visual"><?php echo wp_get_attachment_image($product->get_image_id(), 'woocommerce_thumbnail', false, ['loading' => 'lazy']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                        <span class="trovesia-bundle-card__copy">
                            <strong><?php echo esc_html($bundle); ?></strong>
                            <small><?php echo esc_html($variation_description); ?></small>
                        </span>
                        <span class="trovesia-bundle-card__price">
                            <?php if ($saving > 0) : ?><span class="trovesia-bundle-card__save"><?php printf(esc_html__('Save %d%%', 'trovesia-plugin'), esc_html($saving)); ?></span><?php endif; ?>
                            <strong><?php echo wp_kses_post(wc_price($display_price)); ?></strong>
                            <?php if ($regular_price > $display_price) : ?><del><?php echo wp_kses_post(wc_price($regular_price)); ?></del><?php endif; ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php $this->render_addon_options($product); ?>
        </section>
        <?php
    }

    public function render_generic_addons(): void
    {
        global $product;

        if (! $product instanceof \WC_Product || Addon_Pricing::get_rules($product->get_id()) === []) {
            return;
        }

        if (get_post_meta($product->get_id(), '_trovesia_source_handle', true) === 'viorine-advanced-batana-hair-regrowth-therapy') {
            return;
        }

        echo '<section class="trovesia-generic-addons">';
        $this->render_addon_options($product);
        echo '</section>';
    }

    private function render_addon_options(\WC_Product $parent_product): void
    {
        $rules = Addon_Pricing::get_rules($parent_product->get_id());
        if ($rules === []) {
            return;
        }
        ?>
        <div class="trovesia-addon-heading"><?php esc_html_e('Complete your order', 'trovesia-plugin'); ?></div>
        <div class="trovesia-product-addons">
            <?php foreach ($rules as $rule) : ?>
                <?php
                $linked = wc_get_product((int) ($rule['product_id'] ?? 0));
                if (! $linked || ! $linked->is_purchasable()) {
                    continue;
                }
                $title = trim((string) ($rule['title'] ?? '')) ?: $linked->get_name();
                $price = Addon_Pricing::calculate_rule_price($rule);
                $regular_price = Addon_Pricing::calculate_rule_regular_price($rule);
                $saving = $regular_price > $price && $regular_price > 0
                    ? (int) round((($regular_price - $price) / $regular_price) * 100)
                    : 0;
                $allowed_values = array_values((array) ($rule['allowed_values'] ?? []));
                ?>
                <label class="trovesia-roller-addon trovesia-product-addon<?php echo ! empty($rule['default_selected']) ? ' is-selected' : ''; ?>" data-allowed-values="<?php echo esc_attr(wp_json_encode($allowed_values)); ?>">
                    <input class="trovesia-product-addon__input" name="trovesia_addons[]" type="checkbox" value="<?php echo esc_attr((string) $rule['id']); ?>" <?php checked(! empty($rule['default_selected'])); ?>>
                    <span class="trovesia-roller-addon__check" aria-hidden="true">✓</span>
                    <span class="trovesia-roller-addon__image"><?php echo $linked->get_image('woocommerce_thumbnail', ['loading' => 'lazy']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="trovesia-roller-addon__copy">
                        <strong><?php echo esc_html($title); ?></strong>
                        <small><?php esc_html_e('Add this product to your order', 'trovesia-plugin'); ?></small>
                    </span>
                    <span class="trovesia-roller-addon__price">
                        <?php if ($saving > 0) : ?><span class="trovesia-product-addon__save"><?php printf(esc_html__('Save %d%%', 'trovesia-plugin'), esc_html($saving)); ?></span><?php endif; ?>
                        <strong>+<?php echo wp_kses_post(wc_price($price)); ?></strong>
                        <?php if ($saving > 0) : ?><del><?php echo wp_kses_post(wc_price($regular_price)); ?></del><?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function render_review_media(\WP_Comment $comment): void
    {
        $attachment_id = Review_Experience::media_attachment_id((int) $comment->comment_ID);
        if ($attachment_id < 1) {
            return;
        }

        $full = wp_get_attachment_image_url($attachment_id, 'full');
        if (! $full) {
            return;
        }

        printf(
            '<a class="trovesia-review-media" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
            esc_url($full),
            wp_get_attachment_image($attachment_id, 'medium', false, ['class' => 'trovesia-review-media__image', 'loading' => 'lazy']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    public function render_checkout_assurance(): void
    {
        $delivery_title = self::checkout_delivery_title();
        $delivery_description = self::checkout_delivery_description();
        ?>
        <aside class="trovesia-checkout-assurance" aria-label="<?php esc_attr_e('Returns and delivery', 'trovesia-plugin'); ?>">
            <div><span aria-hidden="true">♡</span><p><strong><?php esc_html_e('30-day satisfaction guarantee', 'trovesia-plugin'); ?></strong><small><?php esc_html_e('Contact us within 30 days for return instructions and a full refund. Return shipping is covered.', 'trovesia-plugin'); ?></small></p></div>
            <div><span aria-hidden="true">▱</span><p><strong><?php echo esc_html($delivery_title); ?></strong><small><?php echo nl2br(esc_html($delivery_description)); ?></small></p></div>
        </aside>
        <?php
    }

    /**
     * Add the same reassurance panel to WooCommerce's block-based checkout.
     *
     * @param array<string, mixed> $block Parsed checkout block.
     */
    public function render_checkout_assurance_block(string $block_content, array $block): string
    {
        unset($block);

        if (is_admin() || $block_content === '') {
            return $block_content;
        }

        ob_start();
        $this->render_checkout_assurance();
        $assurance = (string) ob_get_clean();

        return $assurance . $block_content;
    }

    private function orders_last_24_hours(): int
    {
        $cached = get_transient('trovesia_orders_last_24_hours');
        if ($cached !== false) {
            return max(0, (int) $cached);
        }

        if (! function_exists('wc_get_orders')) {
            return 0;
        }

        $result = wc_get_orders([
            'date_created' => '>' . (time() - DAY_IN_SECONDS),
            'limit'        => 1,
            'paginate'     => true,
            'return'       => 'ids',
            'status'       => ['wc-processing', 'wc-completed', 'wc-on-hold'],
        ]);
        $count = is_object($result) && isset($result->total) ? (int) $result->total : 0;
        set_transient('trovesia_orders_last_24_hours', $count, 5 * MINUTE_IN_SECONDS);

        return $count;
    }

    public function sale_badge(string $html, \WP_Post $post, \WC_Product $product): string
    {
        $percentages = [];

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (! $variation) {
                    continue;
                }
                $regular = (float) $variation->get_regular_price();
                $sale = (float) $variation->get_sale_price();
                if ($regular > 0 && $sale > 0 && $sale < $regular) {
                    $percentages[] = (int) round((($regular - $sale) / $regular) * 100);
                }
            }
        } else {
            $regular = (float) $product->get_regular_price();
            $sale = (float) $product->get_sale_price();
            if ($regular > 0 && $sale > 0 && $sale < $regular) {
                $percentages[] = (int) round((($regular - $sale) / $regular) * 100);
            }
        }

        if ($percentages === []) {
            return $html;
        }

        return sprintf(
            '<span class="onsale">%s</span>',
            esc_html(sprintf(__('Save %d%%', 'trovesia-plugin'), max($percentages)))
        );
    }

    public function review_is_verified(bool $verified, int $comment_id): bool
    {
        if (get_comment_meta($comment_id, '_trovesia_loox_verified', true) === '1') {
            return true;
        }

        return $verified;
    }

    /**
     * @param array<string, string> $columns Admin review table columns.
     * @return array<string, string>
     */
    public function add_admin_review_media_column(array $columns): array
    {
        $media_column = ['trovesia_media' => __('Review media', 'trovesia-plugin')];

        if (isset($columns['date'])) {
            $date = ['date' => $columns['date']];
            unset($columns['date']);

            return $columns + $media_column + $date;
        }

        return $columns + $media_column;
    }

    public function render_admin_comment_media_column(string $column_name, int $comment_id): void
    {
        if ($column_name === 'trovesia_media') {
            $this->render_admin_review_media($comment_id);
        }
    }

    public function render_admin_product_review_media_column(\WP_Comment $comment): void
    {
        $this->render_admin_review_media((int) $comment->comment_ID);
    }

    public function render_admin_review_media_styles(): void
    {
        $screen = get_current_screen();
        if (! $screen || ! in_array($screen->id, ['edit-comments', 'woocommerce_page_product-reviews'], true)) {
            return;
        }
        ?>
        <style>
            .column-trovesia_media { width: 94px; }
            .column-trovesia_featured { width: 72px; text-align: center; }
            .trovesia-admin-featured-review { color: #c6965c; font-size: 20px; }
            .trovesia-admin-review-media { display: inline-block; line-height: 0; }
            .trovesia-admin-review-media img { width: 72px; height: 72px; border: 1px solid #c3c4c7; border-radius: 7px; object-fit: cover; background: #fff; }
        </style>
        <?php
    }

    private function render_admin_review_media(int $comment_id): void
    {
        $attachment_id = Review_Experience::media_attachment_id($comment_id);
        $full = $attachment_id > 0 ? wp_get_attachment_image_url($attachment_id, 'full') : false;

        if (! $full) {
            echo '<span aria-hidden="true">—</span>';
            return;
        }

        printf(
            '<a class="trovesia-admin-review-media" href="%1$s" target="_blank" rel="noopener" aria-label="%2$s">%3$s</a>',
            esc_url($full),
            esc_attr__('Open review image', 'trovesia-plugin'),
            wp_get_attachment_image($attachment_id, [72, 72], false, ['loading' => 'lazy']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    /**
     * Avoid presenting the migration timestamp as an original review date.
     *
     * Loox exposes exact dates for a subset of its server-rendered reviews.
     * Reviews without a public source date remain clearly labelled as imported.
     *
     * @param mixed $comment Comment object or ID supplied by WordPress.
     */
    public function review_date_label(string $date, string $format, $comment): string
    {
        unset($format);

        if (is_admin()) {
            return $date;
        }

        $comment_id = $comment instanceof \WP_Comment ? (int) $comment->comment_ID : (int) $comment;
        if (
            $comment_id > 0
            && get_comment_meta($comment_id, '_trovesia_loox_review_id', true) !== ''
            && get_comment_meta($comment_id, '_trovesia_source_date_known', true) !== '1'
        ) {
            return __('Imported review', 'trovesia-plugin');
        }

        return $date;
    }

    public function woocommerce_notice(): void
    {
        if (! current_user_can('activate_plugins') || class_exists('WooCommerce')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        esc_html_e('Trovesia Shop Plugin is active. Install and activate WooCommerce to enable the product catalogue and checkout.', 'trovesia-plugin');
        echo '</p></div>';
    }

    private static function ensure_page(string $slug, string $title, string $content): int
    {
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            return (int) $existing->ID;
        }

        $page_id = wp_insert_post([
            'post_content' => $content,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_type'    => 'page',
        ], true);

        return is_wp_error($page_id) ? 0 : (int) $page_id;
    }
}
