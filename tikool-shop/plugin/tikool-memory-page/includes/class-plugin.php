<?php
/**
 * Plugin bootstrap.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

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
        Memory_Pages::register_post_type();
        Tasks::register_post_type();
        Account::register_endpoint();
        Claims::register_route();
        Storage::ensure_private_root();
        update_option('tikool_memory_page_version', TIKOOL_MEMORY_PAGE_VERSION);
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        Settings::instance()->boot();
        Tasks::instance()->boot();
        Memory_Pages::instance()->boot();
        Claims::instance()->boot();
        Account::instance()->boot();

        add_action('admin_notices', [$this, 'woocommerce_notice']);
    }

    public function woocommerce_notice(): void
    {
        if (class_exists('WooCommerce') || ! current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__('Tikool Memory Page requires WooCommerce to be installed and active.', 'tikool-memory-page')
            . '</p></div>';
    }
}
