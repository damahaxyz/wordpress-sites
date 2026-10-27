<?php
/**
 * Private frontend portal for channel managers.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

namespace Trovesia\Channel;

if (! defined('ABSPATH')) {
    exit;
}

final class Portal
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

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        add_shortcode('trovesia_channel_portal', [$this, 'render']);
        add_action('admin_post_nopriv_trovesia_channel_login', [$this, 'login']);
        add_action('admin_post_trovesia_channel_login', [$this, 'login']);
        add_action('admin_post_trovesia_channel_create_link', [$this, 'create_link']);
        add_action('admin_post_trovesia_channel_toggle_link', [$this, 'toggle_link']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('template_redirect', [$this, 'protect_portal_response'], 0);
        add_filter('wp_robots', [$this, 'portal_robots']);
        add_filter('body_class', [$this, 'portal_body_class']);
    }

    public function enqueue_assets(): void
    {
        $page_id = (int) get_option(Installer::PORTAL_PAGE_OPTION, 0);
        if ($page_id <= 0 || ! is_page($page_id)) {
            return;
        }

        wp_enqueue_style('trovesia-channel-portal', TROVESIA_CHANNEL_URL . 'assets/portal.css', [], TROVESIA_CHANNEL_VERSION);
    }

    public function protect_portal_response(): void
    {
        if ($this->is_portal_page()) {
            nocache_headers();
        }
    }

    /** @param array<string,bool> $robots
     *  @return array<string,bool>
     */
    public function portal_robots(array $robots): array
    {
        if ($this->is_portal_page()) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    public function render(): string
    {
        if (! is_user_logged_in()) {
            ob_start();
            ?>
            <section class="trovesia-channel-login" aria-labelledby="trovesia-channel-login-title">
                <div class="trovesia-channel-login__brand" aria-hidden="true">TROVESIA</div>
                <p class="trovesia-channel-eyebrow"><?php esc_html_e('Partner workspace', 'trovesia-channel'); ?></p>
                <h1 id="trovesia-channel-login-title"><?php esc_html_e('Welcome back', 'trovesia-channel'); ?></h1>
                <p class="trovesia-channel-login__intro"><?php esc_html_e('Sign in to view your channel performance, attributed orders, and tracking links.', 'trovesia-channel'); ?></p>
                <?php $this->render_login_notice(); ?>
                <form name="loginform" id="loginform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="trovesia_channel_login">
                    <?php wp_nonce_field('trovesia_channel_login', 'trovesia_channel_login_nonce'); ?>
                    <p class="login-username">
                        <label for="user_login"><?php esc_html_e('Email or username', 'trovesia-channel'); ?></label>
                        <input type="text" name="log" id="user_login" class="input" autocomplete="username" required>
                    </p>
                    <p class="login-password">
                        <label for="user_pass"><?php esc_html_e('Password', 'trovesia-channel'); ?></label>
                        <input type="password" name="pwd" id="user_pass" class="input" autocomplete="current-password" required>
                    </p>
                    <p class="login-remember"><label><input name="rememberme" type="checkbox" id="rememberme" value="forever"> <?php esc_html_e('Keep me signed in', 'trovesia-channel'); ?></label></p>
                    <p class="login-submit"><button type="submit" id="wp-submit"><?php esc_html_e('Sign in', 'trovesia-channel'); ?></button></p>
                </form>
                <div class="trovesia-channel-login__footer">
                    <a href="<?php echo esc_url(wp_lostpassword_url(get_permalink())); ?>"><?php esc_html_e('Forgot password?', 'trovesia-channel'); ?></a>
                    <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Back to store', 'trovesia-channel'); ?></a>
                </div>
            </section>
            <?php
            return (string) ob_get_clean();
        }

        $channel = Database::get_user_channel(get_current_user_id());
        if (! $channel) {
            return '<div class="woocommerce-error" role="alert">' . esc_html__('This account is not assigned to a channel.', 'trovesia-channel') . '</div>';
        }

        if ('archived' === $channel->status) {
            return '<div class="woocommerce-info" role="status">' . esc_html__('This channel is archived. Contact the store administrator for access.', 'trovesia-channel') . '</div>';
        }

        $range = isset($_GET['range']) ? sanitize_key(wp_unslash((string) $_GET['range'])) : '30';
        $range = in_array($range, ['7', '30', '90', 'all'], true) ? $range : '30';
        $stats = $this->stats((int) $channel->id, $range);
        $links = $this->links((int) $channel->id);
        $orders = $this->orders((int) $channel->id, $range);

        ob_start();
        ?>
        <section class="trovesia-channel-portal">
            <header class="trovesia-channel-header">
                <div class="trovesia-channel-header__copy"><p class="trovesia-channel-eyebrow"><?php esc_html_e('Channel performance', 'trovesia-channel'); ?></p><h1><?php echo esc_html($channel->name); ?></h1><p><?php esc_html_e('Track visits, attributed sales, and campaign performance from one place.', 'trovesia-channel'); ?></p></div>
                <a class="trovesia-channel-logout" href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>"><?php esc_html_e('Sign out', 'trovesia-channel'); ?></a>
            </header>

            <?php $this->render_portal_notice(); ?>

            <div class="trovesia-channel-toolbar">
                <div><span><?php esc_html_e('Overview', 'trovesia-channel'); ?></span><small><?php esc_html_e('Reporting period', 'trovesia-channel'); ?></small></div>
                <nav class="trovesia-channel-ranges" aria-label="<?php esc_attr_e('Reporting period', 'trovesia-channel'); ?>">
                    <?php foreach (['7' => __('7 days', 'trovesia-channel'), '30' => __('30 days', 'trovesia-channel'), '90' => __('90 days', 'trovesia-channel'), 'all' => __('All time', 'trovesia-channel')] as $key => $label) : ?>
                        <a class="<?php echo $range === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('range', $key, get_permalink())); ?>" <?php echo $range === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="trovesia-channel-cards">
                <?php $this->metric(__('Visits', 'trovesia-channel'), number_format_i18n($stats['visits'])); ?>
                <?php $this->metric(__('Unique visitors', 'trovesia-channel'), number_format_i18n($stats['visitors'])); ?>
                <?php $this->metric(__('Paid orders', 'trovesia-channel'), number_format_i18n($stats['paid_orders'])); ?>
                <?php $this->metric(__('Conversion rate', 'trovesia-channel'), number_format_i18n($stats['conversion_rate'], 2) . '%'); ?>
                <?php $this->metric(__('Paid revenue', 'trovesia-channel'), wc_price($stats['paid_revenue'])); ?>
                <?php $this->metric(__('Net revenue', 'trovesia-channel'), wc_price($stats['net_revenue'])); ?>
            </div>

            <div class="trovesia-channel-panel">
                <div class="trovesia-channel-panel__heading"><div><p class="trovesia-channel-eyebrow"><?php esc_html_e('Campaign tools', 'trovesia-channel'); ?></p><h2><?php esc_html_e('Create a tracking link', 'trovesia-channel'); ?></h2></div><p><?php esc_html_e('Choose where visitors should land, then share the generated link in your campaign.', 'trovesia-channel'); ?></p></div>
                <?php if ('active' !== $channel->status) : ?>
                    <p class="trovesia-channel-empty"><?php esc_html_e('This channel is paused. Existing reports remain available, but new links cannot be created.', 'trovesia-channel'); ?></p>
                <?php else : ?>
                    <form class="trovesia-channel-link-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="trovesia_channel_create_link">
                        <?php wp_nonce_field('trovesia_channel_create_link'); ?>
                        <p><label for="trovesia-campaign-name"><?php esc_html_e('Campaign name', 'trovesia-channel'); ?></label><input id="trovesia-campaign-name" name="campaign_name" maxlength="190" placeholder="TikTok Video 01"></p>
                        <p><label for="trovesia-link-target"><?php esc_html_e('Destination', 'trovesia-channel'); ?></label><select id="trovesia-link-target" name="target" required><?php $this->render_target_options(); ?></select></p>
                        <button class="button alt" type="submit"><?php esc_html_e('Generate link', 'trovesia-channel'); ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="trovesia-channel-panel">
                <div class="trovesia-channel-panel__heading"><div><p class="trovesia-channel-eyebrow"><?php esc_html_e('Campaign library', 'trovesia-channel'); ?></p><h2><?php esc_html_e('Tracking links', 'trovesia-channel'); ?></h2></div><p><?php esc_html_e('Review link activity or pause a campaign without deleting its history.', 'trovesia-channel'); ?></p></div>
                <div class="trovesia-channel-table-wrap"><table class="trovesia-channel-table"><thead><tr><th><?php esc_html_e('Campaign', 'trovesia-channel'); ?></th><th><?php esc_html_e('Destination', 'trovesia-channel'); ?></th><th><?php esc_html_e('Link', 'trovesia-channel'); ?></th><th><?php esc_html_e('Visits', 'trovesia-channel'); ?></th><th><?php esc_html_e('Paid orders', 'trovesia-channel'); ?></th><th><?php esc_html_e('Status', 'trovesia-channel'); ?></th><th></th></tr></thead><tbody>
                <?php if ($links === []) : ?><tr><td class="trovesia-channel-empty" colspan="7"><?php esc_html_e('No tracking links yet.', 'trovesia-channel'); ?></td></tr><?php endif; ?>
                <?php foreach ($links as $link) : ?>
                    <tr>
                        <td><?php echo esc_html($link->name !== '' ? $link->name : __('Untitled campaign', 'trovesia-channel')); ?></td>
                        <td><?php echo esc_html($this->target_label($link)); ?></td>
                        <td><input class="trovesia-channel-copy" readonly value="<?php echo esc_attr(home_url('/go/' . $link->token . '/')); ?>" onclick="this.select()"></td>
                        <td><?php echo esc_html((string) $link->human_visits); ?></td>
                        <td><?php echo esc_html((string) $link->paid_orders); ?></td>
                        <td><span class="trovesia-channel-status is-<?php echo esc_attr(sanitize_html_class((string) $link->status)); ?>"><?php echo esc_html(ucfirst($link->status)); ?></span></td>
                        <td><a class="trovesia-channel-table__action" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=trovesia_channel_toggle_link&campaign_id=' . (int) $link->id), 'trovesia_channel_toggle_link_' . (int) $link->id)); ?>"><?php echo 'active' === $link->status ? esc_html__('Pause', 'trovesia-channel') : esc_html__('Activate', 'trovesia-channel'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>

            <div class="trovesia-channel-panel">
                <div class="trovesia-channel-panel__heading"><div><p class="trovesia-channel-eyebrow"><?php esc_html_e('Sales activity', 'trovesia-channel'); ?></p><h2><?php esc_html_e('Attributed orders', 'trovesia-channel'); ?></h2></div><p><?php esc_html_e('Orders credited to your channel during the selected reporting period.', 'trovesia-channel'); ?></p></div>
                <div class="trovesia-channel-table-wrap"><table class="trovesia-channel-table"><thead><tr><th><?php esc_html_e('Order', 'trovesia-channel'); ?></th><th><?php esc_html_e('Date', 'trovesia-channel'); ?></th><th><?php esc_html_e('Products', 'trovesia-channel'); ?></th><th><?php esc_html_e('Status', 'trovesia-channel'); ?></th><th><?php esc_html_e('Total', 'trovesia-channel'); ?></th><th><?php esc_html_e('Refunded', 'trovesia-channel'); ?></th></tr></thead><tbody>
                <?php if ($orders === []) : ?><tr><td class="trovesia-channel-empty" colspan="6"><?php esc_html_e('No attributed orders in this reporting period.', 'trovesia-channel'); ?></td></tr><?php endif; ?>
                <?php foreach ($orders as $row) : ?>
                    <?php $order = wc_get_order((int) $row->order_id); if (! $order) { continue; } ?>
                    <tr><td><strong>#<?php echo esc_html((string) $order->get_order_number()); ?></strong></td><td><?php echo esc_html(wp_date(get_option('date_format'), strtotime($row->created_at . ' UTC'))); ?></td><td><?php echo esc_html($this->order_products($order)); ?></td><td><span class="trovesia-channel-status is-<?php echo esc_attr(sanitize_html_class((string) $order->get_status())); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span></td><td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td><td><?php echo wp_kses_post(wc_price((float) $row->refund_total, ['currency' => $row->currency])); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    public function login(): void
    {
        if (is_user_logged_in()) {
            $this->login_redirect();
        }

        $nonce = isset($_POST['trovesia_channel_login_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['trovesia_channel_login_nonce'])) : '';
        if (! wp_verify_nonce($nonce, 'trovesia_channel_login')) {
            $this->login_redirect('expired');
        }

        $login = isset($_POST['log']) ? sanitize_text_field(wp_unslash((string) $_POST['log'])) : '';
        $password = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '';
        if ($login === '' || $password === '') {
            $this->login_redirect('failed');
        }

        $user = wp_signon(
            [
                'user_login' => $login,
                'user_password' => $password,
                'remember' => ! empty($_POST['rememberme']),
            ],
            is_ssl()
        );

        if (is_wp_error($user)) {
            $this->login_redirect('failed');
        }

        wp_safe_redirect($this->portal_url());
        exit;
    }

    public function create_link(): void
    {
        global $wpdb;

        $channel = $this->authorize_channel_user();
        check_admin_referer('trovesia_channel_create_link');
        if ('active' !== $channel->status) {
            $this->portal_redirect('channel_paused');
        }

        $campaign_name = isset($_POST['campaign_name']) ? sanitize_text_field(wp_unslash((string) $_POST['campaign_name'])) : '';
        $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash((string) $_POST['target'])) : '';
        [$target_type, $target_id] = array_pad(explode(':', $target, 2), 2, '0');
        $target_type = sanitize_key($target_type);
        $target_id = absint($target_id);

        if (! $this->valid_target($target_type, $target_id)) {
            $this->portal_redirect('invalid_target');
        }

        $now = gmdate('Y-m-d H:i:s');
        $token = $this->unique_token();
        $result = $wpdb->insert(
            Database::table('campaigns'),
            [
                'channel_id' => (int) $channel->id,
                'token' => $token,
                'name' => $campaign_name,
                'target_type' => $target_type,
                'target_id' => $target_id,
                'status' => 'active',
                'clicks' => 0,
                'created_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s']
        );

        if ($result !== 1) {
            $this->portal_redirect('link_failed');
        }

        Database::audit('campaign_created', 'campaign', (int) $wpdb->insert_id, ['channel_id' => (int) $channel->id, 'target_type' => $target_type, 'target_id' => $target_id]);
        $this->portal_redirect('link_created');
    }

    public function toggle_link(): void
    {
        global $wpdb;

        $channel = $this->authorize_channel_user();
        $campaign_id = isset($_GET['campaign_id']) ? absint($_GET['campaign_id']) : 0;
        check_admin_referer('trovesia_channel_toggle_link_' . $campaign_id);

        $campaign = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Database::table('campaigns') . ' WHERE id = %d AND channel_id = %d LIMIT 1', $campaign_id, (int) $channel->id)
        );
        if (! $campaign) {
            $this->portal_redirect('invalid_target');
        }

        $status = 'active' === $campaign->status ? 'paused' : 'active';
        $wpdb->update(Database::table('campaigns'), ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $campaign_id], ['%s', '%s'], ['%d']);
        Database::audit('campaign_status_changed', 'campaign', $campaign_id, ['status' => $status]);
        $this->portal_redirect('link_updated');
    }

    /** @return array{visits:int,visitors:int,paid_orders:int,conversion_rate:float,paid_revenue:float,net_revenue:float} */
    private function stats(int $channel_id, string $range): array
    {
        global $wpdb;

        [$visit_where, $conversion_where, $params] = $this->date_fragments($range);
        $visits_sql = 'SELECT COUNT(*) AS visits, COUNT(DISTINCT visitor_token) AS visitors FROM ' . Database::table('visits') . " WHERE channel_id = %d AND is_bot = 0 {$visit_where}";
        $conversions_sql = 'SELECT COUNT(*) AS paid_orders, COALESCE(SUM(order_total),0) AS revenue, COALESCE(SUM(refund_total),0) AS refunds FROM ' . Database::table('conversions') . " WHERE channel_id = %d AND status IN ('processing','completed') {$conversion_where}";
        $visit_args = array_merge([$channel_id], $params);
        $conversion_args = array_merge([$channel_id], $params);
        $visit_row = $wpdb->get_row($wpdb->prepare($visits_sql, ...$visit_args));
        $conversion_row = $wpdb->get_row($wpdb->prepare($conversions_sql, ...$conversion_args));
        $visits = (int) ($visit_row->visits ?? 0);
        $visitors = (int) ($visit_row->visitors ?? 0);
        $paid_orders = (int) ($conversion_row->paid_orders ?? 0);
        $revenue = (float) ($conversion_row->revenue ?? 0);
        $refunds = (float) ($conversion_row->refunds ?? 0);

        return [
            'visits' => $visits,
            'visitors' => $visitors,
            'paid_orders' => $paid_orders,
            'conversion_rate' => $visitors > 0 ? ($paid_orders / $visitors) * 100 : 0.0,
            'paid_revenue' => $revenue,
            'net_revenue' => max(0.0, $revenue - $refunds),
        ];
    }

    /** @return list<object> */
    private function links(int $channel_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, (SELECT COUNT(*) FROM " . Database::table('visits') . " v WHERE v.campaign_id = p.id AND v.is_bot = 0) AS human_visits, (SELECT COUNT(*) FROM " . Database::table('conversions') . " c WHERE c.campaign_id = p.id AND c.status IN ('processing','completed')) AS paid_orders FROM " . Database::table('campaigns') . ' p WHERE p.channel_id = %d ORDER BY p.created_at DESC',
                $channel_id
            )
        );

        return is_array($rows) ? $rows : [];
    }

    /** @return list<object> */
    private function orders(int $channel_id, string $range): array
    {
        global $wpdb;

        [, $where, $params] = $this->date_fragments($range);
        $args = array_merge([$channel_id], $params);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Database::table('conversions') . " WHERE channel_id = %d {$where} ORDER BY created_at DESC LIMIT 100",
                ...$args
            )
        );

        return is_array($rows) ? $rows : [];
    }

    /** @return array{string,string,list<string>} */
    private function date_fragments(string $range): array
    {
        if ('all' === $range) {
            return ['', '', []];
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - (absint($range) * DAY_IN_SECONDS));
        return [' AND clicked_at >= %s', ' AND created_at >= %s', [$cutoff]];
    }

    private function render_target_options(): void
    {
        echo '<option value="home:0">' . esc_html__('Homepage', 'trovesia-channel') . '</option>';
        echo '<option value="shop:0">' . esc_html__('Shop', 'trovesia-channel') . '</option>';

        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_array($categories) && $categories !== []) {
            echo '<optgroup label="' . esc_attr__('Product categories', 'trovesia-channel') . '">';
            foreach ($categories as $category) {
                if ($category instanceof \WP_Term) {
                    printf('<option value="product_category:%d">%s</option>', $category->term_id, esc_html($category->name));
                }
            }
            echo '</optgroup>';
        }

        $products = wc_get_products(['status' => 'publish', 'limit' => -1, 'orderby' => 'name', 'order' => 'ASC']);
        if ($products !== []) {
            echo '<optgroup label="' . esc_attr__('Products', 'trovesia-channel') . '">';
            foreach ($products as $product) {
                if ($product instanceof \WC_Product && $product->is_visible()) {
                    printf('<option value="product:%d">%s</option>', $product->get_id(), esc_html($product->get_name()));
                }
            }
            echo '</optgroup>';
        }
    }

    private function valid_target(string $type, int $id): bool
    {
        if (in_array($type, ['home', 'shop'], true)) {
            return $id === 0;
        }

        if ('product' === $type) {
            $product = wc_get_product($id);
            return $product instanceof \WC_Product && 'publish' === $product->get_status() && $product->is_visible();
        }

        if ('product_category' === $type) {
            $term = get_term($id, 'product_cat');
            return $term instanceof \WP_Term && ! is_wp_error($term);
        }

        return false;
    }

    private function unique_token(): string
    {
        do {
            $token = wp_generate_password(16, false, false);
        } while (Database::get_campaign_by_token($token));

        return $token;
    }

    private function target_label(object $campaign): string
    {
        switch ($campaign->target_type) {
            case 'home':
                return __('Homepage', 'trovesia-channel');
            case 'shop':
                return __('Shop', 'trovesia-channel');
            case 'product':
                $product = wc_get_product((int) $campaign->target_id);
                return $product ? $product->get_name() : __('Unavailable product', 'trovesia-channel');
            case 'product_category':
                $term = get_term((int) $campaign->target_id, 'product_cat');
                return $term instanceof \WP_Term ? $term->name : __('Unavailable category', 'trovesia-channel');
            default:
                return __('Unavailable destination', 'trovesia-channel');
        }
    }

    private function order_products(\WC_Order $order): string
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = sprintf('%s × %d', $item->get_name(), $item->get_quantity());
        }

        return implode(', ', $items);
    }

    private function metric(string $label, string $value): void
    {
        echo '<article class="trovesia-channel-card"><span>' . esc_html($label) . '</span><strong>' . wp_kses_post($value) . '</strong><i aria-hidden="true"></i></article>';
    }

    /** @return object */
    private function authorize_channel_user(): object
    {
        if (! is_user_logged_in() || ! current_user_can('manage_trovesia_channel_links')) {
            wp_die(
                esc_html__('You do not have permission to manage channel links.', 'trovesia-channel'),
                esc_html__('Forbidden', 'trovesia-channel'),
                ['response' => 403]
            );
        }

        $channel = Database::get_user_channel(get_current_user_id());
        if (! $channel) {
            wp_die(
                esc_html__('This account is not assigned to a channel.', 'trovesia-channel'),
                esc_html__('Forbidden', 'trovesia-channel'),
                ['response' => 403]
            );
        }

        return $channel;
    }

    private function portal_url(): string
    {
        $page_id = (int) get_option(Installer::PORTAL_PAGE_OPTION, 0);

        return $page_id > 0 ? (string) get_permalink($page_id) : home_url('/channel-portal/');
    }

    private function login_redirect(string $notice = ''): void
    {
        $url = $this->portal_url();
        if ($notice !== '') {
            $url = add_query_arg('trovesia_login_notice', sanitize_key($notice), $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    private function portal_redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg('trovesia_channel_notice', sanitize_key($notice), $this->portal_url()));
        exit;
    }

    private function render_login_notice(): void
    {
        $notice = isset($_GET['trovesia_login_notice']) ? sanitize_key(wp_unslash((string) $_GET['trovesia_login_notice'])) : '';
        $messages = [
            'failed' => __('The email, username, or password is incorrect. Please try again.', 'trovesia-channel'),
            'expired' => __('Your sign-in session expired. Please try again.', 'trovesia-channel'),
        ];
        if (! isset($messages[$notice])) {
            return;
        }

        printf('<div class="trovesia-channel-login__notice" role="alert">%s</div>', esc_html($messages[$notice]));
    }

    private function render_portal_notice(): void
    {
        $notice = isset($_GET['trovesia_channel_notice']) ? sanitize_key(wp_unslash((string) $_GET['trovesia_channel_notice'])) : '';
        $messages = [
            'link_created' => __('Tracking link created.', 'trovesia-channel'),
            'link_updated' => __('Tracking link status updated.', 'trovesia-channel'),
            'channel_paused' => __('This channel is paused.', 'trovesia-channel'),
            'invalid_target' => __('Choose a valid public destination.', 'trovesia-channel'),
            'link_failed' => __('The tracking link could not be created. Please try again.', 'trovesia-channel'),
        ];
        if (! isset($messages[$notice])) {
            return;
        }
        $error = in_array($notice, ['channel_paused', 'invalid_target', 'link_failed'], true);
        printf('<div class="%s" role="status">%s</div>', $error ? 'woocommerce-error' : 'woocommerce-message', esc_html($messages[$notice]));
    }

    private function is_portal_page(): bool
    {
        $page_id = (int) get_option(Installer::PORTAL_PAGE_OPTION, 0);

        return $page_id > 0 && is_page($page_id);
    }

    /** @param list<string> $classes
     *  @return list<string>
     */
    public function portal_body_class(array $classes): array
    {
        if ($this->is_portal_page()) {
            $classes[] = 'trovesia-channel-page';
        }

        return $classes;
    }
}
