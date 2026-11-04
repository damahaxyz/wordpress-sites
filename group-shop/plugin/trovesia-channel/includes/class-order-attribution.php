<?php
/**
 * WooCommerce order attribution and conversion synchronization.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

namespace Trovesia\Channel;

if (! defined('ABSPATH')) {
    exit;
}

final class Order_Attribution
{
    public const CHANNEL_META = '_trovesia_channel_id';
    public const CHANNEL_CODE_META = '_trovesia_channel_code_snapshot';
    public const CAMPAIGN_META = '_trovesia_campaign_id';
    public const VISIT_META = '_trovesia_visit_id';
    public const FIRST_CHANNEL_META = '_trovesia_first_channel_id';
    public const ATTRIBUTED_AT_META = '_trovesia_channel_attributed_at';
    public const MODEL_META = '_trovesia_attribution_model';

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

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('woocommerce_checkout_create_order', [$this, 'capture'], 20, 2);
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'capture'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'handle_store_api_processed'], 20);
        add_action('woocommerce_checkout_order_processed', [$this, 'sync_conversion'], 20);
        add_action('woocommerce_new_order', [$this, 'sync_conversion'], 50);
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 20, 4);
        add_action('woocommerce_order_refunded', [$this, 'handle_refund'], 20, 2);
        add_action('woocommerce_refund_created', [$this, 'handle_refund_created'], 20, 2);
    }

    /** @param mixed $request Checkout request. */
    public function capture(\WC_Order $order, $request = null): void
    {
        unset($request);

        if ((int) $order->get_meta(self::CHANNEL_META, true) > 0) {
            return;
        }

        $visit = Attribution::current_visit();
        if (! $visit) {
            return;
        }

        $order->update_meta_data(self::CHANNEL_META, (int) $visit->channel_id);
        $order->update_meta_data(self::CHANNEL_CODE_META, (string) $visit->channel_code);
        $order->update_meta_data(self::CAMPAIGN_META, (int) $visit->campaign_id);
        $order->update_meta_data(self::VISIT_META, (int) $visit->id);
        $order->update_meta_data(self::FIRST_CHANNEL_META, $this->first_channel_id((string) $visit->visitor_token));
        $order->update_meta_data(self::ATTRIBUTED_AT_META, gmdate('Y-m-d H:i:s'));
        $order->update_meta_data(self::MODEL_META, 'last_valid_channel');

        if ($order->get_id() > 0) {
            $order->save_meta_data();
            $this->sync_conversion($order->get_id());
        }
    }

    public function sync_conversion(int $order_id): void
    {
        global $wpdb;

        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $channel_id = (int) $order->get_meta(self::CHANNEL_META, true);
        $campaign_id = (int) $order->get_meta(self::CAMPAIGN_META, true);
        $visit_id = (int) $order->get_meta(self::VISIT_META, true);
        if ($channel_id <= 0) {
            return;
        }

        $created = $order->get_date_created();
        $paid = $order->get_date_paid();
        $now = gmdate('Y-m-d H:i:s');
        $created_at = $created ? gmdate('Y-m-d H:i:s', $created->getTimestamp()) : $now;
        $paid_at = $paid ? gmdate('Y-m-d H:i:s', $paid->getTimestamp()) : null;

        $sql = "INSERT INTO " . Database::table('conversions') . "
            (order_id, channel_id, campaign_id, visit_id, status, currency, order_total, refund_total, paid_at, created_at, updated_at)
            VALUES (%d, %d, %d, %d, %s, %s, %f, %f, NULLIF(%s, ''), %s, %s)
            ON DUPLICATE KEY UPDATE
                channel_id = VALUES(channel_id), campaign_id = VALUES(campaign_id), visit_id = VALUES(visit_id),
                status = VALUES(status), currency = VALUES(currency), order_total = VALUES(order_total),
                refund_total = VALUES(refund_total), paid_at = VALUES(paid_at), updated_at = VALUES(updated_at)";

        $wpdb->query(
            $wpdb->prepare(
                $sql,
                $order_id,
                $channel_id,
                $campaign_id,
                $visit_id,
                $order->get_status(),
                $order->get_currency(),
                (float) $order->get_total(),
                (float) $order->get_total_refunded(),
                $paid_at ?? '',
                $created_at,
                $now
            )
        );
    }

    public function handle_store_api_processed(\WC_Order $order): void
    {
        $this->sync_conversion($order->get_id());
    }

    /** @param mixed $order */
    public function handle_status_change(int $order_id, string $from, string $to, $order): void
    {
        unset($from, $to, $order);
        $this->sync_conversion($order_id);
    }

    public function handle_refund(int $order_id, int $refund_id): void
    {
        unset($refund_id);
        $this->sync_conversion($order_id);
    }

    /** @param mixed $args */
    public function handle_refund_created(int $refund_id, $args): void
    {
        unset($args);
        $refund = wc_get_order($refund_id);
        if ($refund instanceof \WC_Order_Refund) {
            $this->sync_conversion($refund->get_parent_id());
        }
    }

    private function first_channel_id(string $visitor_token): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT channel_id FROM ' . Database::table('visits') . ' WHERE visitor_token = %s AND is_bot = 0 ORDER BY clicked_at ASC, id ASC LIMIT 1',
                $visitor_token
            )
        );
    }
}
