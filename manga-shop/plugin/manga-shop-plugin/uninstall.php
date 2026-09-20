<?php
/**
 * Remove only options owned by Manga Shop Plugin.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('manga_shop_settings');
delete_option('manga_shop_plugin_version');
