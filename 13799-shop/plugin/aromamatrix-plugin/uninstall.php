<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package AromamatrixPlugin
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('aromamatrix_plugin_version');
