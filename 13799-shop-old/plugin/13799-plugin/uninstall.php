<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package Shop13799Plugin
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('shop13799_plugin_version');
