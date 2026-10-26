<?php
/**
 * Guest-order Memory Page claim flow.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

use WC_Order;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class Claims
{
    private const ROUTE = 'memory-page-claim';
    private const TOKEN_TTL = 30 * DAY_IN_SECONDS;

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
        if ($this->booted || ! class_exists('WooCommerce')) {
            return;
        }
        $this->booted = true;

        add_action('init', [self::class, 'register_route']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'render_claim_page']);
        add_action('admin_post_tikool_claim_memory_pages', [$this, 'handle_claim']);
        add_action('admin_post_nopriv_tikool_claim_memory_pages', [$this, 'handle_claim']);
        add_action('admin_post_tikool_resend_memory_claim', [$this, 'handle_resend']);
        add_action('admin_post_nopriv_tikool_resend_memory_claim', [$this, 'handle_resend']);
        add_action('woocommerce_email_after_order_table', [$this, 'render_email_claim'], 20, 4);
        add_action('woocommerce_thankyou', [$this, 'render_thankyou'], 20);
        add_filter('woocommerce_order_actions', [$this, 'order_actions']);
        add_action('woocommerce_order_action_tikool_send_memory_claim', [$this, 'admin_send_claim']);
    }

    public static function register_route(): void
    {
        add_rewrite_rule('^' . self::ROUTE . '/?$', 'index.php?tikool_memory_claim=1', 'top');
    }

    /** @param string[] $vars */
    public function query_vars(array $vars): array
    {
        $vars[] = 'tikool_memory_claim';
        return $vars;
    }

    /**
     * Add the claim invitation to paid customer order emails.
     *
     * @param mixed $email WooCommerce email object.
     */
    public function render_email_claim(WC_Order $order, bool $sent_to_admin, bool $plain_text, $email): void
    {
        if ($sent_to_admin || ! $this->order_can_be_claimed($order)) {
            return;
        }

        $email_id = is_object($email) && isset($email->id) ? (string) $email->id : '';
        if (! in_array($email_id, ['customer_processing_order', 'customer_completed_order'], true)) {
            return;
        }

        // Some email callbacks can run before the normal status hook has provisioned pages.
        if ($this->order_page_ids($order->get_id()) === []) {
            Memory_Pages::instance()->provision_order($order->get_id());
        }
        if (! $this->order_has_unclaimed_pages($order->get_id())) {
            return;
        }

        $url = $this->claim_url_for_order($order);
        if ($url === '') {
            return;
        }

        if ($plain_text) {
            echo "\n" . esc_html__('CREATE YOUR MEMORY PAGE', 'tikool-memory-page') . "\n";
            echo esc_html__('Your NFC Memory Page is ready to claim. Use the secure link below to create or sign in to your account and start editing.', 'tikool-memory-page') . "\n";
            echo esc_url_raw($url) . "\n\n";
            return;
        }
        ?>
        <div style="margin:32px 0;padding:28px;border:1px solid #eadbd2;border-radius:16px;background:#fffaf4;text-align:center">
            <h2 style="margin:0 0 10px;color:#2c211e;font-size:24px;line-height:1.25"><?php esc_html_e('Create your Memory Page', 'tikool-memory-page'); ?></h2>
            <p style="margin:0 0 20px;color:#665955;line-height:1.65"><?php esc_html_e('Your NFC Memory Page is ready to claim. Create or sign in to your account to add photos, videos, audio and stories.', 'tikool-memory-page'); ?></p>
            <p style="margin:0 0 18px"><a href="<?php echo esc_url($url); ?>" style="display:inline-block;padding:14px 24px;border-radius:8px;background:#4b2e46;color:#ffffff;font-weight:700;text-decoration:none"><?php esc_html_e('Create Your Memory Page', 'tikool-memory-page'); ?></a></p>
            <p style="margin:0;color:#8a7b76;font-size:12px;line-height:1.5"><?php esc_html_e('If the button does not open, copy this secure link:', 'tikool-memory-page'); ?><br><a href="<?php echo esc_url($url); ?>" style="color:#4b2e46;word-break:break-all"><?php echo esc_html($url); ?></a></p>
        </div>
        <?php
    }

    public function render_thankyou(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || ! $this->order_can_be_claimed($order)) {
            return;
        }
        $order_key = isset($_GET['key']) ? wc_clean(wp_unslash((string) $_GET['key'])) : '';
        if (! is_user_logged_in() && ($order_key === '' || ! hash_equals($order->get_order_key(), $order_key))) {
            return;
        }
        if ($this->order_page_ids($order_id) === []) {
            Memory_Pages::instance()->provision_order($order_id);
        }
        if (! $this->order_has_unclaimed_pages($order_id)) {
            return;
        }

        $url = $this->claim_url_for_order($order);
        if ($url === '') {
            return;
        }
        ?>
        <section class="woocommerce-order-details tikool-memory-thankyou">
            <h2><?php esc_html_e('Your Memory Page is ready', 'tikool-memory-page'); ?></h2>
            <p><?php esc_html_e('We added a secure claim link to your order email. You can also start now using the button below.', 'tikool-memory-page'); ?></p>
            <p><a class="button" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Create Your Memory Page', 'tikool-memory-page'); ?></a></p>
        </section>
        <?php
    }

    /** @param array<string, string> $actions */
    public function order_actions(array $actions): array
    {
        $actions['tikool_send_memory_claim'] = __('Send Memory Page claim link', 'tikool-memory-page');
        return $actions;
    }

    public function admin_send_claim(WC_Order $order): void
    {
        if ($this->send_claim_email($order, true)) {
            $order->add_order_note(__('A new Memory Page claim link was emailed to the billing address.', 'tikool-memory-page'));
            return;
        }
        $order->add_order_note(__('The Memory Page claim email could not be sent. Confirm that this is a paid guest order with an unclaimed Memory Page and a valid billing email.', 'tikool-memory-page'));
    }

    public function render_claim_page(): void
    {
        if ((string) get_query_var('tikool_memory_claim') !== '1') {
            return;
        }

        nocache_headers();
        header('Referrer-Policy: no-referrer');
        $css = TIKOOL_MEMORY_PAGE_DIR . 'assets/css/claim.css';
        wp_enqueue_style('tikool-memory-page-claim', TIKOOL_MEMORY_PAGE_URL . 'assets/css/claim.css', [], is_file($css) ? (string) filemtime($css) : TIKOOL_MEMORY_PAGE_VERSION);

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash((string) $_GET['token'])) : '';
        $context = $this->validate_claim($order_id, $token);

        status_header(200);
        get_header();
        echo '<main class="tikool-memory-claim"><div class="tikool-memory-claim__card">';
        echo '<p class="tikool-memory-claim__eyebrow">' . esc_html__('Tikool Memory Page', 'tikool-memory-page') . '</p>';

        if ($context instanceof WP_Error) {
            $this->render_invalid_claim($order_id);
        } else {
            $this->render_valid_claim($context['order'], $context['page_ids'], $token);
        }

        echo '</div></main>';
        get_footer();
        exit;
    }

    public function handle_claim(): void
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
        check_admin_referer('tikool_claim_memory_pages_' . $order_id);

        $context = $this->validate_claim($order_id, $token);
        if ($context instanceof WP_Error) {
            $this->redirect_to_claim($order_id, $token, 'invalid');
        }

        /** @var WC_Order $order */
        $order = $context['order'];
        $email = strtolower(sanitize_email($order->get_billing_email()));
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if (! $user || strtolower((string) $user->user_email) !== $email) {
                $this->redirect_to_claim($order_id, $token, 'wrong-account');
            }
        } else {
            if (email_exists($email)) {
                $this->redirect_to_claim($order_id, $token, 'sign-in');
            }
            $password = isset($_POST['account_password']) ? (string) $_POST['account_password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if (strlen($password) < 8) {
                $this->redirect_to_claim($order_id, $token, 'password-short');
            }

            $user_id = wc_create_new_customer($email, '', $password, [
                'first_name' => sanitize_text_field($order->get_billing_first_name()),
                'last_name'  => sanitize_text_field($order->get_billing_last_name()),
                'source'     => 'tikool-memory-page-claim',
            ]);
            if ($user_id instanceof WP_Error) {
                $this->redirect_to_claim($order_id, $token, 'create-failed');
            }
            wc_set_customer_auth_cookie((int) $user_id);
            $user_id = (int) $user_id;
        }

        $claimed = $this->claim_pages($order, $context['page_ids'], $user_id);
        if ($claimed < 1) {
            $this->redirect_to_claim($order_id, $token, 'invalid');
        }

        $first_page_id = (int) $context['page_ids'][0];
        $slug = (string) get_post_meta($first_page_id, '_tikool_memory_directory', true);
        $destination = wc_get_endpoint_url('memory-pages', $slug, wc_get_page_permalink('myaccount'));
        wp_safe_redirect($destination);
        exit;
    }

    public function handle_resend(): void
    {
        check_admin_referer('tikool_resend_memory_claim');
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $email = isset($_POST['billing_email']) ? strtolower(sanitize_email(wp_unslash((string) $_POST['billing_email']))) : '';
        $order = wc_get_order($order_id);
        $rate_key = 'tikool_memory_claim_resend_' . md5($order_id . '|' . $email . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        if (! get_transient($rate_key)) {
            set_transient($rate_key, 1, 10 * MINUTE_IN_SECONDS);
            if ($order instanceof WC_Order
                && is_email($email)
                && hash_equals(strtolower(sanitize_email($order->get_billing_email())), $email)) {
                $this->send_claim_email($order, true);
            }
        }

        wp_safe_redirect(add_query_arg([
            'tikool_memory_claim' => '1',
            'order_id'            => $order_id,
            'claim_notice'        => 'sent',
        ], home_url('/')));
        exit;
    }

    /**
     * Return or create a reusable, expiring claim URL for an unclaimed guest order.
     */
    public function claim_url_for_order(WC_Order $order, bool $rotate = false): string
    {
        if (! $this->order_can_be_claimed($order) || ! $this->order_has_unclaimed_pages($order->get_id())) {
            return '';
        }

        $version = max(1, (int) $order->get_meta('_tikool_memory_claim_token_version', true));
        $expires = (int) $order->get_meta('_tikool_memory_claim_expires_at', true);
        $stored_hash = (string) $order->get_meta('_tikool_memory_claim_token_hash', true);
        $needs_issue = $rotate || $stored_hash === '' || $expires < time();
        if ($needs_issue) {
            $version++;
            $expires = time() + self::TOKEN_TTL;
        }

        $token = $this->token_for_order($order, $version);
        $token_hash = $this->token_hash($token);
        if ($needs_issue || ! hash_equals($stored_hash, $token_hash)) {
            $expires = time() + self::TOKEN_TTL;
            $order->update_meta_data('_tikool_memory_claim_token_version', $version);
            $order->update_meta_data('_tikool_memory_claim_token_hash', $token_hash);
            $order->update_meta_data('_tikool_memory_claim_expires_at', $expires);
            $order->save();
        }

        return add_query_arg([
            'order_id' => $order->get_id(),
            'token'    => $token,
        ], home_url('/' . self::ROUTE . '/'));
    }

    /**
     * Send a standalone replacement invitation, used by the resend form and order action.
     */
    public function send_claim_email(WC_Order $order, bool $rotate = false): bool
    {
        $email = sanitize_email($order->get_billing_email());
        $url = $this->claim_url_for_order($order, $rotate);
        if (! is_email($email) || $url === '') {
            return false;
        }

        $subject = sprintf(__('Your Tikool Memory Page for order #%s', 'tikool-memory-page'), $order->get_order_number());
        $heading = __('Your Memory Page is ready', 'tikool-memory-page');
        $body = '<p>' . esc_html__('Use the secure button below to create or sign in to your account and start editing the Memory Page connected to your NFC keepsake.', 'tikool-memory-page') . '</p>';
        $body .= '<p style="margin:24px 0"><a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 24px;border-radius:8px;background:#4b2e46;color:#fff;font-weight:700;text-decoration:none">' . esc_html__('Create Your Memory Page', 'tikool-memory-page') . '</a></p>';
        $body .= '<p style="font-size:12px;color:#777">' . esc_html__('If the button does not open, copy this link:', 'tikool-memory-page') . '<br><a href="' . esc_url($url) . '">' . esc_html($url) . '</a></p>';
        $message = function_exists('WC') && WC()->mailer() ? WC()->mailer()->wrap_message($heading, $body) : $body;
        return wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }

    /** @return array{order:WC_Order,page_ids:int[]}|WP_Error */
    private function validate_claim(int $order_id, string $token)
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || ! $this->order_can_be_claimed($order) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_Error('invalid_claim', __('This claim link is invalid.', 'tikool-memory-page'));
        }
        $stored_hash = (string) $order->get_meta('_tikool_memory_claim_token_hash', true);
        $expires = (int) $order->get_meta('_tikool_memory_claim_expires_at', true);
        if ($stored_hash === '' || $expires < time() || ! hash_equals($stored_hash, $this->token_hash($token))) {
            return new WP_Error('expired_claim', __('This claim link has expired.', 'tikool-memory-page'));
        }
        $page_ids = $this->unclaimed_page_ids($order_id);
        if ($page_ids === []) {
            return new WP_Error('claimed', __('These Memory Pages have already been claimed.', 'tikool-memory-page'));
        }
        return ['order' => $order, 'page_ids' => $page_ids];
    }

    /** @param int[] $page_ids */
    private function claim_pages(WC_Order $order, array $page_ids, int $user_id): int
    {
        $claimed = 0;
        foreach ($page_ids as $page_id) {
            $post = get_post($page_id);
            if (! $post || $post->post_type !== Memory_Pages::POST_TYPE || (int) $post->post_author !== 0) {
                continue;
            }
            $updated = wp_update_post(['ID' => $page_id, 'post_author' => $user_id], true);
            if ($updated instanceof WP_Error) {
                continue;
            }
            update_post_meta($page_id, '_tikool_memory_claim_status', 'claimed');
            update_post_meta($page_id, '_tikool_memory_claimed_at', gmdate('c'));
            update_post_meta($page_id, '_tikool_memory_claimed_by', $user_id);
            $claimed++;
        }
        if ($claimed < 1) {
            return 0;
        }

        if ((int) $order->get_customer_id() === 0) {
            $order->set_customer_id($user_id);
        }
        $order->delete_meta_data('_tikool_memory_claim_token_hash');
        $order->delete_meta_data('_tikool_memory_claim_expires_at');
        $order->update_meta_data('_tikool_memory_claimed_at', gmdate('c'));
        $order->update_meta_data('_tikool_memory_claimed_by', $user_id);
        $order->save();
        $order->add_order_note(sprintf(_n('%d Memory Page was claimed by the customer.', '%d Memory Pages were claimed by the customer.', $claimed, 'tikool-memory-page'), $claimed));
        return $claimed;
    }

    /** @param int[] $page_ids */
    private function render_valid_claim(WC_Order $order, array $page_ids, string $token): void
    {
        $email = sanitize_email($order->get_billing_email());
        $existing_user_id = email_exists($email);
        $current_user = wp_get_current_user();
        $matches = $current_user->exists() && strtolower((string) $current_user->user_email) === strtolower($email);
        $notice = isset($_GET['claim_notice']) ? sanitize_key((string) $_GET['claim_notice']) : '';
        $messages = [
            'wrong-account'  => __('This order belongs to a different email address. Sign out and use the account for the order email.', 'tikool-memory-page'),
            'sign-in'        => __('An account already uses this email address. Sign in below to claim the Memory Page.', 'tikool-memory-page'),
            'password-short' => __('Use a password with at least 8 characters.', 'tikool-memory-page'),
            'create-failed'  => __('The account could not be created. Please sign in if you already have an account, or contact us for help.', 'tikool-memory-page'),
            'invalid'        => __('The claim could not be completed. Request a new link and try again.', 'tikool-memory-page'),
        ];

        echo '<h1>' . esc_html__('Your Memory Page is ready', 'tikool-memory-page') . '</h1>';
        echo '<p class="tikool-memory-claim__lead">' . esc_html(sprintf(_n('Claim the Memory Page from order #%1$s and keep it connected to %2$s.', 'Claim all %3$d Memory Pages from order #%1$s and keep them connected to %2$s.', count($page_ids), 'tikool-memory-page'), $order->get_order_number(), $this->mask_email($email), count($page_ids))) . '</p>';
        if (isset($messages[$notice])) {
            echo '<div class="tikool-memory-claim__notice tikool-memory-claim__notice--error">' . esc_html($messages[$notice]) . '</div>';
        }

        if ($current_user->exists() && ! $matches) {
            echo '<p>' . esc_html(sprintf(__('You are currently signed in as %s.', 'tikool-memory-page'), $current_user->user_email)) . '</p>';
            echo '<p><a class="button" href="' . esc_url(wp_logout_url($this->claim_url($order->get_id(), $token))) . '">' . esc_html__('Sign out and continue', 'tikool-memory-page') . '</a></p>';
            return;
        }

        if ($matches) {
            echo '<p>' . esc_html__('Confirm below to add these Memory Pages to your account.', 'tikool-memory-page') . '</p>';
            $this->render_claim_form($order->get_id(), $token, false);
            return;
        }

        if ($existing_user_id) {
            echo '<h2>' . esc_html__('Sign in to claim', 'tikool-memory-page') . '</h2>';
            echo '<p>' . esc_html__('For security, sign in with the account that uses the order email address.', 'tikool-memory-page') . '</p>';
            wp_login_form([
                'redirect'       => $this->claim_url($order->get_id(), $token),
                'label_username' => __('Email address', 'tikool-memory-page'),
                'label_log_in'   => __('Sign in and continue', 'tikool-memory-page'),
                'remember'       => true,
            ]);
            return;
        }

        echo '<h2>' . esc_html__('Create your account', 'tikool-memory-page') . '</h2>';
        echo '<p>' . esc_html__('No account was needed at checkout. Set a password now so only you can edit this Memory Page in the future.', 'tikool-memory-page') . '</p>';
        $this->render_claim_form($order->get_id(), $token, true);
    }

    private function render_claim_form(int $order_id, string $token, bool $create_account): void
    {
        ?>
        <form class="tikool-memory-claim__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tikool_claim_memory_pages">
            <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order_id); ?>">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <?php wp_nonce_field('tikool_claim_memory_pages_' . $order_id); ?>
            <?php if ($create_account) : ?>
                <p><label for="tikool-memory-password"><?php esc_html_e('Create a password', 'tikool-memory-page'); ?></label><input id="tikool-memory-password" name="account_password" type="password" minlength="8" autocomplete="new-password" required><small><?php esc_html_e('At least 8 characters.', 'tikool-memory-page'); ?></small></p>
            <?php endif; ?>
            <button class="button alt" type="submit"><?php echo esc_html($create_account ? __('Create account & claim', 'tikool-memory-page') : __('Claim Memory Pages', 'tikool-memory-page')); ?></button>
        </form>
        <?php
    }

    private function render_invalid_claim(int $order_id): void
    {
        $sent = isset($_GET['claim_notice']) && sanitize_key((string) $_GET['claim_notice']) === 'sent';
        echo '<h1>' . esc_html__('Request a new claim link', 'tikool-memory-page') . '</h1>';
        echo '<p class="tikool-memory-claim__lead">' . esc_html__('This link is invalid, expired or has already been used. Enter the billing email from the order and we will send a new link when the order is eligible.', 'tikool-memory-page') . '</p>';
        if ($sent) {
            echo '<div class="tikool-memory-claim__notice">' . esc_html__('If the order details match, a fresh claim link is on its way. Please check your inbox and spam folder.', 'tikool-memory-page') . '</div>';
        }
        ?>
        <form class="tikool-memory-claim__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tikool_resend_memory_claim">
            <?php wp_nonce_field('tikool_resend_memory_claim'); ?>
            <p><label for="tikool-memory-order-id"><?php esc_html_e('Order number', 'tikool-memory-page'); ?></label><input id="tikool-memory-order-id" name="order_id" type="number" min="1" value="<?php echo esc_attr($order_id > 0 ? (string) $order_id : ''); ?>" required></p>
            <p><label for="tikool-memory-billing-email"><?php esc_html_e('Billing email', 'tikool-memory-page'); ?></label><input id="tikool-memory-billing-email" name="billing_email" type="email" autocomplete="email" required></p>
            <button class="button alt" type="submit"><?php esc_html_e('Email a new link', 'tikool-memory-page'); ?></button>
        </form>
        <?php
    }

    private function redirect_to_claim(int $order_id, string $token, string $notice): void
    {
        wp_safe_redirect(add_query_arg('claim_notice', sanitize_key($notice), $this->claim_url($order_id, $token)));
        exit;
    }

    private function claim_url(int $order_id, string $token): string
    {
        return add_query_arg(['order_id' => $order_id, 'token' => $token], home_url('/' . self::ROUTE . '/'));
    }

    private function token_for_order(WC_Order $order, int $version): string
    {
        return hash_hmac('sha256', $order->get_id() . '|' . $order->get_order_key() . '|' . $version, wp_salt('auth'));
    }

    private function token_hash(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('secure_auth'));
    }

    private function order_can_be_claimed(WC_Order $order): bool
    {
        return ! $order->has_status(['cancelled', 'refunded', 'failed'])
            && ($order->is_paid() || $order->has_status(['processing', 'completed']));
    }

    /** @return int[] */
    private function order_page_ids(int $order_id): array
    {
        return array_map('intval', get_posts([
            'post_type'      => Memory_Pages::POST_TYPE,
            'post_status'    => ['private', 'publish'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_tikool_memory_order_id',
            'meta_value'     => $order_id,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]));
    }

    /** @return int[] */
    private function unclaimed_page_ids(int $order_id): array
    {
        return array_values(array_filter($this->order_page_ids($order_id), static function (int $page_id): bool {
            $post = get_post($page_id);
            return $post && (int) $post->post_author === 0 && get_post_meta($page_id, '_tikool_memory_claim_status', true) !== 'claimed';
        }));
    }

    private function order_has_unclaimed_pages(int $order_id): bool
    {
        return $this->unclaimed_page_ids($order_id) !== [];
    }

    private function mask_email(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }
        $visible = substr($parts[0], 0, min(2, strlen($parts[0])));
        return $visible . str_repeat('•', max(3, strlen($parts[0]) - strlen($visible))) . '@' . $parts[1];
    }
}
