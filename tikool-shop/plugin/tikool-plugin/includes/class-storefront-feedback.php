<?php
/**
 * Storefront toast messages and add-to-cart progress feedback.
 *
 * @package TikoolShopPlugin
 */

declare(strict_types=1);

namespace Tikool\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Storefront_Feedback
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
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 30);
        add_action('wp_footer', [$this, 'render_toast_region'], 4);
    }

    public function enqueue_assets(): void
    {
        if (is_admin() || ! class_exists('WooCommerce')) {
            return;
        }

        $css = TIKOOL_PLUGIN_DIR . 'assets/css/storefront-feedback.css';
        $js = TIKOOL_PLUGIN_DIR . 'assets/js/storefront-feedback.js';

        wp_enqueue_style(
            'tikool-storefront-feedback',
            TIKOOL_PLUGIN_URL . 'assets/css/storefront-feedback.css',
            [],
            is_file($css) ? (string) filemtime($css) : TIKOOL_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tikool-storefront-feedback',
            TIKOOL_PLUGIN_URL . 'assets/js/storefront-feedback.js',
            ['jquery'],
            is_file($js) ? (string) filemtime($js) : TIKOOL_PLUGIN_VERSION,
            true
        );
        wp_localize_script('tikool-storefront-feedback', 'tikoolStorefrontFeedback', [
            'duration'          => 6000,
            'addingToCart'      => __('Adding to cart…', 'tikool-plugin'),
            'closeNotification' => __('Close notification', 'tikool-plugin'),
        ]);
    }

    public function render_toast_region(): void
    {
        if (is_admin() || ! class_exists('WooCommerce')) {
            return;
        }
        ?>
        <div class="tikool-toast-region" data-tikool-toast-region aria-live="polite" aria-atomic="false"></div>
        <?php
    }
}
