<?php
/**
 * Channel-link routing and first-party attribution.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

namespace Trovesia\Channel;

if (! defined('ABSPATH')) {
    exit;
}

final class Attribution
{
    private const VISIT_COOKIE = 'trovesia_channel_visit';
    private const VISITOR_COOKIE = 'trovesia_channel_visitor';
    private const SESSION_KEY = 'trovesia_channel_visit';

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
        add_action('init', [self::class, 'add_rewrite_rule']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'handle_channel_link'], 0);
    }

    public static function add_rewrite_rule(): void
    {
        add_rewrite_rule('^go/([A-Za-z0-9_-]+)/?$', 'index.php?trovesia_channel_token=$matches[1]', 'top');
    }

    /** @param list<string> $query_vars */
    public function register_query_var(array $query_vars): array
    {
        $query_vars[] = 'trovesia_channel_token';

        return $query_vars;
    }

    public function handle_channel_link(): void
    {
        $token = sanitize_text_field((string) get_query_var('trovesia_channel_token'));
        if ($token === '') {
            return;
        }

        nocache_headers();

        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) {
            $this->safe_fallback();
        }

        $campaign = Database::get_campaign_by_token($token);
        if (! $campaign || 'active' !== $campaign->status || 'active' !== $campaign->channel_status) {
            $this->safe_fallback();
        }

        $destination = $this->destination_url($campaign);
        if ($destination === '') {
            $this->safe_fallback();
        }

        /**
         * Integrations with a consent manager may return false here. The raw
         * request will still be safely redirected, but no visitor cookie or
         * visit row will be created.
         */
        $can_track = (bool) apply_filters('trovesia_channel_can_track', true, $campaign);
        if ($can_track) {
            $this->record_visit($campaign, $destination);
        }

        wp_safe_redirect($destination, 302, 'Trovesia Channel');
        exit;
    }

    /** @return object|null */
    public static function current_visit(): ?object
    {
        $visit_token = '';

        if (function_exists('WC') && WC()->session) {
            $visit_token = (string) WC()->session->get(self::SESSION_KEY, '');
        }

        if ($visit_token === '' && isset($_COOKIE[self::VISIT_COOKIE])) {
            $visit_token = sanitize_text_field(wp_unslash((string) $_COOKIE[self::VISIT_COOKIE]));
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $visit_token)) {
            return null;
        }

        return Database::get_current_visit($visit_token);
    }

    public function destination_url(object $campaign): string
    {
        $target_id = (int) $campaign->target_id;

        switch ((string) $campaign->target_type) {
            case 'home':
                return home_url('/');

            case 'shop':
                $url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : '';
                return is_string($url) ? $url : '';

            case 'product':
                $product = wc_get_product($target_id);
                if (! $product || 'publish' !== $product->get_status() || ! $product->is_visible()) {
                    return '';
                }

                return (string) get_permalink($target_id);

            case 'product_category':
                $term = get_term($target_id, 'product_cat');
                if (! $term instanceof \WP_Term || is_wp_error($term)) {
                    return '';
                }

                $url = get_term_link($term);
                return is_wp_error($url) ? '' : (string) $url;

            default:
                return '';
        }
    }

    private function record_visit(object $campaign, string $destination): void
    {
        global $wpdb;

        $visit_token = bin2hex(random_bytes(32));
        $visitor_token = $this->visitor_token();
        $now = gmdate('Y-m-d H:i:s');
        $is_bot = $this->is_probable_bot();
        $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash((string) $_SERVER['HTTP_REFERER'])) : '';
        $referrer_host = $referrer !== '' ? (string) wp_parse_url($referrer, PHP_URL_HOST) : '';

        $inserted = $wpdb->insert(
            Database::table('visits'),
            [
                'visit_token' => $visit_token,
                'visitor_token' => $visitor_token,
                'channel_id' => (int) $campaign->channel_id,
                'campaign_id' => (int) $campaign->id,
                'landing_url' => esc_url_raw($destination),
                'referrer_host' => sanitize_text_field($referrer_host),
                'device' => $this->device_type(),
                'is_bot' => $is_bot ? 1 : 0,
                'clicked_at' => $now,
                'last_seen_at' => $now,
            ],
            ['%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Database::table('campaigns') . ' SET clicks = clicks + 1, updated_at = %s WHERE id = %d',
                $now,
                (int) $campaign->id
            )
        );

        if ($inserted !== 1 || $is_bot) {
            return;
        }

        $this->set_cookie(
            self::VISIT_COOKIE,
            $visit_token,
            time() + (Installer::attribution_window_days() * DAY_IN_SECONDS)
        );
        $this->set_cookie(self::VISITOR_COOKIE, $visitor_token, time() + YEAR_IN_SECONDS);

        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_KEY, $visit_token);
        }
    }

    private function visitor_token(): string
    {
        if (isset($_COOKIE[self::VISITOR_COOKIE])) {
            $token = sanitize_text_field(wp_unslash((string) $_COOKIE[self::VISITOR_COOKIE]));
            if (preg_match('/^[a-f0-9]{64}$/', $token)) {
                return $token;
            }
        }

        return bin2hex(random_bytes(32));
    }

    private function set_cookie(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(
            $name,
            $value,
            [
                'expires' => $expires,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $_COOKIE[$name] = $value;
    }

    private function is_probable_bot(): bool
    {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';

        return $agent === '' || (bool) preg_match('/bot|crawler|spider|preview|facebookexternalhit|headless|curl|wget/', $agent);
    }

    private function device_type(): string
    {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';

        if (preg_match('/ipad|tablet|kindle|silk/', $agent)) {
            return 'tablet';
        }

        return wp_is_mobile() ? 'mobile' : 'desktop';
    }

    private function safe_fallback(): void
    {
        wp_safe_redirect(home_url('/'), 302, 'Trovesia Channel');
        exit;
    }
}
