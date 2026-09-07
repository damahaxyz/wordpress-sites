<?php
/**
 * Main plugin service.
 *
 * @package Shop13799Plugin
 */

declare(strict_types=1);

namespace Shop13799\Plugin;

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

    public static function activate(): void
    {
        update_option('shop13799_plugin_version', SHOP13799_PLUGIN_VERSION);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('plugins_loaded', [$this, 'loaded']);
        add_shortcode('shop_13799_year', [$this, 'year_shortcode']);
    }

    public function loaded(): void
    {
        /**
         * Fires after the 13799 shop plugin is ready.
         *
         * Register future site-specific services here or directly in this
         * class. Keep presentation-only behavior in the custom theme.
         *
         * @param Plugin $plugin Main plugin service.
         */
        do_action('shop_13799_plugin_loaded', $this);
    }

    /**
     * Example shortcode proving the custom plugin is active.
     */
    public function year_shortcode(): string
    {
        return esc_html(wp_date('Y'));
    }
}
