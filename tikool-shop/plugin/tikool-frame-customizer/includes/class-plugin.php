<?php
/**
 * Plugin bootstrap.
 */

declare(strict_types=1);

namespace Tikool\FrameCustomizer;

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
        AI_Jobs::install();
        update_option('tikool_frame_customizer_version', TIKOOL_FC_VERSION);
        Config::migrate_legacy_products();
    }

    public function boot(): void
    {
        if ($this->booted || ! class_exists('WooCommerce')) {
            return;
        }

        $this->booted = true;
        AI_Jobs::maybe_upgrade();
        Admin::instance()->boot();
        AI_Styles::instance()->boot();
        Customizer::instance()->boot();
    }
}
