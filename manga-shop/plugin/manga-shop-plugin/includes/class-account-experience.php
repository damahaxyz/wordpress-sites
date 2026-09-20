<?php
/**
 * Modal login and registration experience.
 *
 * @package MangaShopPlugin
 */

declare(strict_types=1);

namespace MangaShop\Plugin;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class Account_Experience
{
    private const NONCE_ACTION = 'manga_shop_account_modal';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_modal'], 5);
        add_action('wp_ajax_nopriv_manga_shop_account_login', [$this, 'handle_login']);
        add_action('wp_ajax_nopriv_manga_shop_account_register', [$this, 'handle_registration']);
    }

    public function enqueue_assets(): void
    {
        if (! class_exists('WooCommerce') || is_user_logged_in()) {
            return;
        }

        $css = MANGA_SHOP_PLUGIN_DIR . 'assets/css/account-modal.css';
        $js = MANGA_SHOP_PLUGIN_DIR . 'assets/js/account-modal.js';

        wp_enqueue_style(
            'manga-shop-account-modal',
            plugins_url('assets/css/account-modal.css', MANGA_SHOP_PLUGIN_FILE),
            [],
            is_file($css) ? (string) filemtime($css) : MANGA_SHOP_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'manga-shop-account-modal',
            plugins_url('assets/js/account-modal.js', MANGA_SHOP_PLUGIN_FILE),
            [],
            is_file($js) ? (string) filemtime($js) : MANGA_SHOP_PLUGIN_VERSION,
            true
        );
        wp_localize_script('manga-shop-account-modal', 'mangaShopAccount', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
            'myAccountPath'  => wp_parse_url(wc_get_page_permalink('myaccount'), PHP_URL_PATH),
            'workingMessage' => __('Please wait…', 'manga-shop-plugin'),
            'errorMessage'   => __('Something went wrong. Please try again.', 'manga-shop-plugin'),
            'showPassword'   => __('Show password', 'manga-shop-plugin'),
            'hidePassword'   => __('Hide password', 'manga-shop-plugin'),
            'show'           => __('Show', 'manga-shop-plugin'),
            'hide'           => __('Hide', 'manga-shop-plugin'),
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
        <div class="manga-account-modal" id="manga-account-modal" data-manga-account-modal hidden>
            <button class="manga-account-modal__backdrop" type="button" data-account-modal-close tabindex="-1" aria-label="<?php esc_attr_e('Close account window', 'manga-shop-plugin'); ?>"></button>
            <section class="manga-account-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="manga-account-title">
                <div class="manga-account-modal__accent" aria-hidden="true">
                    <span><?php esc_html_e('Member access', 'manga-shop-plugin'); ?></span>
                    <strong><?php esc_html_e('Your next anime find is waiting.', 'manga-shop-plugin'); ?></strong>
                </div>
                <div class="manga-account-modal__content">
                    <header class="manga-account-modal__header">
                        <div>
                            <p class="manga-account-modal__eyebrow"><?php esc_html_e('Welcome to Manga Product', 'manga-shop-plugin'); ?></p>
                            <h2 id="manga-account-title"><?php esc_html_e('Your account', 'manga-shop-plugin'); ?></h2>
                        </div>
                        <button class="manga-account-modal__close" type="button" data-account-modal-close aria-label="<?php esc_attr_e('Close account window', 'manga-shop-plugin'); ?>">&times;</button>
                    </header>

                    <?php if ($registration_enabled) : ?>
                        <div class="manga-account-tabs" role="tablist" aria-label="<?php esc_attr_e('Account options', 'manga-shop-plugin'); ?>">
                            <button id="manga-login-tab" class="manga-account-tabs__button is-active" type="button" role="tab" aria-selected="true" aria-controls="manga-login-panel" data-account-tab="login"><?php esc_html_e('Log in', 'manga-shop-plugin'); ?></button>
                            <button id="manga-register-tab" class="manga-account-tabs__button" type="button" role="tab" aria-selected="false" aria-controls="manga-register-panel" data-account-tab="register"><?php esc_html_e('Create account', 'manga-shop-plugin'); ?></button>
                        </div>
                    <?php endif; ?>

                    <div class="manga-account-notice" data-account-notice role="status" aria-live="polite" hidden></div>

                    <div id="manga-login-panel" class="manga-account-panel" role="tabpanel" aria-labelledby="manga-login-tab" data-account-panel="login">
                        <p class="manga-account-panel__intro"><?php esc_html_e('Sign in to view orders, save your details and move through checkout faster.', 'manga-shop-plugin'); ?></p>
                        <form class="manga-account-form" data-account-form="manga_shop_account_login">
                            <p>
                                <label for="manga-account-login"><?php esc_html_e('Email or username', 'manga-shop-plugin'); ?></label>
                                <input id="manga-account-login" name="login" type="text" autocomplete="username" required>
                            </p>
                            <p>
                                <label for="manga-account-password"><?php esc_html_e('Password', 'manga-shop-plugin'); ?></label>
                                <span class="manga-password-field">
                                    <input id="manga-account-password" name="password" type="password" autocomplete="current-password" required>
                                    <button type="button" data-password-toggle aria-label="<?php esc_attr_e('Show password', 'manga-shop-plugin'); ?>"><?php esc_html_e('Show', 'manga-shop-plugin'); ?></button>
                                </span>
                            </p>
                            <div class="manga-account-form__row">
                                <label class="manga-account-form__remember"><input name="remember" type="checkbox" value="1"> <?php esc_html_e('Remember me', 'manga-shop-plugin'); ?></label>
                                <a href="<?php echo esc_url(wc_lostpassword_url()); ?>"><?php esc_html_e('Forgot password?', 'manga-shop-plugin'); ?></a>
                            </div>
                            <button class="manga-account-form__submit" type="submit"><?php esc_html_e('Log in', 'manga-shop-plugin'); ?></button>
                        </form>
                    </div>

                    <?php if ($registration_enabled) : ?>
                        <div id="manga-register-panel" class="manga-account-panel" role="tabpanel" aria-labelledby="manga-register-tab" data-account-panel="register" hidden>
                            <p class="manga-account-panel__intro"><?php esc_html_e('Create an account to keep orders, addresses and delivery updates together.', 'manga-shop-plugin'); ?></p>
                            <form class="manga-account-form" data-account-form="manga_shop_account_register">
                                <div class="manga-account-form__names">
                                    <p>
                                        <label for="manga-account-first-name"><?php esc_html_e('First name', 'manga-shop-plugin'); ?></label>
                                        <input id="manga-account-first-name" name="first_name" type="text" autocomplete="given-name">
                                    </p>
                                    <p>
                                        <label for="manga-account-last-name"><?php esc_html_e('Last name', 'manga-shop-plugin'); ?></label>
                                        <input id="manga-account-last-name" name="last_name" type="text" autocomplete="family-name">
                                    </p>
                                </div>
                                <?php if (! $generate_username) : ?>
                                    <p>
                                        <label for="manga-account-username"><?php esc_html_e('Username', 'manga-shop-plugin'); ?></label>
                                        <input id="manga-account-username" name="username" type="text" autocomplete="username" required>
                                    </p>
                                <?php endif; ?>
                                <p>
                                    <label for="manga-account-email"><?php esc_html_e('Email address', 'manga-shop-plugin'); ?></label>
                                    <input id="manga-account-email" name="email" type="email" autocomplete="email" required>
                                </p>
                                <?php if (! $generate_password) : ?>
                                    <p>
                                        <label for="manga-register-password"><?php esc_html_e('Password', 'manga-shop-plugin'); ?></label>
                                        <span class="manga-password-field">
                                            <input id="manga-register-password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                                            <button type="button" data-password-toggle aria-label="<?php esc_attr_e('Show password', 'manga-shop-plugin'); ?>"><?php esc_html_e('Show', 'manga-shop-plugin'); ?></button>
                                        </span>
                                    </p>
                                <?php else : ?>
                                    <p class="manga-account-form__hint"><?php esc_html_e('We will email you a secure link to create your password.', 'manga-shop-plugin'); ?></p>
                                <?php endif; ?>
                                <p class="manga-account-form__honeypot" aria-hidden="true">
                                    <label for="manga-account-website">Website</label>
                                    <input id="manga-account-website" name="website" type="text" tabindex="-1" autocomplete="off">
                                </p>
                                <?php if ($privacy_url) : ?>
                                    <p class="manga-account-form__privacy">
                                        <?php
                                        printf(
                                            wp_kses_post(__('By creating an account, you agree to our <a href="%s">Privacy Policy</a>.', 'manga-shop-plugin')),
                                            esc_url($privacy_url)
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                                <button class="manga-account-form__submit" type="submit"><?php esc_html_e('Create account', 'manga-shop-plugin'); ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php
    }

    public function handle_login(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (is_user_logged_in()) {
            wp_send_json_success(['message' => __('You are already logged in.', 'manga-shop-plugin')]);
        }

        $login = isset($_POST['login']) ? trim(wp_unslash((string) $_POST['login'])) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $remember = ! empty($_POST['remember']);

        if ($login === '' || $password === '') {
            wp_send_json_error(['message' => __('Enter your email or username and password.', 'manga-shop-plugin')], 422);
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
            'message' => __('Welcome back. Your account is ready.', 'manga-shop-plugin'),
            'reload'  => true,
        ]);
    }

    public function handle_registration(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (is_user_logged_in()) {
            wp_send_json_success(['message' => __('Your account is already active.', 'manga-shop-plugin')]);
        }

        if (! $this->registration_enabled()) {
            wp_send_json_error(['message' => __('Account registration is currently unavailable.', 'manga-shop-plugin')], 403);
        }

        if (! empty($_POST['website'])) {
            wp_send_json_error(['message' => __('Your account could not be created.', 'manga-shop-plugin')], 422);
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
            wp_send_json_error(['message' => __('Enter a valid email address.', 'manga-shop-plugin')], 422);
        }

        if ('no' === get_option('woocommerce_registration_generate_password', 'yes') && strlen($password) < 8) {
            wp_send_json_error(['message' => __('Use a password with at least 8 characters.', 'manga-shop-plugin')], 422);
        }

        $validation = apply_filters('woocommerce_process_registration_errors', new WP_Error(), $username, $password, $email);
        if ($validation instanceof WP_Error && $validation->get_error_code()) {
            $this->send_error($validation);
        }

        $customer_id = wc_create_new_customer($email, $username, $password, [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'source'     => 'manga-shop-account-modal',
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
                ? __('Account created. We signed you in and emailed a secure password setup link.', 'manga-shop-plugin')
                : __('Account created. You are now signed in.', 'manga-shop-plugin'),
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
        $message = $messages
            ? implode(' ', array_map('wp_strip_all_tags', $messages))
            : __('Something went wrong. Please try again.', 'manga-shop-plugin');

        wp_send_json_error(['message' => $message], $status);
    }
}
