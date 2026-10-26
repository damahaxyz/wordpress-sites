<?php
/**
 * WooCommerce product and order lifecycle integration.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

use WC_Order;
use WC_Order_Item_Product;

if (! defined('ABSPATH')) {
    exit;
}

final class Memory_Pages
{
    public const POST_TYPE = 'tikool_memory_page';
    public const PRODUCT_META = '_tikool_memory_page_enabled';

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

        add_action('init', [self::class, 'register_post_type']);
        add_action('init', [$this, 'maybe_upgrade'], 20);
        add_action('save_post_' . self::POST_TYPE, [$this, 'initialize_manual_page'], 10, 3);
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'add_page_details_metabox']);
        add_filter('post_row_actions', [$this, 'admin_row_actions'], 10, 2);
        if (! class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_product_options_general_product_data', [$this, 'product_option']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_option']);
        add_filter('woocommerce_checkout_registration_enabled', [$this, 'checkout_registration_enabled']);
        add_filter('woocommerce_checkout_registration_required', [$this, 'checkout_registration_required']);
        add_action('woocommerce_review_order_before_submit', [$this, 'checkout_guest_message']);
        add_action('woocommerce_payment_complete', [$this, 'provision_order']);
        add_action('woocommerce_order_status_processing', [$this, 'provision_order']);
        add_action('woocommerce_order_status_completed', [$this, 'provision_order']);
        add_filter('woocommerce_order_actions', [$this, 'order_actions']);
        add_action('woocommerce_order_action_tikool_provision_memory_pages', [$this, 'manual_provision']);
        add_action('woocommerce_after_order_itemmeta', [$this, 'render_order_item_pages'], 20, 3);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
    }

    public static function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'          => __('Memory Pages', 'tikool-memory-page'),
                'singular_name' => __('Memory Page', 'tikool-memory-page'),
                'edit_item'     => __('Edit Memory Page', 'tikool-memory-page'),
                'search_items'  => __('Search Memory Pages', 'tikool-memory-page'),
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'woocommerce',
            'show_in_rest'        => false,
            'supports'            => ['title', 'author'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
        ]);
    }

    public function product_option(): void
    {
        woocommerce_wp_checkbox([
            'id'          => self::PRODUCT_META,
            'label'       => __('Memory Page / NFC product', 'tikool-memory-page'),
            'description' => __('Create one editable Memory Page per purchased quantity after payment.', 'tikool-memory-page'),
            'desc_tip'    => true,
        ]);
    }

    public function save_product_option(int $product_id): void
    {
        update_post_meta($product_id, self::PRODUCT_META, isset($_POST[self::PRODUCT_META]) ? 'yes' : 'no');
    }

    public function checkout_registration_enabled(bool $enabled): bool
    {
        return $enabled || $this->cart_contains_memory_page();
    }

    public function checkout_registration_required(bool $required): bool
    {
        return $this->cart_contains_memory_page() ? false : $required;
    }

    public function checkout_guest_message(): void
    {
        if (is_user_logged_in() || ! $this->cart_contains_memory_page()) {
            return;
        }
        echo '<div class="woocommerce-info tikool-memory-checkout-note">'
            . esc_html__('No account is required at checkout. After payment, your order email will include a secure link to claim and manage your Memory Page.', 'tikool-memory-page')
            . '</div>';
    }

    /**
     * Provision pages idempotently for all qualifying items.
     */
    public function provision_order(int $order_id): int
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return 0;
        }

        $customer_id = (int) $order->get_customer_id();
        $created = 0;
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (! $item instanceof WC_Order_Item_Product || ! $this->item_is_memory_page($item)) {
                continue;
            }

            $page_ids = $this->page_ids_for_item($order_id, (int) $item_id);
            $quantity = max(1, (int) $item->get_quantity());
            for ($unit = count($page_ids) + 1; $unit <= $quantity; $unit++) {
                $page_id = $this->create_page($order, $item, (int) $item_id, $unit, $customer_id);
                if ($page_id > 0) {
                    $page_ids[] = $page_id;
                    $created++;
                }
            }

            if ($page_ids !== []) {
                $item->update_meta_data('_tikool_memory_page_ids', array_values(array_unique(array_map('intval', $page_ids))));
                $item->save();
            }
        }

        return $created;
    }

    /**
     * @param array<string, string> $actions Order actions.
     * @return array<string, string>
     */
    public function order_actions(array $actions): array
    {
        $actions['tikool_provision_memory_pages'] = __('Create missing Memory Pages', 'tikool-memory-page');
        return $actions;
    }

    public function manual_provision(WC_Order $order): void
    {
        $created = $this->provision_order($order->get_id());
        if ($created > 0) {
            $order->add_order_note(sprintf(_n('%d Memory Page was created manually.', '%d Memory Pages were created manually.', $created, 'tikool-memory-page'), $created));
        } else {
            $order->add_order_note(__('No Memory Page was created. Confirm that an order product has “Memory Page / NFC product” enabled; pages already linked to this order item are not duplicated.', 'tikool-memory-page'));
        }
    }

    public function render_order_item_pages(int $item_id, WC_Order_Item_Product $item, $product): void
    {
        if (! is_admin() || ! current_user_can('manage_woocommerce')) {
            return;
        }

        $page_ids = $this->page_ids_for_item((int) $item->get_order_id(), $item_id);
        foreach ($page_ids as $page_id) {
            $url = self::public_url($page_id);
            $edit_url = get_edit_post_link($page_id);
            echo '<div class="tikool-memory-order-link"><strong>' . esc_html__('Memory Page:', 'tikool-memory-page') . '</strong> ';
            if ($url !== '') {
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a> ';
            }
            if ($edit_url) {
                echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('record', 'tikool-memory-page') . '</a>';
            }
            echo '</div>';
        }
    }

    /**
     * @param array<string, string> $columns List-table columns.
     * @return array<string, string>
     */
    public function admin_columns(array $columns): array
    {
        $columns['tikool_memory_status'] = __('Status', 'tikool-memory-page');
        $columns['tikool_memory_owner'] = __('Ownership', 'tikool-memory-page');
        $columns['tikool_memory_order'] = __('Order', 'tikool-memory-page');
        $columns['tikool_memory_nfc'] = __('NFC URL', 'tikool-memory-page');
        return $columns;
    }

    public function render_admin_column(string $column, int $page_id): void
    {
        if ($column === 'tikool_memory_status') {
            echo esc_html($this->publish_status_label((string) get_post_meta($page_id, '_tikool_memory_publish_status', true)));
        } elseif ($column === 'tikool_memory_owner') {
            $claim_status = (string) get_post_meta($page_id, '_tikool_memory_claim_status', true);
            echo esc_html($claim_status === 'claimed' ? __('Claimed', 'tikool-memory-page') : __('Waiting for customer', 'tikool-memory-page'));
        } elseif ($column === 'tikool_memory_order') {
            $order_id = (int) get_post_meta($page_id, '_tikool_memory_order_id', true);
            $order = $order_id > 0 ? wc_get_order($order_id) : false;
            $url = $order ? $order->get_edit_order_url() : '';
            echo $url !== '' ? '<a href="' . esc_url($url) . '">#' . esc_html((string) $order_id) . '</a>' : '&mdash;';
        } elseif ($column === 'tikool_memory_nfc') {
            $url = self::public_url($page_id);
            echo $url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a>' : '&mdash;';
        }
    }

    /**
     * Add a direct customer-content editor action to the admin list.
     *
     * @param array<string, string> $actions Row actions.
     * @return array<string, string>
     */
    public function admin_row_actions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== self::POST_TYPE || ! current_user_can('manage_woocommerce')) {
            return $actions;
        }

        $url = $this->content_editor_url($post->ID);
        if ($url !== '') {
            $actions['tikool_edit_memory_content'] = '<a href="' . esc_url($url) . '">' . esc_html__('Edit content', 'tikool-memory-page') . '</a>';
        }

        return $actions;
    }

    public function add_page_details_metabox(): void
    {
        add_meta_box(
            'tikool-memory-page-details',
            __('Memory Page details', 'tikool-memory-page'),
            [$this, 'render_page_details_metabox'],
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    public function render_page_details_metabox(\WP_Post $post): void
    {
        if ($post->post_status !== 'auto-draft') {
            $this->ensure_page_initialized($post->ID);
        }
        $url = self::public_url($post->ID);
        $editor_url = $this->content_editor_url($post->ID);
        $status = (string) get_post_meta($post->ID, '_tikool_memory_publish_status', true);
        $claim_status = (string) get_post_meta($post->ID, '_tikool_memory_claim_status', true);
        $bucket_git_url = (string) get_post_meta($post->ID, '_tikool_memory_bucket_git_url', true);
        ?>
        <p><strong><?php esc_html_e('Content status', 'tikool-memory-page'); ?></strong><br><?php echo esc_html($this->publish_status_label($status)); ?></p>
        <p><strong><?php esc_html_e('Ownership', 'tikool-memory-page'); ?></strong><br><?php echo esc_html($claim_status === 'claimed' ? __('Claimed', 'tikool-memory-page') : __('Waiting for customer', 'tikool-memory-page')); ?></p>
        <p><strong><?php esc_html_e('Bucket repository', 'tikool-memory-page'); ?></strong><br><code style="display:block;overflow-wrap:anywhere"><?php echo esc_html($bucket_git_url); ?></code></p>
        <p><strong><?php esc_html_e('Permanent NFC URL', 'tikool-memory-page'); ?></strong><br>
            <?php if ($url !== '') : ?><code style="display:block;overflow-wrap:anywhere;margin-top:4px"><?php echo esc_html($url); ?></code><?php else : ?><?php esc_html_e('Public URL is not configured.', 'tikool-memory-page'); ?><?php endif; ?>
        </p>
        <?php if ($editor_url !== '') : ?>
            <p><a class="button button-primary" href="<?php echo esc_url($editor_url); ?>"><?php esc_html_e('Edit photos, media & timeline', 'tikool-memory-page'); ?></a></p>
        <?php endif; ?>
        <?php
    }

    public function initialize_manual_page(int $post_id, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return;
        }

        $this->ensure_page_initialized($post_id);
    }

    public function maybe_upgrade(): void
    {
        if (version_compare((string) get_option('tikool_memory_page_version', '0'), TIKOOL_MEMORY_PAGE_VERSION, '>=')) {
            return;
        }

        $page_ids = get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        foreach ($page_ids as $page_id) {
            $this->ensure_page_initialized((int) $page_id);
            $public_url = self::public_url((int) $page_id);
            if ($public_url !== '') {
                update_post_meta((int) $page_id, '_tikool_memory_public_url', $public_url);
            }
        }
        flush_rewrite_rules(false);
        update_option('tikool_memory_page_version', TIKOOL_MEMORY_PAGE_VERSION);
    }

    public static function public_url(int $page_id): string
    {
        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $base = Settings::get('public_url');
        if ($base === '' || ! self::is_valid_slug($slug)) {
            return '';
        }

        // Cloudflare Workers Static Assets serves <slug>.html at the canonical
        // extensionless URL and redirects explicit .html requests there.
        return untrailingslashit($base) . '/' . rawurlencode($slug);
    }

    /**
     * @return int[]
     */
    public function user_pages(int $user_id): array
    {
        return get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => ['private', 'publish'],
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
    }

    public function user_owns_page(int $page_id, int $user_id): bool
    {
        $post = get_post($page_id);
        return $post !== null
            && $post->post_type === self::POST_TYPE
            && ((int) $post->post_author === $user_id || user_can($user_id, 'manage_woocommerce'));
    }

    public function page_by_slug_for_user(string $slug, int $user_id): int
    {
        if (! self::is_valid_slug($slug)) {
            return 0;
        }

        $args = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => ['private', 'publish'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_tikool_memory_directory',
            'meta_value'     => $slug,
        ];
        if (! user_can($user_id, 'manage_woocommerce')) {
            $args['author'] = $user_id;
        }
        $ids = get_posts($args);

        return isset($ids[0]) ? (int) $ids[0] : 0;
    }

    private function item_is_memory_page(WC_Order_Item_Product $item): bool
    {
        $product = $item->get_product();
        if (! $product) {
            return false;
        }
        if ($product->get_meta(self::PRODUCT_META, true) === 'yes') {
            return true;
        }

        return $product->get_parent_id() > 0
            && get_post_meta($product->get_parent_id(), self::PRODUCT_META, true) === 'yes';
    }

    private function cart_contains_memory_page(): bool
    {
        if (is_user_logged_in() || ! function_exists('WC') || ! WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = (int) ($cart_item['product_id'] ?? 0);
            $variation_id = (int) ($cart_item['variation_id'] ?? 0);
            if (($variation_id > 0 && get_post_meta($variation_id, self::PRODUCT_META, true) === 'yes')
                || ($product_id > 0 && get_post_meta($product_id, self::PRODUCT_META, true) === 'yes')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int[]
     */
    private function page_ids_for_item(int $order_id, int $item_id): array
    {
        return array_map('intval', get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => ['private', 'publish'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tikool_memory_order_id', 'value' => $order_id, 'compare' => '='],
                ['key' => '_tikool_memory_order_item_id', 'value' => $item_id, 'compare' => '='],
            ],
        ]));
    }

    private function create_page(WC_Order $order, WC_Order_Item_Product $item, int $item_id, int $unit, int $customer_id): int
    {
        $slug = $this->unique_slug();
        $bucket_git_url = Settings::get('bucket_git_url');
        if (! Settings::is_valid_bucket_git_url($bucket_git_url)) {
            $order->add_order_note(__('A Memory Page could not be created because the bucket repository is invalid.', 'tikool-memory-page'));
            return 0;
        }

        $title = sprintf(
            /* translators: 1: product name, 2: order number, 3: item unit */
            __('%1$s — Order %2$s #%3$d', 'tikool-memory-page'),
            $item->get_name(),
            $order->get_order_number(),
            $unit
        );
        $page_id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'private',
            'post_title'  => $title,
            'post_author' => $customer_id,
        ], true);
        if (is_wp_error($page_id)) {
            $order->add_order_note(sprintf(__('A Memory Page record could not be created: %s', 'tikool-memory-page'), $page_id->get_error_message()));
            return 0;
        }

        update_post_meta($page_id, '_tikool_memory_directory', $slug);
        update_post_meta($page_id, '_tikool_memory_bucket_git_url', $bucket_git_url);
        update_post_meta($page_id, '_tikool_memory_order_id', $order->get_id());
        update_post_meta($page_id, '_tikool_memory_order_item_id', $item_id);
        update_post_meta($page_id, '_tikool_memory_unit', $unit);
        update_post_meta($page_id, '_tikool_memory_customer_email', sanitize_email($order->get_billing_email()));
        update_post_meta($page_id, '_tikool_memory_claim_status', $customer_id > 0 ? 'claimed' : 'unclaimed');
        if ($customer_id > 0) {
            update_post_meta($page_id, '_tikool_memory_claimed_at', gmdate('c'));
            update_post_meta($page_id, '_tikool_memory_claimed_by', $customer_id);
        }
        update_post_meta($page_id, '_tikool_memory_publish_status', 'draft');
        update_post_meta($page_id, '_tikool_memory_scene', 'cozy-home');

        $url = self::public_url((int) $page_id);
        if ($url !== '') {
            update_post_meta($page_id, '_tikool_memory_public_url', $url);
            $order->add_order_note(sprintf(__('Memory Page created for NFC programming: %s', 'tikool-memory-page'), $url));
        }

        return (int) $page_id;
    }

    private function ensure_page_initialized(int $page_id): void
    {
        $post = get_post($page_id);
        if (! $post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        if (! self::is_valid_slug($slug)) {
            $slug = $this->unique_slug();
            update_post_meta($page_id, '_tikool_memory_directory', $slug);
        }

        $bucket_git_url = (string) get_post_meta($page_id, '_tikool_memory_bucket_git_url', true);
        if (! Settings::is_valid_bucket_git_url($bucket_git_url)) {
            $bucket_git_url = Settings::get('bucket_git_url');
            update_post_meta($page_id, '_tikool_memory_bucket_git_url', $bucket_git_url);
        }
        delete_post_meta($page_id, '_tikool_memory_bucket');
        if (! Settings::is_valid_bucket_git_url($bucket_git_url)) {
            update_post_meta($page_id, '_tikool_memory_publish_status', 'failed');
            update_post_meta($page_id, '_tikool_memory_last_error', __('The Memory Page bucket repository is invalid.', 'tikool-memory-page'));
            return;
        }

        if (get_post_meta($page_id, '_tikool_memory_publish_status', true) === '') {
            update_post_meta($page_id, '_tikool_memory_publish_status', 'draft');
        }
        if (get_post_meta($page_id, '_tikool_memory_scene', true) === '') {
            update_post_meta($page_id, '_tikool_memory_scene', 'cozy-home');
        }
        if (get_post_meta($page_id, '_tikool_memory_claim_status', true) === '') {
            $author_id = (int) get_post_field('post_author', $page_id);
            update_post_meta($page_id, '_tikool_memory_claim_status', $author_id > 0 ? 'claimed' : 'unclaimed');
            if ($author_id > 0) {
                update_post_meta($page_id, '_tikool_memory_claimed_by', $author_id);
            }
        }
        $url = self::public_url($page_id);
        if ($url !== '') {
            update_post_meta($page_id, '_tikool_memory_public_url', $url);
        }
    }

    private function content_editor_url(int $page_id): string
    {
        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        if (! self::is_valid_slug($slug) || ! function_exists('wc_get_endpoint_url')) {
            return '';
        }

        return wc_get_endpoint_url('memory-pages', $slug, wc_get_page_permalink('myaccount'));
    }

    private function publish_status_label(string $status): string
    {
        return match ($status) {
            'published' => __('Published to GitHub', 'tikool-memory-page'),
            'queued' => __('Publishing queued', 'tikool-memory-page'),
            'running' => __('Publishing in progress', 'tikool-memory-page'),
            'failed' => __('Publishing failed', 'tikool-memory-page'),
            default => __('Not published', 'tikool-memory-page'),
        };
    }

    private function unique_slug(): string
    {
        do {
            $slug = '';
            $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            $last_index = strlen($alphabet) - 1;
            for ($index = 0; $index < 16; $index++) {
                $slug .= $alphabet[random_int(0, $last_index)];
            }
            $exists = get_posts([
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_tikool_memory_directory',
                'meta_value'     => $slug,
            ]);
        } while ($exists !== [] || is_file(Storage::page_html_path($slug)));

        return $slug;
    }

    public static function is_valid_slug(string $slug): bool
    {
        return (bool) preg_match('/^(?:[A-Za-z0-9]{16}|[a-f0-9]{32})$/', $slug);
    }
}
