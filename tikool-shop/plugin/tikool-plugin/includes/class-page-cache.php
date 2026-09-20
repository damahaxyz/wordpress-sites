<?php
/**
 * Nginx FastCGI page-cache invalidation and administration.
 *
 * @package TikoolShopPlugin
 */

declare(strict_types=1);

namespace Tikool\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Page_Cache
{
    private const LAST_GENERATION_OPTION = 'tikool_page_cache_generation';
    private const LAST_PURGED_AT_OPTION = 'tikool_page_cache_last_purged_at';
    private const LAST_REASON_OPTION = 'tikool_page_cache_last_reason';

    private static ?self $instance = null;

    private bool $booted = false;

    private bool $purge_scheduled = false;

    /** @var array<string, bool> */
    private array $pending_reasons = [];

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
        self::instance()->purge_now('Tikool plugin activated');
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('save_post', [$this, 'handle_post_change'], 100, 3);
        add_action('before_delete_post', [$this, 'handle_post_delete']);
        add_action('trashed_post', [$this, 'handle_post_delete']);
        add_action('untrashed_post', [$this, 'handle_post_delete']);
        add_action('created_term', [$this, 'handle_term_change'], 100, 3);
        add_action('edited_term', [$this, 'handle_term_change'], 100, 3);
        add_action('delete_term', [$this, 'handle_term_change'], 100, 3);
        add_action('woocommerce_product_set_stock', [$this, 'handle_stock_change']);
        add_action('woocommerce_variation_set_stock', [$this, 'handle_stock_change']);
        add_action('woocommerce_update_product', [$this, 'handle_product_change']);
        add_action('woocommerce_update_product_variation', [$this, 'handle_product_change']);
        add_action('wp_update_nav_menu', [$this, 'handle_menu_change']);
        add_action('customize_save_after', [$this, 'handle_customizer_change']);
        add_action('switch_theme', [$this, 'handle_theme_change']);
        add_action('upgrader_process_complete', [$this, 'handle_software_update'], 100, 2);
        add_action('updated_option', [$this, 'handle_option_change'], 100, 3);

        add_action('admin_menu', [$this, 'add_tools_page']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_item'], 100);
        add_action('admin_post_tikool_purge_page_cache', [$this, 'handle_manual_purge']);
        add_action('admin_notices', [$this, 'render_admin_notice']);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('tikool page-cache', Page_Cache_Command::class);
        }
    }

    public function handle_post_change(int $post_id, \WP_Post $post, bool $update): void
    {
        unset($update);

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (! is_post_type_viewable($post->post_type)) {
            return;
        }

        $this->schedule_purge(sprintf('Updated %s #%d', $post->post_type, $post_id));
    }

    public function handle_post_delete(int $post_id): void
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || ! is_post_type_viewable($post->post_type)) {
            return;
        }

        $this->schedule_purge(sprintf('Deleted or restored %s #%d', $post->post_type, $post_id));
    }

    public function handle_term_change(int $term_id, int $term_taxonomy_id, string $taxonomy): void
    {
        unset($term_taxonomy_id);

        if (! taxonomy_exists($taxonomy) || ! is_taxonomy_viewable($taxonomy)) {
            return;
        }

        $this->schedule_purge(sprintf('Updated %s term #%d', $taxonomy, $term_id));
    }

    /**
     * @param mixed $product WooCommerce product instance.
     */
    public function handle_stock_change($product): void
    {
        $product_id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        $this->schedule_purge($product_id > 0 ? sprintf('Updated stock for product #%d', $product_id) : 'Updated product stock');
    }

    public function handle_product_change(int $product_id): void
    {
        $this->schedule_purge(sprintf('Updated product #%d', $product_id));
    }

    public function handle_menu_change(int $menu_id): void
    {
        $this->schedule_purge(sprintf('Updated navigation menu #%d', $menu_id));
    }

    /**
     * @param mixed $manager WordPress customize manager.
     */
    public function handle_customizer_change($manager): void
    {
        unset($manager);
        $this->schedule_purge('Updated theme customizer settings');
    }

    public function handle_theme_change(): void
    {
        $this->schedule_purge('Switched theme');
    }

    /**
     * @param mixed               $upgrader Upgrade manager.
     * @param array<string,mixed> $hook_extra Upgrade context.
     */
    public function handle_software_update($upgrader, array $hook_extra): void
    {
        unset($upgrader);
        $type = isset($hook_extra['type']) ? sanitize_key((string) $hook_extra['type']) : 'software';
        $this->schedule_purge(sprintf('Updated %s', $type));
    }

    /**
     * @param mixed $old_value Previous option value.
     * @param mixed $value     New option value.
     */
    public function handle_option_change(string $option, $old_value, $value): void
    {
        unset($old_value, $value);

        $tracked_options = [
            'page_on_front',
            'page_for_posts',
            'show_on_front',
            'woocommerce_shop_page_id',
            'woocommerce_cart_page_id',
            'woocommerce_checkout_page_id',
            'woocommerce_myaccount_page_id',
        ];

        if (in_array($option, $tracked_options, true) || str_starts_with($option, 'theme_mods_') || str_starts_with($option, 'widget_')) {
            $this->schedule_purge(sprintf('Updated site option %s', $option));
        }
    }

    public function schedule_purge(string $reason): void
    {
        $reason = sanitize_text_field($reason);
        if ($reason !== '') {
            $this->pending_reasons[$reason] = true;
        }

        if ($this->purge_scheduled) {
            return;
        }

        $this->purge_scheduled = true;
        add_action('shutdown', [$this, 'flush_scheduled_purge'], PHP_INT_MAX);
    }

    public function flush_scheduled_purge(): void
    {
        if (! $this->purge_scheduled) {
            return;
        }

        $this->purge_scheduled = false;
        $reasons = array_keys($this->pending_reasons);
        $this->pending_reasons = [];
        $this->purge_now($reasons !== [] ? implode('; ', $reasons) : 'Automatic invalidation');
    }

    /**
     * Move all future requests to a new cache generation.
     *
     * @return true|\WP_Error
     */
    public function purge_now(string $reason)
    {
        $path = $this->generation_file();
        $directory = dirname($path);

        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            return new \WP_Error('tikool_page_cache_directory', sprintf('Unable to create page-cache runtime directory: %s', $directory));
        }

        if (! is_writable($directory)) {
            return new \WP_Error('tikool_page_cache_permissions', sprintf('Page-cache runtime directory is not writable: %s', $directory));
        }

        $generation = gmdate('YmdHis') . '-' . str_replace('-', '', wp_generate_uuid4());
        $contents = sprintf("set \$tikool_cache_generation \"%s\";\n", $generation);
        $temporary = $path . '.' . wp_generate_password(12, false, false) . '.tmp';
        $written = file_put_contents($temporary, $contents, LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        if ($written !== strlen($contents)) {
            @unlink($temporary); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            return new \WP_Error('tikool_page_cache_write', sprintf('Unable to write page-cache generation file: %s', $path));
        }

        @chmod($temporary, 0666); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
        if (! @rename($temporary, $path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
            @unlink($temporary); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            return new \WP_Error('tikool_page_cache_replace', sprintf('Unable to replace page-cache generation file: %s', $path));
        }

        $reason = sanitize_text_field($reason);
        update_option(self::LAST_GENERATION_OPTION, $generation, false);
        update_option(self::LAST_PURGED_AT_OPTION, time(), false);
        update_option(self::LAST_REASON_OPTION, $reason, false);

        do_action('tikool_page_cache_purged', $generation, $reason);

        return true;
    }

    public function add_tools_page(): void
    {
        add_management_page(
            __('Tikool Page Cache', 'tikool-plugin'),
            __('Tikool Page Cache', 'tikool-plugin'),
            'manage_options',
            'tikool-page-cache',
            [$this, 'render_tools_page']
        );
    }

    public function add_admin_bar_item(\WP_Admin_Bar $admin_bar): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $admin_bar->add_node([
            'id'    => 'tikool-purge-page-cache',
            'title' => __('Clear page cache', 'tikool-plugin'),
            'href'  => wp_nonce_url(admin_url('admin-post.php?action=tikool_purge_page_cache'), 'tikool_purge_page_cache'),
            'meta'  => ['title' => __('Start a new Nginx page-cache generation', 'tikool-plugin')],
        ]);
    }

    public function handle_manual_purge(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to clear the page cache.', 'tikool-plugin'),
                esc_html__('Page cache', 'tikool-plugin'),
                ['response' => 403]
            );
        }

        check_admin_referer('tikool_purge_page_cache');
        $result = $this->purge_now(sprintf('Manual purge by user #%d', get_current_user_id()));
        $status = is_wp_error($result) ? 'error' : 'success';

        wp_safe_redirect(add_query_arg('tikool_page_cache_notice', $status, admin_url('tools.php?page=tikool-page-cache')));
        exit;
    }

    public function render_admin_notice(): void
    {
        if (! current_user_can('manage_options') || ! isset($_GET['tikool_page_cache_notice'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $status = sanitize_key(wp_unslash((string) $_GET['tikool_page_cache_notice'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! in_array($status, ['success', 'error'], true)) {
            return;
        }

        $class = $status === 'success' ? 'notice notice-success is-dismissible' : 'notice notice-error';
        $message = $status === 'success'
            ? __('Tikool page cache was cleared.', 'tikool-plugin')
            : __('Tikool could not update the page-cache generation file. Check the runtime directory permissions.', 'tikool-plugin');

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }

    public function render_tools_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $path = $this->generation_file();
        $last_purged = (int) get_option(self::LAST_PURGED_AT_OPTION, 0);
        $last_reason = (string) get_option(self::LAST_REASON_OPTION, '');
        $generation = (string) get_option(self::LAST_GENERATION_OPTION, '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tikool Page Cache', 'tikool-plugin'); ?></h1>
            <p><?php esc_html_e('Nginx caches public HTML for anonymous visitors. Logged-in users, carts, checkout, accounts, requests with query strings, and WooCommerce sessions bypass the cache.', 'tikool-plugin'); ?></p>
            <table class="widefat striped" style="max-width: 900px">
                <tbody>
                    <tr><th><?php esc_html_e('Generation file', 'tikool-plugin'); ?></th><td><code><?php echo esc_html($path); ?></code></td></tr>
                    <tr><th><?php esc_html_e('Writable', 'tikool-plugin'); ?></th><td><?php echo esc_html(is_writable(dirname($path)) ? __('Yes', 'tikool-plugin') : __('No', 'tikool-plugin')); ?></td></tr>
                    <tr><th><?php esc_html_e('Current generation', 'tikool-plugin'); ?></th><td><code><?php echo esc_html($generation !== '' ? $generation : 'bootstrap'); ?></code></td></tr>
                    <tr><th><?php esc_html_e('Last cleared', 'tikool-plugin'); ?></th><td><?php echo esc_html($last_purged > 0 ? wp_date('Y-m-d H:i:s', $last_purged) : __('Not recorded', 'tikool-plugin')); ?></td></tr>
                    <tr><th><?php esc_html_e('Reason', 'tikool-plugin'); ?></th><td><?php echo esc_html($last_reason !== '' ? $last_reason : __('Not recorded', 'tikool-plugin')); ?></td></tr>
                </tbody>
            </table>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tikool_purge_page_cache'), 'tikool_purge_page_cache')); ?>">
                    <?php esc_html_e('Clear page cache', 'tikool-plugin'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function generation_file(): string
    {
        $configured = getenv('TIKOOL_PAGE_CACHE_GENERATION_FILE');

        return is_string($configured) && $configured !== ''
            ? $configured
            : '/var/run/tikool-page-cache/generation.conf';
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function status(): array
    {
        $path = $this->generation_file();

        return [
            'generation_file' => $path,
            'file_exists'     => is_file($path),
            'directory_writable' => is_writable(dirname($path)),
            'generation'      => (string) get_option(self::LAST_GENERATION_OPTION, 'bootstrap'),
            'last_purged_at'  => (int) get_option(self::LAST_PURGED_AT_OPTION, 0),
            'last_reason'     => (string) get_option(self::LAST_REASON_OPTION, ''),
        ];
    }
}

final class Page_Cache_Command
{
    /**
     * Clear the Nginx FastCGI page cache by starting a new generation.
     *
     * ## EXAMPLES
     *
     *     wp tikool page-cache purge
     */
    public function purge(): void
    {
        $result = Page_Cache::instance()->purge_now('WP-CLI manual purge');
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }

        \WP_CLI::success('Tikool page cache cleared.');
    }

    /**
     * Show the current page-cache generation and last invalidation.
     *
     * ## EXAMPLES
     *
     *     wp tikool page-cache status
     */
    public function status(): void
    {
        $status = Page_Cache::instance()->status();
        $status['last_purged_at'] = (int) $status['last_purged_at'] > 0
            ? wp_date('Y-m-d H:i:s', (int) $status['last_purged_at'])
            : 'Not recorded';

        \WP_CLI\Utils\format_items('table', [$status], array_keys($status));
    }
}
