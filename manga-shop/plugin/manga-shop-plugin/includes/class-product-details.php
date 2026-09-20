<?php
/**
 * Product-specific storefront details.
 *
 * @package MangaShopPlugin
 */

declare(strict_types=1);

namespace MangaShop\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Product_Details
{
    private const ORDER_ACTIVITY_META = '_manga_order_activity_notice';
    private const ORDER_ACTIVITY_TRANSIENT = 'manga_shop_orders_last_24_hours';
    private const DELIVERY_ESTIMATE_ACTION = 'manga_shop_delivery_estimate';

    /** @var string[] */
    private const DELIVERY_COUNTRIES = [
        'US', 'CA', 'MX', 'AU', 'NZ', 'GB',
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
        'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    public function register(): void
    {
        add_action('add_meta_boxes_product', [$this, 'add_meta_box']);
        add_action('save_post_product', [$this, 'save_meta']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        // Keep fulfillment messaging above every express-payment option.
        // Stripe renders here at priority 1, while PayPal follows later in the
        // product summary, so priority 0 keeps all payment buttons together.
        add_action('woocommerce_after_add_to_cart_form', [$this, 'render_purchase_notices'], 0);
        add_action('wp_ajax_' . self::DELIVERY_ESTIMATE_ACTION, [$this, 'delivery_estimate']);
        add_action('wp_ajax_nopriv_' . self::DELIVERY_ESTIMATE_ACTION, [$this, 'delivery_estimate']);
        add_action('woocommerce_order_status_changed', [$this, 'clear_order_activity_cache'], 10, 4);
    }

    public function enqueue_assets(): void
    {
        if (! is_product()) {
            return;
        }

        $script = MANGA_SHOP_PLUGIN_DIR . 'assets/js/product-delivery.js';
        wp_enqueue_script(
            'manga-shop-product-delivery',
            plugins_url('assets/js/product-delivery.js', MANGA_SHOP_PLUGIN_FILE),
            [],
            is_file($script) ? (string) filemtime($script) : MANGA_SHOP_PLUGIN_VERSION,
            true
        );
        wp_localize_script('manga-shop-product-delivery', 'mangaShopDelivery', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::DELIVERY_ESTIMATE_ACTION,
        ]);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'manga-shop-product-details',
            __('Manga Shop product information', 'manga-shop-plugin'),
            [$this, 'render_meta_box'],
            'product',
            'normal',
            'default'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('manga_shop_product_details', 'manga_shop_product_details_nonce');
        $notice = (string) get_post_meta($post->ID, self::ORDER_ACTIVITY_META, true);
        ?>
        <p>
            <label for="manga-order-activity-notice"><strong><?php esc_html_e('Order activity notice', 'manga-shop-plugin'); ?></strong></label><br>
            <input
                class="widefat"
                id="manga-order-activity-notice"
                name="manga_order_activity_notice"
                type="text"
                value="<?php echo esc_attr($notice); ?>"
                placeholder="<?php esc_attr_e('Orders are processed daily', 'manga-shop-plugin'); ?>"
            >
            <span class="description"><?php esc_html_e('Optional. When filled, this text replaces the automatic past-24-hours order message below the Add to cart button. Leave blank to keep the automatic count and its default fallback.', 'manga-shop-plugin'); ?></span>
        </p>
        <?php
    }

    public function save_meta(int $post_id): void
    {
        if (
            ! isset($_POST['manga_shop_product_details_nonce'])
            || ! wp_verify_nonce(
                sanitize_text_field(wp_unslash((string) $_POST['manga_shop_product_details_nonce'])),
                'manga_shop_product_details'
            )
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || wp_is_post_revision($post_id)
            || ! current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        $notice = isset($_POST['manga_order_activity_notice'])
            ? sanitize_text_field(wp_unslash((string) $_POST['manga_order_activity_notice']))
            : '';

        if ($notice === '') {
            delete_post_meta($post_id, self::ORDER_ACTIVITY_META);
            return;
        }

        update_post_meta($post_id, self::ORDER_ACTIVITY_META, $notice);
    }

    public function render_purchase_notices(): void
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            return;
        }

        $custom_notice = trim((string) get_post_meta($product->get_id(), self::ORDER_ACTIVITY_META, true));
        $order_count = $custom_notice === '' ? $this->orders_last_24_hours() : 0;
        ?>
        <div class="manga-purchase-notices">
            <div class="manga-order-activity" role="status">
                <span class="manga-order-activity__icon" aria-hidden="true"></span>
                <strong>
                    <?php if ($custom_notice !== '') : ?>
                        <?php echo esc_html($custom_notice); ?>
                    <?php elseif ($order_count > 0) : ?>
                        <?php printf(esc_html__('%d orders placed in the past 24 hours', 'manga-shop-plugin'), esc_html((string) $order_count)); ?>
                    <?php else : ?>
                        <?php esc_html_e('Orders are processed daily', 'manga-shop-plugin'); ?>
                    <?php endif; ?>
                </strong>
            </div>
            <div class="manga-delivery-estimate" data-manga-delivery-estimate role="status" aria-live="polite" hidden>
                <span class="manga-delivery-estimate__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M3 6.5h10v10H3zM13 10h4l3 3v3.5h-7zM6.5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM17.5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>
                </span>
                <strong data-manga-delivery-message></strong>
            </div>
        </div>
        <?php
    }

    public function delivery_estimate(): void
    {
        $country = $this->visitor_country();

        if (! in_array($country, self::DELIVERY_COUNTRIES, true)) {
            wp_send_json_success(['eligible' => false]);
        }

        $start = current_datetime()->modify('+5 days');
        $end = current_datetime()->modify('+14 days');
        $range = $start->format('Y') === $end->format('Y')
            ? sprintf('%s – %s', wp_date('M j', $start->getTimestamp()), wp_date('M j, Y', $end->getTimestamp()))
            : sprintf('%s – %s', wp_date('M j, Y', $start->getTimestamp()), wp_date('M j, Y', $end->getTimestamp()));

        wp_send_json_success([
            'eligible' => true,
            'country'  => $country,
            'message'  => sprintf(
                /* translators: %s: estimated delivery date range. */
                __('Estimated delivery: %s', 'manga-shop-plugin'),
                $range
            ),
        ]);
    }

    public function clear_order_activity_cache(int $order_id, string $from, string $to, \WC_Order $order): void
    {
        unset($order);

        $visible_statuses = ['processing', 'completed', 'on-hold'];
        if (! in_array($from, $visible_statuses, true) && ! in_array($to, $visible_statuses, true)) {
            return;
        }

        delete_transient(self::ORDER_ACTIVITY_TRANSIENT);
        Page_Cache::instance()->schedule_purge(sprintf('Updated storefront order activity for order #%d', $order_id));
    }

    private function orders_last_24_hours(): int
    {
        $cached = get_transient(self::ORDER_ACTIVITY_TRANSIENT);
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
        set_transient(self::ORDER_ACTIVITY_TRANSIENT, $count, 5 * MINUTE_IN_SECONDS);

        return $count;
    }

    private function visitor_country(): string
    {
        $country = isset($_SERVER['HTTP_CF_IPCOUNTRY'])
            ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_CF_IPCOUNTRY'])))
            : '';

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '';
    }
}
