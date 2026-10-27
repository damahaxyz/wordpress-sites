<?php
/**
 * Main plugin service.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

namespace Trovesia\Channel;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
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

        add_action('plugins_loaded', [$this, 'load']);
    }

    public function load(): void
    {
        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'render_woocommerce_notice']);
            return;
        }

        if (get_option('trovesia_channel_db_version') !== Database::VERSION) {
            Database::install();
        }

        Attribution::instance()->boot();
        Order_Attribution::instance()->boot();
        Admin::instance()->boot();
        Portal::instance()->boot();

        do_action('trovesia_channel_loaded', $this);
    }

    public function render_woocommerce_notice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        esc_html_e('Trovesia Channel requires WooCommerce to be active.', 'trovesia-channel');
        echo '</p></div>';
    }
}
