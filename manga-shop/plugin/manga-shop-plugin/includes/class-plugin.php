<?php
/**
 * Plugin bootstrap.
 *
 * @package MangaShopPlugin
 */

declare(strict_types=1);

namespace MangaShop\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        $settings = get_option(Settings::OPTION_NAME, []);

        if (! is_array($settings)) {
            $settings = [];
        }

        update_option(
            Settings::OPTION_NAME,
            wp_parse_args($settings, Settings::defaults())
        );
        update_option('manga_shop_plugin_version', MANGA_SHOP_PLUGIN_VERSION);
        Page_Cache::activate();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        (new Settings())->register();
        (new Product_Details())->register();
        (new SEO())->register();
        (new Account_Experience())->register();
        (new Variable_Stock())->register();
        Page_Cache::instance()->boot();

        add_action('admin_notices', [$this, 'woocommerce_notice']);
        add_filter('manga_shop_source_url', [$this, 'source_url']);
        add_filter('woocommerce_order_number', [$this, 'legacy_order_number'], 10, 2);
    }

    public function woocommerce_notice(): void
    {
        if (class_exists('WooCommerce') || ! current_user_can('activate_plugins')) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e('Manga Shop Plugin expects WooCommerce to be installed and active.', 'manga-shop-plugin'); ?></p>
        </div>
        <?php
    }

    public function source_url(string $sourceUrl = ''): string
    {
        $settings = get_option(Settings::OPTION_NAME, Settings::defaults());

        if (! is_array($settings)) {
            return Settings::defaults()['source_url'];
        }

        return esc_url_raw((string) ($settings['source_url'] ?? Settings::defaults()['source_url']));
    }

    /**
     * Keep migrated orders recognizable by their original order number.
     *
     * @param string|int $orderNumber Current WooCommerce order number.
     * @param object     $order       Order object supplied by WooCommerce.
     * @return string|int
     */
    public function legacy_order_number(string|int $orderNumber, object $order): string|int
    {
        if (! is_a($order, 'WC_Abstract_Order') || ! method_exists($order, 'get_meta')) {
            return $orderNumber;
        }

        $legacyOrderId = (int) $order->get_meta('_manga_legacy_order_id', true, 'edit');

        return $legacyOrderId > 0 ? (string) $legacyOrderId : $orderNumber;
    }
}
