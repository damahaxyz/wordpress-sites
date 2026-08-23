<?php
/**
 * Main plugin service.
 *
 * @package AromamatrixPlugin
 */

declare(strict_types=1);

namespace Aromamatrix\Plugin;

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
        update_option('aromamatrix_plugin_version', AROMAMATRIX_PLUGIN_VERSION);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('plugins_loaded', [$this, 'loaded']);
    }

    public function loaded(): void
    {
        if (class_exists('WooCommerce')) {
            (new AccountAccess())->register();
            (new FactoryModel())->register();
            (new ProductNote())->register();
        }

        /**
         * Fires after the AROMAMATRIX plugin is ready.
         *
         * Add future site-specific services from this hook or register them
         * directly in this class.
         *
         * @param Plugin $plugin Main plugin service.
         */
        do_action('aromamatrix_plugin_loaded', $this);
    }
}
