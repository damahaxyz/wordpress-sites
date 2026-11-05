<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }

/** Retired Multisite writer: retained entry points fail closed in the single-site release. */
final class CategoryMigration {
    public static function boot(): void {}
    public static function migrate(...$args): never { throw new \RuntimeException('单站多品牌已取代旧跨站迁移／同步。请使用当前 OPERATIONS 流程。'); }
}
