<?php
/**
 * Main plugin service.
 *
 * @package TikoolShopPlugin
 */

declare(strict_types=1);

namespace Tikool\Plugin;

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
        update_option('tikool_plugin_version', TIKOOL_PLUGIN_VERSION);
        Site_Experience::activate();
        Email_Experience::activate();
        Page_Cache::activate();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('plugins_loaded', [$this, 'loaded']);
        add_shortcode('tikool_year', [$this, 'year_shortcode']);

        Site_Experience::instance()->boot();
        Account_Experience::instance()->boot();
        Email_Experience::instance()->boot();
        Page_Cache::instance()->boot();
        Review_Experience::instance()->boot();
        Addon_Pricing::instance()->boot();
    }

    public function loaded(): void
    {
        /**
         * Fires after the Tikool shop plugin is ready.
         *
         * Register future site-specific services here or directly in this
         * class. Keep presentation-only behavior in the custom theme.
         *
         * @param Plugin $plugin Main plugin service.
         */
        do_action('tikool_plugin_loaded', $this);
    }

    /**
     * Example shortcode proving the custom plugin is active.
     */
    public function year_shortcode(): string
    {
        return esc_html(wp_date('Y'));
    }
}
