<?php
/**
 * Branded WooCommerce email presentation.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

namespace Trovesia\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps every WooCommerce HTML email aligned with the Trovesia storefront.
 *
 * Styling through WooCommerce's supported filter avoids maintaining copies of
 * every individual email template as WooCommerce evolves.
 */
final class Email_Experience
{
    private const SETTINGS_VERSION = '1.0.0';

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

    /**
     * Seed values that remain editable in WooCommerce > Settings > Emails.
     */
    public static function activate(): void
    {
        self::apply_brand_settings();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('init', [$this, 'maybe_apply_brand_settings'], 20);
        add_action('woocommerce_email_header', [$this, 'render_service_strip'], 20, 2);
        add_action('woocommerce_pos_email_header', [$this, 'render_service_strip'], 20, 2);
        add_filter('woocommerce_email_styles', [$this, 'add_brand_styles'], 99, 2);
        add_filter('woocommerce_email_footer_text', [$this, 'footer_text'], 20, 2);
    }

    public function maybe_apply_brand_settings(): void
    {
        if (self::SETTINGS_VERSION === get_option('trovesia_email_branding_version')) {
            return;
        }

        self::apply_brand_settings();
    }

    private static function apply_brand_settings(): void
    {
        update_option('woocommerce_email_base_color', '#315f4f');
        update_option('woocommerce_email_background_color', '#eef2eb');
        update_option('woocommerce_email_body_background_color', '#fffefb');
        update_option('woocommerce_email_text_color', '#17211d');
        update_option('woocommerce_email_footer_text_color', '#67716b');
        update_option('woocommerce_email_header_alignment', 'center');
        update_option('woocommerce_email_font_family', 'Helvetica');
        update_option('trovesia_email_branding_version', self::SETTINGS_VERSION, false);
    }

    /**
     * Render a concise reassurance strip below the email heading.
     *
     * @param string            $email_heading Current email heading.
     * @param \WC_Email|null    $email         Email instance where available.
     */
    public function render_service_strip(string $email_heading, ?\WC_Email $email = null): void
    {
        unset($email_heading, $email);
        ?>
        <div class="trovesia-email-service-strip">
            <span>Botanical care</span>
            <i aria-hidden="true">&#9670;</i>
            <span>Tracked delivery</span>
            <i aria-hidden="true">&#9670;</i>
            <span>30-day returns</span>
        </div>
        <?php
    }

    /**
     * Replace the generic WooCommerce footer with useful brand links.
     *
     * @param string         $footer_text Existing footer text.
     * @param \WC_Email|null $email       Email instance where available.
     */
    public function footer_text(string $footer_text, ?\WC_Email $email = null): string
    {
        unset($footer_text, $email);

        $shop_url    = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
        $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        $privacy_url = get_privacy_policy_url() ?: home_url('/privacy-policy/');

        return sprintf(
            '<div class="trovesia-email-footer"><p class="trovesia-email-footer__wordmark">TROVESIA</p><p class="trovesia-email-footer__tagline">Botanical hair care, thoughtfully chosen.</p><p class="trovesia-email-footer__links"><a href="%1$s">Shop</a><span>&bull;</span><a href="%2$s">My account</a><span>&bull;</span><a href="%3$s">Contact</a><span>&bull;</span><a href="%4$s">Privacy</a></p><p>Questions? <a href="mailto:service@trovesia.com">service@trovesia.com</a></p><p>&copy; %5$s Trovesia. All rights reserved.</p></div>',
            esc_url($shop_url),
            esc_url($account_url),
            esc_url(home_url('/contact/')),
            esc_url($privacy_url),
            esc_html(wp_date('Y'))
        );
    }

    /**
     * Add email-client-safe brand styles to every WooCommerce HTML email.
     *
     * WooCommerce passes the result through its CSS inliner, so the important
     * presentation survives Gmail, Outlook and Apple Mail CSS restrictions.
     *
     * @param string    $css   Core WooCommerce email CSS.
     * @param \WC_Email $email Current email instance.
     */
    public function add_brand_styles(string $css, \WC_Email $email): string
    {
        unset($email);

        return $css . <<<'CSS'

body,
#outer_wrapper {
    background-color: #eef2eb !important;
}

#wrapper {
    max-width: 640px !important;
    padding: 32px 12px !important;
}

#inner_wrapper {
    overflow: hidden !important;
    background-color: #fffefb !important;
    border: 1px solid #dfe3dc !important;
    border-radius: 24px !important;
    box-shadow: 0 20px 55px rgba(23, 33, 29, 0.10) !important;
}

#template_header_image,
#template_header {
    background-color: #102c25 !important;
}

#template_header_image {
    padding: 30px 40px 18px !important;
}

#template_header_image p {
    margin: 0 !important;
    text-align: center !important;
}

#template_header_image img {
    display: inline-block !important;
    width: auto !important;
    max-width: 210px !important;
    max-height: 58px !important;
    margin: 0 !important;
}

.email-logo-text,
.email-logo-text a {
    color: #fffefb !important;
    font-family: Georgia, Times, 'Times New Roman', serif !important;
    font-size: 27px !important;
    font-weight: 400 !important;
    line-height: 1.2 !important;
    letter-spacing: 0.22em !important;
    text-decoration: none !important;
}

#header_wrapper {
    padding: 0 40px 32px !important;
}

#header_wrapper h1,
#header_wrapper h1 a {
    color: #fffefb !important;
    font-family: Georgia, Times, 'Times New Roman', serif !important;
    font-size: 37px !important;
    font-weight: 400 !important;
    line-height: 1.15 !important;
    letter-spacing: -0.02em !important;
    text-align: center !important;
}

#template_body,
#body_content {
    background-color: #fffefb !important;
}

#body_content table td {
    padding: 26px 40px 40px !important;
}

#body_content table td td,
#body_content table td th {
    padding: 12px !important;
}

#body_content_inner {
    color: #4e5d56 !important;
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important;
    font-size: 15px !important;
    line-height: 1.65 !important;
}

#body_content_inner > p:first-of-type {
    color: #17211d !important;
    font-size: 17px !important;
}

.trovesia-email-service-strip {
    margin: 0 0 26px !important;
    padding: 13px 16px !important;
    color: #315f4f !important;
    background-color: #eef2eb !important;
    border: 1px solid #dfe3dc !important;
    border-radius: 999px !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    letter-spacing: 0.11em !important;
    line-height: 1.5 !important;
    text-align: center !important;
    text-transform: uppercase !important;
}

.trovesia-email-service-strip span {
    display: inline-block !important;
    white-space: nowrap !important;
}

.trovesia-email-service-strip i {
    display: inline-block !important;
    margin: 0 10px !important;
    color: #c6965c !important;
    font-size: 7px !important;
    font-style: normal !important;
    vertical-align: 2px !important;
}

h2,
h2.email-order-detail-heading {
    color: #17211d !important;
    font-family: Georgia, Times, 'Times New Roman', serif !important;
    font-size: 25px !important;
    font-weight: 400 !important;
    line-height: 1.3 !important;
    letter-spacing: -0.01em !important;
}

h3 {
    color: #17211d !important;
}

a,
.link {
    color: #315f4f !important;
}

.email-order-details {
    overflow: hidden !important;
    background-color: #f8f7f2 !important;
    border: 1px solid #dfe3dc !important;
    border-radius: 14px !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
}

#body_content table .email-order-details td,
#body_content table .email-order-details th {
    padding: 12px 14px !important;
}

.email-order-details thead th {
    color: #315f4f !important;
    background-color: #eef2eb !important;
    font-size: 11px !important;
    letter-spacing: 0.08em !important;
    text-transform: uppercase !important;
}

.email-order-details img {
    border-radius: 10px !important;
}

#body_content .email-order-details .order-totals-total th,
#body_content .email-order-details .order-totals-total td {
    color: #183c33 !important;
    font-weight: 700 !important;
}

#body_content .email-order-details .order-totals-total td {
    font-size: 21px !important;
}

.email-order-item-meta,
h2.email-order-detail-heading span,
.wc-item-meta {
    color: #67716b !important;
}

.hr,
#body_content hr {
    border-top-color: #dfe3dc !important;
}

#addresses td {
    color: #4e5d56 !important;
    background-color: #f8f7f2 !important;
    border: 1px solid #dfe3dc !important;
    border-radius: 14px !important;
    padding: 18px !important;
}

#addresses td + td {
    border-left: 10px solid #fffefb !important;
}

.address-title {
    color: #315f4f !important;
    font-size: 12px !important;
    letter-spacing: 0.07em !important;
    text-transform: uppercase !important;
}

.address {
    color: #17211d !important;
    line-height: 1.55 !important;
}

#body_content .button,
#body_content a.button,
#notification__action_button,
a[style*='background-color'] {
    display: inline-block !important;
    padding: 15px 28px !important;
    color: #fffefb !important;
    background-color: #315f4f !important;
    border: 1px solid #183c33 !important;
    border-radius: 999px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    letter-spacing: 0.09em !important;
    text-decoration: none !important;
    text-transform: uppercase !important;
}

#template_footer {
    background-color: #102c25 !important;
}

#template_footer #credit {
    padding: 32px 40px !important;
    color: #c9d3ce !important;
    border-top: 0 !important;
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important;
    font-size: 12px !important;
    line-height: 1.6 !important;
    text-align: center !important;
}

#template_footer #credit p {
    margin: 0 0 7px !important;
}

#template_footer #credit a {
    color: #fffefb !important;
    text-decoration: none !important;
}

.trovesia-email-footer__wordmark {
    color: #fffefb !important;
    font-family: Georgia, Times, 'Times New Roman', serif !important;
    font-size: 23px !important;
    letter-spacing: 0.22em !important;
}

.trovesia-email-footer__tagline {
    color: #ead8bd !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    letter-spacing: 0.11em !important;
    text-transform: uppercase !important;
}

.trovesia-email-footer__links span {
    margin: 0 7px !important;
    color: #c6965c !important;
}

@media screen and (max-width: 600px) {
    #wrapper {
        padding: 12px 6px !important;
    }

    #inner_wrapper {
        border-radius: 16px !important;
    }

    #template_header_image {
        padding: 24px 22px 14px !important;
    }

    .email-logo-text,
    .email-logo-text a {
        font-size: 22px !important;
    }

    #header_wrapper {
        padding: 0 22px 24px !important;
    }

    #header_wrapper h1 {
        font-size: 29px !important;
    }

    #body_content_inner_cell {
        padding: 22px 18px 28px !important;
    }

    #body_content_inner {
        font-size: 14px !important;
    }

    .trovesia-email-service-strip {
        border-radius: 14px !important;
        font-size: 9px !important;
    }

    .trovesia-email-service-strip span {
        display: block !important;
        margin: 2px 0 !important;
    }

    .trovesia-email-service-strip i {
        display: none !important;
    }

    h2,
    h2.email-order-detail-heading {
        font-size: 21px !important;
    }

    #addresses td {
        display: block !important;
        width: auto !important;
    }

    #addresses td + td {
        margin-top: 10px !important;
        border-left: 1px solid #dfe3dc !important;
    }

    #template_footer #credit {
        padding: 28px 18px !important;
    }
}
CSS;
    }
}
