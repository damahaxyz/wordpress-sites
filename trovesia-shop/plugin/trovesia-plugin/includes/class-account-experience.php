<?php
/**
 * Modal account access for the Trovesia storefront.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

namespace Trovesia\Plugin;

use WP_Error;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

final class Account_Experience
{
    private const NONCE_ACTION = 'trovesia_account_modal';

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

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        // Footer scripts are printed at priority 20. Render the dialog first so
        // account-modal.js can find it when the script initializes.
        add_action('wp_footer', [$this, 'render_modal'], 5);
        add_action('wp_ajax_nopriv_trovesia_account_login', [$this, 'handle_login']);
        add_action('wp_ajax_nopriv_trovesia_account_register', [$this, 'handle_registration']);
    }

    public function enqueue_assets(): void
    {
        if (! class_exists('WooCommerce') || is_user_logged_in()) {
            return;
        }

        $css = TROVESIA_PLUGIN_DIR . 'assets/css/account-modal.css';
        $js = TROVESIA_PLUGIN_DIR . 'assets/js/account-modal.js';

        wp_enqueue_style(
            'trovesia-account-modal',
            TROVESIA_PLUGIN_URL . 'assets/css/account-modal.css',
            [],
            is_file($css) ? (string) filemtime($css) : TROVESIA_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'trovesia-account-modal',
            TROVESIA_PLUGIN_URL . 'assets/js/account-modal.js',
            [],
            is_file($js) ? (string) filemtime($js) : TROVESIA_PLUGIN_VERSION,
            true
        );
        wp_localize_script('trovesia-account-modal', 'trovesiaAccount', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
            'myAccountPath'  => wp_parse_url(wc_get_page_permalink('myaccount'), PHP_URL_PATH),
            'workingMessage' => __('Please wait…', 'trovesia-plugin'),
            'errorMessage'   => __('Something went wrong. Please try again.', 'trovesia-plugin'),
        ]);
    }

    public function render_modal(): void
    {
        if (! class_exists('WooCommerce') || is_user_logged_in()) {
            return;
        }

        $registration_enabled = $this->registration_enabled();
        $generate_password = 'yes' === get_option('woocommerce_registration_generate_password', 'yes');
        $generate_username = 'yes' === get_option('woocommerce_registration_generate_username', 'yes');
        $privacy_url = get_privacy_policy_url();
        ?>
        <div class="trovesia-account-modal" data-trovesia-account-modal hidden>
            <button class="trovesia-account-modal__backdrop" type="button" data-account-modal-close tabindex="-1" aria-label="<?php esc_attr_e('Close account window', 'trovesia-plugin'); ?>"></button>
            <section class="trovesia-account-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="trovesia-account-title">
                <header class="trovesia-account-modal__header">
                    <div>
                        <p class="trovesia-account-modal__eyebrow"><?php esc_html_e('Welcome to Trovesia', 'trovesia-plugin'); ?></p>
                        <h2 id="trovesia-account-title"><?php esc_html_e('Your account', 'trovesia-plugin'); ?></h2>
                    </div>
                    <button class="trovesia-account-modal__close" type="button" data-account-modal-close aria-label="<?php esc_attr_e('Close account window', 'trovesia-plugin'); ?>">&times;</button>
                </header>

                <?php if ($registration_enabled) : ?>
                    <div class="trovesia-account-tabs" role="tablist" aria-label="<?php esc_attr_e('Account options', 'trovesia-plugin'); ?>">
                        <button id="trovesia-login-tab" class="trovesia-account-tabs__button is-active" type="button" role="tab" aria-selected="true" aria-controls="trovesia-login-panel" data-account-tab="login"><?php esc_html_e('Log in', 'trovesia-plugin'); ?></button>
                        <button id="trovesia-register-tab" class="trovesia-account-tabs__button" type="button" role="tab" aria-selected="false" aria-controls="trovesia-register-panel" data-account-tab="register"><?php esc_html_e('Create account', 'trovesia-plugin'); ?></button>
                    </div>
                <?php endif; ?>

                <div class="trovesia-account-notice" data-account-notice role="status" aria-live="polite" hidden></div>

                <div id="trovesia-login-panel" class="trovesia-account-panel" role="tabpanel" aria-labelledby="trovesia-login-tab" data-account-panel="login">
                    <p class="trovesia-account-panel__intro"><?php esc_html_e('Log in to view your orders and continue checkout faster.', 'trovesia-plugin'); ?></p>
                    <form class="trovesia-account-form" data-account-form="trovesia_account_login">
                        <p>
                            <label for="trovesia-account-login"><?php esc_html_e('Email or username', 'trovesia-plugin'); ?></label>
                            <input id="trovesia-account-login" name="login" type="text" autocomplete="username" required>
                        </p>
                        <p>
                            <label for="trovesia-account-password"><?php esc_html_e('Password', 'trovesia-plugin'); ?></label>
                            <span class="trovesia-password-field">
                                <input id="trovesia-account-password" name="password" type="password" autocomplete="current-password" required>
                                <button type="button" data-password-toggle aria-label="<?php esc_attr_e('Show password', 'trovesia-plugin'); ?>"><?php esc_html_e('Show', 'trovesia-plugin'); ?></button>
                            </span>
                        </p>
                        <div class="trovesia-account-form__row">
                            <label class="trovesia-account-form__remember"><input name="remember" type="checkbox" value="1"> <?php esc_html_e('Remember me', 'trovesia-plugin'); ?></label>
                            <a href="<?php echo esc_url(wc_lostpassword_url()); ?>"><?php esc_html_e('Forgot password?', 'trovesia-plugin'); ?></a>
                        </div>
                        <button class="trovesia-account-form__submit" type="submit"><?php esc_html_e('Log in', 'trovesia-plugin'); ?></button>
                    </form>
                </div>

                <?php if ($registration_enabled) : ?>
                    <div id="trovesia-register-panel" class="trovesia-account-panel" role="tabpanel" aria-labelledby="trovesia-register-tab" data-account-panel="register" hidden>
                        <p class="trovesia-account-panel__intro"><?php esc_html_e('Create an account to keep every order and delivery update in one place.', 'trovesia-plugin'); ?></p>
                        <form class="trovesia-account-form" data-account-form="trovesia_account_register">
                            <div class="trovesia-account-form__names">
                                <p>
                                    <label for="trovesia-account-first-name"><?php esc_html_e('First name', 'trovesia-plugin'); ?></label>
                                    <input id="trovesia-account-first-name" name="first_name" type="text" autocomplete="given-name">
                                </p>
                                <p>
                                    <label for="trovesia-account-last-name"><?php esc_html_e('Last name', 'trovesia-plugin'); ?></label>
                                    <input id="trovesia-account-last-name" name="last_name" type="text" autocomplete="family-name">
                                </p>
                            </div>
                            <?php if (! $generate_username) : ?>
                                <p>
                                    <label for="trovesia-account-username"><?php esc_html_e('Username', 'trovesia-plugin'); ?></label>
                                    <input id="trovesia-account-username" name="username" type="text" autocomplete="username" required>
                                </p>
                            <?php endif; ?>
                            <p>
                                <label for="trovesia-account-email"><?php esc_html_e('Email address', 'trovesia-plugin'); ?></label>
                                <input id="trovesia-account-email" name="email" type="email" autocomplete="email" required>
                            </p>
                            <?php if (! $generate_password) : ?>
                                <p>
                                    <label for="trovesia-register-password"><?php esc_html_e('Password', 'trovesia-plugin'); ?></label>
                                    <span class="trovesia-password-field">
                                        <input id="trovesia-register-password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                                        <button type="button" data-password-toggle aria-label="<?php esc_attr_e('Show password', 'trovesia-plugin'); ?>"><?php esc_html_e('Show', 'trovesia-plugin'); ?></button>
                                    </span>
                                </p>
                            <?php else : ?>
                                <p class="trovesia-account-form__hint"><?php esc_html_e('We will email you a secure link to set your password.', 'trovesia-plugin'); ?></p>
                            <?php endif; ?>
                            <p class="trovesia-account-form__honeypot" aria-hidden="true">
                                <label for="trovesia-account-website">Website</label>
                                <input id="trovesia-account-website" name="website" type="text" tabindex="-1" autocomplete="off">
                            </p>
                            <?php if ($privacy_url) : ?>
                                <p class="trovesia-account-form__privacy">
                                    <?php
                                    printf(
                                        wp_kses_post(__('By creating an account, you agree to our <a href="%s">Privacy Policy</a>.', 'trovesia-plugin')),
                                        esc_url($privacy_url)
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                            <button class="trovesia-account-form__submit" type="submit"><?php esc_html_e('Create account', 'trovesia-plugin'); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public function handle_login(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (is_user_logged_in()) {
            wp_send_json_success(['message' => __('You are already logged in.', 'trovesia-plugin')]);
        }

        $login = isset($_POST['login']) ? trim(wp_unslash((string) $_POST['login'])) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $remember = ! empty($_POST['remember']);

        if ($login === '' || $password === '') {
            wp_send_json_error(['message' => __('Enter your email or username and password.', 'trovesia-plugin')], 422);
        }

        $validation = apply_filters('woocommerce_process_login_errors', new WP_Error(), $login, $password);
        if ($validation instanceof WP_Error && $validation->get_error_code()) {
            $this->send_error($validation);
        }

        $credentials = apply_filters('woocommerce_login_credentials', [
            'user_login'    => $login,
            'user_password' => $password,
            'remember'      => $remember,
        ]);
        $user = wp_signon($credentials, is_ssl());

        if ($user instanceof WP_Error) {
            do_action('woocommerce_login_failed');
            $this->send_error($user, 401);
        }

        if (is_multisite() && ! is_user_member_of_blog($user->ID, get_current_blog_id())) {
            add_user_to_blog(get_current_blog_id(), $user->ID, 'customer');
        }

        wp_send_json_success([
            'message' => __('Welcome back. Your account is ready.', 'trovesia-plugin'),
            'reload'  => true,
        ]);
    }

    public function handle_registration(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (is_user_logged_in()) {
            wp_send_json_success(['message' => __('Your account is already active.', 'trovesia-plugin')]);
        }

        if (! $this->registration_enabled()) {
            wp_send_json_error(['message' => __('Account registration is currently unavailable.', 'trovesia-plugin')], 403);
        }

        if (! empty($_POST['website'])) {
            wp_send_json_error(['message' => __('Your account could not be created.', 'trovesia-plugin')], 422);
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash((string) $_POST['email'])) : '';
        $username = 'no' === get_option('woocommerce_registration_generate_username', 'yes') && isset($_POST['username'])
            ? sanitize_user(wp_unslash((string) $_POST['username']))
            : '';
        $password = 'no' === get_option('woocommerce_registration_generate_password', 'yes') && isset($_POST['password'])
            ? (string) $_POST['password'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash((string) $_POST['first_name'])) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash((string) $_POST['last_name'])) : '';

        if (! is_email($email)) {
            wp_send_json_error(['message' => __('Enter a valid email address.', 'trovesia-plugin')], 422);
        }

        if ('no' === get_option('woocommerce_registration_generate_password', 'yes') && strlen($password) < 8) {
            wp_send_json_error(['message' => __('Use a password with at least 8 characters.', 'trovesia-plugin')], 422);
        }

        $validation = apply_filters('woocommerce_process_registration_errors', new WP_Error(), $username, $password, $email);
        if ($validation instanceof WP_Error && $validation->get_error_code()) {
            $this->send_error($validation);
        }

        $customer_id = wc_create_new_customer($email, $username, $password, [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'source'     => 'trovesia-account-modal',
        ]);

        if ($customer_id instanceof WP_Error) {
            $this->send_error($customer_id);
        }

        $authenticated = (bool) apply_filters('woocommerce_registration_auth_new_customer', true, $customer_id);
        if ($authenticated) {
            wc_set_customer_auth_cookie($customer_id);
        }

        wp_send_json_success([
            'message'       => 'yes' === get_option('woocommerce_registration_generate_password', 'yes')
                ? __('Account created. We signed you in and emailed a secure password setup link.', 'trovesia-plugin')
                : __('Account created. You are now signed in.', 'trovesia-plugin'),
            'reload'        => $authenticated,
            'authenticated' => $authenticated,
        ]);
    }

    private function registration_enabled(): bool
    {
        return 'yes' === get_option('woocommerce_enable_myaccount_registration')
            || 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout');
    }

    private function send_error(WP_Error $error, int $status = 422): void
    {
        $messages = $error->get_error_messages();
        $message = $messages ? implode(' ', array_map('wp_strip_all_tags', $messages)) : __('Something went wrong. Please try again.', 'trovesia-plugin');

        wp_send_json_error(['message' => $message], $status);
    }
}
