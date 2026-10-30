<?php
/**
 * Store-owner administration.
 *
 * @package TrovesiaChannel
 */

declare(strict_types=1);

namespace Trovesia\Channel;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin
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

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_trovesia_channel_save_channel', [$this, 'save_channel']);
        add_action('admin_post_trovesia_channel_save_settings', [$this, 'save_settings']);
        add_action('admin_post_trovesia_channel_assign_manager', [$this, 'assign_manager']);
        add_action('add_meta_boxes', [$this, 'register_order_meta_box']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_order_channel'], 20, 2);
        add_action('save_post_shop_order', [$this, 'save_legacy_order_channel'], 20, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_order_column']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_order_column'], 20, 2);
        add_filter('manage_edit-shop_order_columns', [$this, 'add_order_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy_order_column'], 20, 2);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'render_order_filter'], 20, 2);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'filter_hpos_orders']);
        add_action('restrict_manage_posts', [$this, 'render_legacy_order_filter'], 20, 2);
        add_action('pre_get_posts', [$this, 'filter_legacy_orders']);
        add_action('admin_init', [$this, 'restrict_channel_wp_admin']);
        add_filter('show_admin_bar', [$this, 'hide_channel_admin_bar']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Trovesia Channels', 'trovesia-channel'),
            __('Channels', 'trovesia-channel'),
            'manage_woocommerce',
            'trovesia-channels',
            [$this, 'render_channels_page'],
            'dashicons-chart-line',
            56
        );

        add_submenu_page(
            'trovesia-channels',
            __('Channel Settings', 'trovesia-channel'),
            __('Settings', 'trovesia-channel'),
            'manage_woocommerce',
            'trovesia-channel-settings',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ('toplevel_page_trovesia-channels' !== $hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'trovesia-channel-admin',
            TROVESIA_CHANNEL_URL . 'assets/admin.css',
            [],
            TROVESIA_CHANNEL_VERSION
        );
        wp_enqueue_script(
            'trovesia-channel-admin',
            TROVESIA_CHANNEL_URL . 'assets/admin.js',
            [],
            TROVESIA_CHANNEL_VERSION,
            true
        );
        wp_localize_script(
            'trovesia-channel-admin',
            'trovesiaChannelAdmin',
            [
                'addTitle' => __('Add channel', 'trovesia-channel'),
                'editTitle' => __('Edit channel', 'trovesia-channel'),
                'createLabel' => __('Create channel', 'trovesia-channel'),
                'updateLabel' => __('Update channel', 'trovesia-channel'),
            ]
        );
    }

    public function render_channels_page(): void
    {
        $this->authorize();

        $selected_channel_id = isset($_GET['channel_id']) ? absint($_GET['channel_id']) : 0;
        $channels = Database::get_channels();
        $notice = isset($_GET['trovesia_notice']) ? sanitize_key(wp_unslash((string) $_GET['trovesia_notice'])) : '';
        $auto_open_modal = in_array($notice, ['invalid_channel', 'invalid_commission_rate', 'duplicate_code', 'invalid_manager', 'manager_password_required', 'manager_password_invalid'], true);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Trovesia Channels', 'trovesia-channel'); ?></h1>
            <button type="button" class="page-title-action" id="trovesia-add-channel"><?php esc_html_e('Add channel', 'trovesia-channel'); ?></button>
            <hr class="wp-header-end">
            <?php $this->render_notice(); ?>

            <h2><?php esc_html_e('Channels', 'trovesia-channel'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Name', 'trovesia-channel'); ?></th><th><?php esc_html_e('Code', 'trovesia-channel'); ?></th><th><?php esc_html_e('Status', 'trovesia-channel'); ?></th><th><?php esc_html_e('Manager', 'trovesia-channel'); ?></th><th><?php esc_html_e('Links', 'trovesia-channel'); ?></th><th><?php esc_html_e('Visits', 'trovesia-channel'); ?></th><th><?php esc_html_e('Paid orders', 'trovesia-channel'); ?></th><th><?php esc_html_e('Net revenue', 'trovesia-channel'); ?></th><th></th></tr></thead>
                <tbody>
                <?php if ($channels === []) : ?>
                    <tr><td colspan="9"><?php esc_html_e('No channels yet.', 'trovesia-channel'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($channels as $channel) : ?>
                        <?php $summary = $this->channel_summary((int) $channel->id); ?>
                        <?php $manager = $this->channel_manager((int) $channel->id); ?>
                        <tr>
                            <td><strong><?php echo esc_html($channel->name); ?></strong></td>
                            <td><code><?php echo esc_html($channel->code); ?></code></td>
                            <td><?php echo esc_html(ucfirst($channel->status)); ?></td>
                            <td><?php echo esc_html($summary['manager']); ?></td>
                            <td><a href="<?php echo esc_url(add_query_arg(['page' => 'trovesia-channels', 'channel_id' => (int) $channel->id], admin_url('admin.php'))); ?>"><?php echo esc_html((string) $summary['links']); ?></a></td>
                            <td><?php echo esc_html((string) $summary['visits']); ?></td>
                            <td><?php echo esc_html((string) $summary['paid_orders']); ?></td>
                            <td><?php echo wp_kses_post(wc_price($summary['net_revenue'])); ?></td>
                            <td><button
                                type="button"
                                class="button-link trovesia-edit-channel"
                                data-channel-id="<?php echo esc_attr((string) $channel->id); ?>"
                                data-name="<?php echo esc_attr((string) $channel->name); ?>"
                                data-code="<?php echo esc_attr((string) $channel->code); ?>"
                                data-status="<?php echo esc_attr((string) $channel->status); ?>"
                                data-commission-rate="<?php echo esc_attr((string) ($channel->commission_rate ?? '0.2')); ?>"
                                data-manager-email="<?php echo esc_attr($manager?->user_email ?? ''); ?>"
                                data-manager-username="<?php echo esc_attr($manager?->user_login ?? ''); ?>"
                            ><?php esc_html_e('Edit', 'trovesia-channel'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($selected_channel_id > 0 && Database::get_channel($selected_channel_id)) : ?>
                <?php $this->render_admin_links($selected_channel_id); ?>
            <?php endif; ?>

            <?php $this->render_channel_modal($auto_open_modal, $selected_channel_id); ?>
        </div>
        <?php
    }

    public function render_settings_page(): void
    {
        $this->authorize();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Channel Settings', 'trovesia-channel'); ?></h1>
            <?php $this->render_notice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="trovesia_channel_save_settings">
                <?php wp_nonce_field('trovesia_channel_save_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="trovesia-channel-window"><?php esc_html_e('Attribution window', 'trovesia-channel'); ?></label></th>
                        <td><input id="trovesia-channel-window" type="number" min="1" max="365" name="window_days" value="<?php echo esc_attr((string) Installer::attribution_window_days()); ?>"> <?php esc_html_e('days', 'trovesia-channel'); ?><p class="description"><?php esc_html_e('Only store administrators can view or change this value. Channel managers never see it in their portal.', 'trovesia-channel'); ?></p></td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'trovesia-channel')); ?>
            </form>
        </div>
        <?php
    }

    public function save_channel(): void
    {
        global $wpdb;

        $this->authorize();
        check_admin_referer('trovesia_channel_save_channel');

        $channel_id = isset($_POST['channel_id']) ? absint($_POST['channel_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
        $code = isset($_POST['code']) ? sanitize_title(wp_unslash((string) $_POST['code'])) : '';
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash((string) $_POST['status'])) : 'active';
        $status = in_array($status, ['active', 'paused', 'archived'], true) ? $status : 'paused';
        $existing_channel = $channel_id > 0 ? Database::get_channel($channel_id) : null;
        $commission_rate = $this->parse_commission_rate($_POST['commission_rate'] ?? (string) ($existing_channel->commission_rate ?? '0.2'));
        if ($commission_rate === null) {
            $this->redirect_with_notice('invalid_commission_rate', $channel_id);
        }
        $manager_email = isset($_POST['manager_email']) ? sanitize_email(wp_unslash((string) $_POST['manager_email'])) : '';
        $manager_username = isset($_POST['manager_username']) ? sanitize_user(wp_unslash((string) $_POST['manager_username']), true) : '';
        $manager_password = isset($_POST['manager_password']) ? (string) wp_unslash($_POST['manager_password']) : '';

        if ($name === '' || $code === '') {
            $this->redirect_with_notice('invalid_channel', $channel_id);
        }
        if ($manager_email !== '') {
            if (! is_email($manager_email)) {
                $this->redirect_with_notice('invalid_manager', $channel_id);
            }
            $manager_user = get_user_by('email', $manager_email);
            $password_error = $this->manager_password_error($manager_password, ! $manager_user instanceof \WP_User);
            if ($password_error) {
                $this->redirect_with_notice($password_error->get_error_code(), $channel_id);
            }
            if (! $manager_user instanceof \WP_User) {
                $candidate_username = $manager_username !== '' ? $manager_username : sanitize_user(strstr($manager_email, '@', true) ?: '', true);
                if ($candidate_username === '' || username_exists($candidate_username)) {
                    $this->redirect_with_notice('invalid_manager', $channel_id);
                }
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($channel_id > 0 && Database::get_channel($channel_id)) {
            $result = $wpdb->update(
                Database::table('channels'),
                ['name' => $name, 'code' => $code, 'status' => $status, 'commission_rate' => $commission_rate, 'updated_at' => $now],
                ['id' => $channel_id],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            $action = 'channel_updated';
        } else {
            $result = $wpdb->insert(
                Database::table('channels'),
                ['uuid' => wp_generate_uuid4(), 'name' => $name, 'code' => $code, 'status' => $status, 'commission_rate' => $commission_rate, 'created_at' => $now, 'updated_at' => $now],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $channel_id = (int) $wpdb->insert_id;
            $action = 'channel_created';
        }

        if ($result === false) {
            $this->redirect_with_notice('duplicate_code', $channel_id);
        }

        Database::audit($action, 'channel', $channel_id, ['name' => $name, 'code' => $code, 'status' => $status, 'commission_rate' => $commission_rate]);

        if ($manager_email !== '') {
            $manager_result = $this->upsert_manager($channel_id, $manager_email, $manager_username, $manager_password);
            if (is_wp_error($manager_result)) {
                $this->redirect_with_notice(in_array($manager_result->get_error_code(), ['manager_password_required', 'manager_password_invalid'], true) ? $manager_result->get_error_code() : 'invalid_manager', $channel_id);
            }

            $this->redirect_with_notice($manager_result);
        }

        $this->redirect_with_notice('saved');
    }

    private function parse_commission_rate($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/D', trim(wp_unslash($value)))) {
            return null;
        }
        return number_format((float) trim(wp_unslash($value)), 4, '.', '');
    }

    public function save_settings(): void
    {
        $this->authorize();
        check_admin_referer('trovesia_channel_save_settings');

        $days = isset($_POST['window_days']) ? absint($_POST['window_days']) : 30;
        update_option(Installer::WINDOW_OPTION, max(1, min(365, $days)), false);
        Database::audit('settings_updated', 'settings', 0, ['window_days' => max(1, min(365, $days))]);

        wp_safe_redirect(add_query_arg(['page' => 'trovesia-channel-settings', 'trovesia_notice' => 'saved'], admin_url('admin.php')));
        exit;
    }

    public function assign_manager(): void
    {
        $this->authorize();
        check_admin_referer('trovesia_channel_assign_manager');

        $channel_id = isset($_POST['channel_id']) ? absint($_POST['channel_id']) : 0;
        $email = isset($_POST['manager_email']) ? sanitize_email(wp_unslash((string) $_POST['manager_email'])) : '';
        $username = isset($_POST['manager_username']) ? sanitize_user(wp_unslash((string) $_POST['manager_username']), true) : '';
        $initial_password = isset($_POST['manager_password']) ? (string) wp_unslash($_POST['manager_password']) : '';
        $result = $this->upsert_manager($channel_id, $email, $username, $initial_password);

        $this->redirect_with_notice(is_wp_error($result) ? (in_array($result->get_error_code(), ['manager_password_required', 'manager_password_invalid'], true) ? $result->get_error_code() : 'invalid_manager') : $result, $channel_id);
    }

    public function register_order_meta_box(): void
    {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique($screens) as $screen) {
            add_meta_box('trovesia-channel-order', __('Trovesia channel', 'trovesia-channel'), [$this, 'render_order_meta_box'], $screen, 'side', 'default');
        }
    }

    /** @param mixed $post_or_order */
    public function render_order_meta_box($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order((int) $post_or_order->ID);
        if (! $order) {
            return;
        }

        $current = (int) $order->get_meta(Order_Attribution::CHANNEL_META, true);
        wp_nonce_field('trovesia_channel_order_assignment', 'trovesia_channel_order_nonce');
        echo '<p><label for="trovesia-order-channel">' . esc_html__('Attributed channel', 'trovesia-channel') . '</label></p>';
        echo '<select id="trovesia-order-channel" name="trovesia_order_channel_id" style="width:100%"><option value="0">' . esc_html__('No channel', 'trovesia-channel') . '</option>';
        foreach (Database::get_channels() as $channel) {
            printf('<option value="%d" %s>%s</option>', (int) $channel->id, selected($current, (int) $channel->id, false), esc_html($channel->name));
        }
        echo '</select><p class="description">' . esc_html__('Manual changes are recorded in the audit log.', 'trovesia-channel') . '</p>';
    }

    /** @param mixed $order */
    public function save_order_channel(int $order_id, $order): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        $this->process_order_channel_save($order);
    }

    public function save_legacy_order_channel(int $post_id, \WP_Post $post): void
    {
        unset($post);
        $this->process_order_channel_save(wc_get_order($post_id));
    }

    /** @param array<string,string> $columns */
    public function add_order_column(array $columns): array
    {
        $output = [];
        foreach ($columns as $key => $label) {
            $output[$key] = $label;
            if ('order_status' === $key) {
                $output['trovesia_channel'] = __('Channel', 'trovesia-channel');
            }
        }

        return $output;
    }

    /** @param mixed $order */
    public function render_order_column(string $column, $order): void
    {
        if ('trovesia_channel' !== $column || ! $order instanceof \WC_Order) {
            return;
        }

        echo esc_html($this->order_channel_name($order));
    }

    public function render_legacy_order_column(string $column, int $post_id): void
    {
        if ('trovesia_channel' !== $column) {
            return;
        }

        $order = wc_get_order($post_id);
        echo esc_html($order ? $this->order_channel_name($order) : '—');
    }

    public function render_order_filter(string $order_type = 'shop_order', string $which = 'top'): void
    {
        if ('shop_order' !== $order_type || 'top' !== $which) {
            return;
        }

        $this->render_channel_filter_select();
    }

    /** @param array<string,mixed> $query_args
     *  @return array<string,mixed>
     */
    public function filter_hpos_orders(array $query_args): array
    {
        $channel_id = isset($_GET['trovesia_channel_filter']) ? absint($_GET['trovesia_channel_filter']) : 0;
        if ($channel_id <= 0) {
            return $query_args;
        }

        $query_args['meta_query'] = isset($query_args['meta_query']) && is_array($query_args['meta_query']) ? $query_args['meta_query'] : [];
        $query_args['meta_query'][] = ['key' => Order_Attribution::CHANNEL_META, 'value' => $channel_id, 'compare' => '='];

        return $query_args;
    }

    public function render_legacy_order_filter(string $post_type = '', string $which = 'top'): void
    {
        if ('shop_order' === $post_type && 'top' === $which) {
            $this->render_channel_filter_select();
        }
    }

    public function filter_legacy_orders(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get('post_type')) {
            return;
        }

        $channel_id = isset($_GET['trovesia_channel_filter']) ? absint($_GET['trovesia_channel_filter']) : 0;
        if ($channel_id > 0) {
            $query->set('meta_key', Order_Attribution::CHANNEL_META);
            $query->set('meta_value', (string) $channel_id);
        }
    }

    public function restrict_channel_wp_admin(): void
    {
        if (! is_admin() || wp_doing_ajax() || ! is_user_logged_in() || current_user_can('manage_woocommerce')) {
            return;
        }

        if (! current_user_can('view_trovesia_channel_portal')) {
            return;
        }

        global $pagenow;
        if (in_array($pagenow, ['admin-post.php', 'profile.php', 'user-edit.php'], true)) {
            return;
        }

        $page_id = (int) get_option(Installer::PORTAL_PAGE_OPTION, 0);
        wp_safe_redirect($page_id > 0 ? get_permalink($page_id) : home_url('/channel-portal/'));
        exit;
    }

    /** @param bool $show */
    public function hide_channel_admin_bar(bool $show): bool
    {
        if (current_user_can('view_trovesia_channel_portal') && ! current_user_can('manage_woocommerce')) {
            return false;
        }

        return $show;
    }

    private function process_order_channel_save(?\WC_Order $order): void
    {
        global $wpdb;

        if (! $order || ! current_user_can('manage_woocommerce') || ! isset($_POST['trovesia_channel_order_nonce'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) $_POST['trovesia_channel_order_nonce']));
        if (! wp_verify_nonce($nonce, 'trovesia_channel_order_assignment')) {
            return;
        }

        $new_channel_id = isset($_POST['trovesia_order_channel_id']) ? absint($_POST['trovesia_order_channel_id']) : 0;
        $old_channel_id = (int) $order->get_meta(Order_Attribution::CHANNEL_META, true);
        if ($new_channel_id === $old_channel_id) {
            return;
        }

        if ($new_channel_id === 0) {
            foreach ([Order_Attribution::CHANNEL_META, Order_Attribution::CHANNEL_CODE_META, Order_Attribution::CAMPAIGN_META, Order_Attribution::VISIT_META, Order_Attribution::FIRST_CHANNEL_META, Order_Attribution::ATTRIBUTED_AT_META, Order_Attribution::MODEL_META] as $key) {
                $order->delete_meta_data($key);
            }
            $order->save_meta_data();
            $wpdb->delete(Database::table('conversions'), ['order_id' => $order->get_id()], ['%d']);
        } else {
            $channel = Database::get_channel($new_channel_id);
            if (! $channel) {
                return;
            }
            $order->update_meta_data(Order_Attribution::CHANNEL_META, $new_channel_id);
            $order->update_meta_data(Order_Attribution::CHANNEL_CODE_META, (string) $channel->code);
            $order->update_meta_data(Order_Attribution::CAMPAIGN_META, 0);
            $order->update_meta_data(Order_Attribution::VISIT_META, 0);
            $order->update_meta_data(Order_Attribution::FIRST_CHANNEL_META, $new_channel_id);
            $order->update_meta_data(Order_Attribution::ATTRIBUTED_AT_META, gmdate('Y-m-d H:i:s'));
            $order->update_meta_data(Order_Attribution::MODEL_META, 'manual');
            $order->save_meta_data();
            Order_Attribution::instance()->sync_conversion($order->get_id());
        }

        Database::audit('order_channel_changed', 'order', $order->get_id(), ['from' => $old_channel_id, 'to' => $new_channel_id]);
    }

    private function order_channel_name(\WC_Order $order): string
    {
        $channel = Database::get_channel((int) $order->get_meta(Order_Attribution::CHANNEL_META, true));

        return $channel ? (string) $channel->name : '—';
    }

    private function render_channel_filter_select(): void
    {
        $selected = isset($_GET['trovesia_channel_filter']) ? absint($_GET['trovesia_channel_filter']) : 0;
        echo '<select name="trovesia_channel_filter"><option value="0">' . esc_html__('All channels', 'trovesia-channel') . '</option>';
        foreach (Database::get_channels() as $channel) {
            printf('<option value="%d" %s>%s</option>', (int) $channel->id, selected($selected, (int) $channel->id, false), esc_html($channel->name));
        }
        echo '</select>';
    }

    private function render_channel_modal(bool $auto_open, int $selected_channel_id = 0): void
    {
        $selected_channel = $selected_channel_id > 0 ? Database::get_channel($selected_channel_id) : null;
        $manager = $selected_channel ? $this->channel_manager((int) $selected_channel->id) : null;
        ?>
        <div
            class="trovesia-channel-modal"
            id="trovesia-channel-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="trovesia-channel-modal-title"
            <?php echo $auto_open ? '' : 'hidden'; ?>
        >
            <div class="trovesia-channel-modal__backdrop" data-trovesia-modal-close></div>
            <div class="trovesia-channel-modal__dialog" role="document">
                <div class="trovesia-channel-modal__header">
                    <h2 id="trovesia-channel-modal-title"><?php echo $selected_channel ? esc_html__('Edit channel', 'trovesia-channel') : esc_html__('Add channel', 'trovesia-channel'); ?></h2>
                    <button type="button" class="trovesia-channel-modal__close" data-trovesia-modal-close aria-label="<?php esc_attr_e('Close', 'trovesia-channel'); ?>">&times;</button>
                </div>
                <form id="trovesia-channel-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="trovesia_channel_save_channel">
                    <input type="hidden" id="trovesia-channel-id" name="channel_id" value="<?php echo esc_attr((string) ($selected_channel->id ?? 0)); ?>">
                    <?php wp_nonce_field('trovesia_channel_save_channel'); ?>
                    <div class="trovesia-channel-modal__body">
                        <h3><?php esc_html_e('Channel details', 'trovesia-channel'); ?></h3>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="trovesia-channel-name"><?php esc_html_e('Name', 'trovesia-channel'); ?></label></th>
                                <td><input class="regular-text" id="trovesia-channel-name" name="name" required value="<?php echo esc_attr($selected_channel->name ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="trovesia-channel-code"><?php esc_html_e('Code', 'trovesia-channel'); ?></label></th>
                                <td><input class="regular-text" id="trovesia-channel-code" name="code" required pattern="[a-z0-9-]+" value="<?php echo esc_attr($selected_channel->code ?? ''); ?>"><p class="description"><?php esc_html_e('Lowercase internal identifier; it is not exposed in public links.', 'trovesia-channel'); ?></p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="trovesia-channel-status"><?php esc_html_e('Status', 'trovesia-channel'); ?></label></th>
                                <td><select id="trovesia-channel-status" name="status"><option value="active" <?php selected($selected_channel->status ?? 'active', 'active'); ?>><?php esc_html_e('Active', 'trovesia-channel'); ?></option><option value="paused" <?php selected($selected_channel->status ?? '', 'paused'); ?>><?php esc_html_e('Paused', 'trovesia-channel'); ?></option><option value="archived" <?php selected($selected_channel->status ?? '', 'archived'); ?>><?php esc_html_e('Archived', 'trovesia-channel'); ?></option></select></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="trovesia-channel-commission-rate"><?php esc_html_e('Commission rate', 'trovesia-channel'); ?></label></th>
                                <td><input type="number" id="trovesia-channel-commission-rate" name="commission_rate" min="0" max="1" step="0.0001" required value="<?php echo esc_attr((string) ($selected_channel->commission_rate ?? '0.2')); ?>"><p class="description"><?php esc_html_e('Enter a ratio from 0 to 1. For example, 0.2 means 20%.', 'trovesia-channel'); ?></p></td>
                            </tr>
                        </table>

                        <hr>
                        <h3><?php esc_html_e('Channel manager', 'trovesia-channel'); ?></h3>
                        <p class="description"><?php esc_html_e('Manager details are optional. Leave the email empty to save only the channel.', 'trovesia-channel'); ?></p>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><label for="manager-email"><?php esc_html_e('Login email', 'trovesia-channel'); ?></label></th><td><input class="regular-text" type="email" id="manager-email" name="manager_email" autocomplete="off" value="<?php echo esc_attr($manager?->user_email ?? ''); ?>"><p class="description"><?php esc_html_e('The channel manager can use this email address to sign in. An existing user with the same email will be assigned without changing that user’s password.', 'trovesia-channel'); ?></p></td></tr>
                            <tr><th scope="row"><label for="manager-username"><?php esc_html_e('Username', 'trovesia-channel'); ?></label></th><td><input class="regular-text" id="manager-username" name="manager_username" autocomplete="off" value="<?php echo esc_attr($manager?->user_login ?? ''); ?>"><p class="description"><?php esc_html_e('For a new account, leave this empty to derive a username from the email. An existing username cannot be changed here.', 'trovesia-channel'); ?></p></td></tr>
                            <tr><th scope="row"><label for="manager-password"><?php esc_html_e('Initial / new password', 'trovesia-channel'); ?></label></th><td><input class="regular-text" type="password" id="manager-password" name="manager_password" autocomplete="new-password" minlength="6" maxlength="4096" aria-describedby="manager-password-help"><p class="description" id="manager-password-help"><?php esc_html_e('For a new account, enter an initial password of at least 6 characters and share it with the manager. No password setup email is sent. For an existing account, leave this empty to keep its password or enter a new one to reset it.', 'trovesia-channel'); ?></p></td></tr>
                        </table>
                    </div>
                    <div class="trovesia-channel-modal__footer">
                        <button type="button" class="button" data-trovesia-modal-close><?php esc_html_e('Cancel', 'trovesia-channel'); ?></button>
                        <button type="submit" class="button button-primary" id="trovesia-channel-submit"><?php echo $selected_channel ? esc_html__('Update channel', 'trovesia-channel') : esc_html__('Create channel', 'trovesia-channel'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /** @return string|\WP_Error */
    private function upsert_manager(int $channel_id, string $email, string $username, string $initial_password)
    {
        global $wpdb;

        if (! Database::get_channel($channel_id) || ! is_email($email)) {
            return new \WP_Error('invalid_manager');
        }

        $user = get_user_by('email', $email);
        $password_error = $this->manager_password_error($initial_password, ! $user instanceof \WP_User);
        if ($password_error) {
            return $password_error;
        }
        $created = false;
        if (! $user instanceof \WP_User) {
            if ($username === '') {
                $username = sanitize_user(strstr($email, '@', true) ?: '', true);
            }
            if ($username === '' || username_exists($username)) {
                return new \WP_Error('invalid_manager');
            }

            $user_id = wp_create_user($username, $initial_password, $email);
            if (is_wp_error($user_id)) {
                return $user_id;
            }
            $user = get_user_by('id', (int) $user_id);
            $created = true;
        }

        if (! $user instanceof \WP_User) {
            return new \WP_Error('invalid_manager');
        }

        $password_updated = false;
        if (! $created && $initial_password !== '') {
            wp_set_password($initial_password, $user->ID);
            clean_user_cache($user->ID);
            $password_updated = true;
        }

        if (! user_can($user, 'manage_woocommerce')) {
            $user->add_role('trovesia_channel_manager');
        }

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . Database::table('users') . ' WHERE channel_id = %d OR user_id = %d',
                $channel_id,
                $user->ID
            )
        );
        $assigned = $wpdb->insert(
            Database::table('users'),
            ['user_id' => $user->ID, 'channel_id' => $channel_id, 'created_at' => gmdate('Y-m-d H:i:s')],
            ['%d', '%d', '%s']
        );
        if (false === $assigned) {
            return new \WP_Error('invalid_manager');
        }

        Database::audit('manager_assigned', 'channel', $channel_id, ['user_id' => $user->ID, 'password_updated' => $password_updated]);

        return $created ? 'manager_created' : ($password_updated ? 'manager_password_updated' : 'manager_assigned');
    }

    private function manager_password_error(string $password, bool $creating): ?\WP_Error
    {
        if ($creating && $password === '') {
            return new \WP_Error('manager_password_required');
        }
        if ($password !== '' && (mb_strlen(trim($password)) < 6 || strlen($password) > 4096)) {
            return new \WP_Error('manager_password_invalid');
        }

        return null;
    }

    private function channel_manager(int $channel_id): ?\WP_User
    {
        global $wpdb;

        $user_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT user_id FROM ' . Database::table('users') . ' WHERE channel_id = %d ORDER BY created_at ASC LIMIT 1',
                $channel_id
            )
        );
        if ($user_id <= 0) {
            return null;
        }

        $user = get_user_by('id', $user_id);

        return $user instanceof \WP_User ? $user : null;
    }

    /** @return array{manager:string,links:int,visits:int,paid_orders:int,net_revenue:float} */
    private function channel_summary(int $channel_id): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT u.user_id, (SELECT COUNT(*) FROM ' . Database::table('campaigns') . ' p WHERE p.channel_id = %d) AS link_count FROM ' . Database::table('users') . ' u WHERE u.channel_id = %d LIMIT 1',
                $channel_id,
                $channel_id
            )
        );
        $user = $row ? get_user_by('id', (int) $row->user_id) : false;
        $links = $row ? (int) $row->link_count : (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Database::table('campaigns') . ' WHERE channel_id = %d', $channel_id));

        $visits = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Database::table('visits') . ' WHERE channel_id = %d AND is_bot = 0', $channel_id));
        $sales = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS paid_orders, COALESCE(SUM(order_total - refund_total), 0) AS net_revenue FROM " . Database::table('conversions') . " WHERE channel_id = %d AND status IN ('processing','completed')",
                $channel_id
            )
        );

        return [
            'manager' => $user instanceof \WP_User ? $user->user_email : '—',
            'links' => $links,
            'visits' => $visits,
            'paid_orders' => (int) ($sales->paid_orders ?? 0),
            'net_revenue' => max(0.0, (float) ($sales->net_revenue ?? 0)),
        ];
    }

    private function render_admin_links(int $channel_id): void
    {
        global $wpdb;

        $campaigns = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Database::table('campaigns') . ' WHERE channel_id = %d ORDER BY created_at DESC LIMIT 100',
                $channel_id
            )
        );
        ?>
        <h2><?php esc_html_e('Channel links', 'trovesia-channel'); ?></h2>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Campaign', 'trovesia-channel'); ?></th><th><?php esc_html_e('Destination', 'trovesia-channel'); ?></th><th><?php esc_html_e('Tracking link', 'trovesia-channel'); ?></th><th><?php esc_html_e('Raw clicks', 'trovesia-channel'); ?></th><th><?php esc_html_e('Status', 'trovesia-channel'); ?></th></tr></thead>
            <tbody>
            <?php if (! is_array($campaigns) || $campaigns === []) : ?><tr><td colspan="5"><?php esc_html_e('No links have been created for this channel.', 'trovesia-channel'); ?></td></tr><?php endif; ?>
            <?php foreach ((array) $campaigns as $campaign) : ?>
                <tr><td><?php echo esc_html($campaign->name !== '' ? $campaign->name : __('Untitled campaign', 'trovesia-channel')); ?></td><td><?php echo esc_html($campaign->target_type . ((int) $campaign->target_id > 0 ? ' #' . $campaign->target_id : '')); ?></td><td><code><?php echo esc_html(Attribution::link_url((string) $campaign->token)); ?></code></td><td><?php echo esc_html((string) $campaign->clicks); ?></td><td><?php echo esc_html(ucfirst($campaign->status)); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function authorize(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('You do not have permission to manage channels.', 'trovesia-channel'),
                esc_html__('Forbidden', 'trovesia-channel'),
                ['response' => 403]
            );
        }
    }

    private function redirect_with_notice(string $notice, int $channel_id = 0): void
    {
        $args = ['page' => 'trovesia-channels', 'trovesia_notice' => sanitize_key($notice)];
        if ($channel_id > 0) {
            $args['channel_id'] = $channel_id;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function render_notice(): void
    {
        $notice = isset($_GET['trovesia_notice']) ? sanitize_key(wp_unslash((string) $_GET['trovesia_notice'])) : '';
        $messages = [
            'saved' => __('Saved.', 'trovesia-channel'),
            'manager_created' => __('The manager account was created with the initial password you entered. Share the login details with the manager; no password setup email was sent.', 'trovesia-channel'),
            'manager_assigned' => __('The existing account was assigned.', 'trovesia-channel'),
            'manager_password_updated' => __('The existing account was assigned and its password was reset.', 'trovesia-channel'),
            'invalid_channel' => __('Enter a valid channel name and code.', 'trovesia-channel'),
            'invalid_commission_rate' => __('Enter a commission rate between 0 and 1, with up to four decimal places.', 'trovesia-channel'),
            'duplicate_code' => __('That channel code is already in use.', 'trovesia-channel'),
            'invalid_manager' => __('Enter a valid manager email and an available username when creating an account.', 'trovesia-channel'),
            'manager_password_required' => __('Enter an initial password for the new manager account. No password setup email will be sent.', 'trovesia-channel'),
            'manager_password_invalid' => __('Use a password of at least 6 characters and no more than 4096 bytes.', 'trovesia-channel'),
        ];
        if (! isset($messages[$notice])) {
            return;
        }

        $error = in_array($notice, ['invalid_channel', 'invalid_commission_rate', 'duplicate_code', 'invalid_manager', 'manager_password_required', 'manager_password_invalid'], true);
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', $error ? 'notice-error' : 'notice-success', esc_html($messages[$notice]));
    }
}
