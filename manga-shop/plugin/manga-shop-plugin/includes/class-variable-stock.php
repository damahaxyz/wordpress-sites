<?php
/**
 * Keep variable-product parent availability in sync with its variations.
 *
 * @package MangaShopPlugin
 */

declare(strict_types=1);

namespace MangaShop\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Variable_Stock
{
    /** @var array<int, true> */
    private array $pending_parent_ids = [];

    private bool $flush_scheduled = false;

    public function register(): void
    {
        add_action('woocommerce_variation_set_stock', [$this, 'queue_parent_sync']);
        add_action('woocommerce_variation_set_stock_status', [$this, 'queue_parent_sync'], 10, 3);
        add_action('woocommerce_update_product_variation', [$this, 'queue_parent_sync']);
        add_action('save_post_product_variation', [$this, 'queue_parent_sync'], 100, 3);
    }

    /**
     * Queue one parent sync per request, even when several variations change.
     *
     * @param mixed $subject Variation object or variation post ID.
     */
    public function queue_parent_sync(mixed $subject): void
    {
        $parentId = 0;
        if (is_object($subject) && method_exists($subject, 'get_parent_id')) {
            $parentId = (int) $subject->get_parent_id();
        } elseif (is_numeric($subject)) {
            $postId = (int) $subject;
            if (get_post_type($postId) === 'product_variation') {
                $parentId = (int) wp_get_post_parent_id($postId);
            }
        }

        if ($parentId <= 0) {
            return;
        }

        $this->pending_parent_ids[$parentId] = true;
        if (! $this->flush_scheduled) {
            $this->flush_scheduled = true;
            add_action('shutdown', [$this, 'flush'], 20);
        }
    }

    public function flush(): void
    {
        if (! $this->flush_scheduled || ! class_exists('WC_Product_Variable')) {
            return;
        }

        $this->flush_scheduled = false;
        $parentIds = array_keys($this->pending_parent_ids);
        $this->pending_parent_ids = [];

        foreach ($parentIds as $parentId) {
            \WC_Product_Variable::sync_stock_status((int) $parentId);
            wc_delete_product_transients((int) $parentId);
            clean_post_cache((int) $parentId);
        }

        if ($parentIds !== []) {
            Page_Cache::instance()->schedule_purge(
                sprintf('Synchronized stock for %d variable products', count($parentIds))
            );
        }
    }
}
