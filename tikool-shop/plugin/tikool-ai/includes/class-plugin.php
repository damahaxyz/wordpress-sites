<?php
/**
 * Plugin bootstrap.
 */

declare(strict_types=1);

namespace Tikool\AI;

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
        if (get_option(Settings::OPTION_NAME, null) === null) {
            add_option(Settings::OPTION_NAME, Settings::defaults(), '', false);
        }
        self::upgrade_settings();
        Generation_Log::install();
        update_option('tikool_ai_version', TIKOOL_AI_VERSION, false);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        self::upgrade_settings();
        Generation_Log::maybe_upgrade();
        add_action('tikool_ai_mark_generation_stale', [Generation_Log::class, 'mark_stale']);
        if (is_admin()) {
            Admin::instance()->boot();
        }

        /** Fires after Tikool AI's PHP API is ready for customizer plugins. */
        do_action('tikool_ai_ready', Service::instance());
    }

    private static function upgrade_settings(): void
    {
        $installed = (string) get_option('tikool_ai_version', '');
        if ($installed !== '' && version_compare($installed, TIKOOL_AI_VERSION, '>=')) {
            return;
        }
        $stored = get_option(Settings::OPTION_NAME, []);
        $stored = is_array($stored) ? $stored : [];
        if ($installed === '' || version_compare($installed, '1.6.0', '<')) {
            $old_model = sanitize_text_field((string) ($stored['model'] ?? ''));
            if ($old_model === '' || $old_model === 'doubao-seedream-5-0-260128') {
                $stored['model'] = Settings::DEFAULT_MODEL;
            }
            if (str_contains((string) ($stored['model'] ?? ''), 'seedream-5-0-flash')) {
                $stored['sequential_image_generation'] = 'disabled';
            }
            update_option(Settings::OPTION_NAME, $stored, false);
        }
        update_option('tikool_ai_version', TIKOOL_AI_VERSION, false);
    }
}
