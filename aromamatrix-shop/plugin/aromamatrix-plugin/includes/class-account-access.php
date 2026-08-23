<?php
/**
 * Customer sign-in and registration for purchase-restricted stores.
 *
 * @package AromamatrixPlugin
 */

declare(strict_types=1);

namespace Aromamatrix\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class AccountAccess
{
    private const NONCE_ACTION = 'aromamatrix_account_access';

    public function register(): void
    {
        add_action('wp_footer', [$this, 'render_modal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_script_data'], 20);
        add_action('template_redirect', [$this, 'prevent_account_page_caching']);
        add_action('wp_ajax_nopriv_aromamatrix_account_login', [$this, 'login']);
        add_action('wp_ajax_nopriv_aromamatrix_account_register', [$this, 'register_customer']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'prevent_guest_cart_addition'], 10, 2);
        add_filter('authenticate', [$this, 'authenticate_by_whatsapp'], 5, 3);
        add_filter('authenticate', [$this, 'prevent_username_authentication'], 40, 3);
        add_filter('woocommerce_registration_generate_password', '__return_false');
        add_filter('option_woocommerce_registration_generate_password', [$this, 'disable_generated_registration_passwords']);
        add_action('woocommerce_before_customer_login_form', [$this, 'render_my_account_tabs']);
        add_action('woocommerce_register_form_start', [$this, 'render_native_whatsapp_field']);
        add_action('woocommerce_register_form', [$this, 'render_native_password_confirmation']);
        add_filter('woocommerce_registration_errors', [$this, 'validate_native_registration'], 10, 3);
        add_action('woocommerce_created_customer', [$this, 'save_native_registration_details']);
        add_filter('gettext', [$this, 'adapt_account_copy'], 20, 3);
    }

    /**
     * WooCommerce's "Allow customers to place orders without an account"
     * setting is the store-wide source of truth for this requirement.
     */
    public static function requires_login_to_purchase(): bool
    {
        return 'no' === get_option('woocommerce_enable_guest_checkout', 'yes');
    }

    public function enqueue_script_data(): void
    {
        wp_localize_script('aromamatrix-theme', 'aromamatrixAccount', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce(self::NONCE_ACTION),
            'accountUrl'    => wc_get_page_permalink('myaccount'),
            'isLoggedIn'    => is_user_logged_in(),
            'requiresLogin' => self::requires_login_to_purchase(),
            'canRegister'   => $this->registration_is_enabled(),
            'messages'      => [
                'loginRequired' => __('Please sign in or create an account before adding products to your cart.', 'aromamatrix-plugin'),
                'working'       => __('Please wait…', 'aromamatrix-plugin'),
                'genericError'  => __('Something went wrong. Please try again.', 'aromamatrix-plugin'),
            ],
            'nativeAccountPage' => function_exists('is_account_page') && is_account_page() && ! is_user_logged_in(),
        ]);
    }

    public function prevent_account_page_caching(): void
    {
        if (function_exists('is_account_page') && is_account_page()) {
            nocache_headers();
        }
    }

    public function render_modal(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        $can_register = $this->registration_is_enabled();
        ?>
        <div class="aromamatrix-account-modal" hidden data-account-modal aria-hidden="true">
            <div class="aromamatrix-account-modal__backdrop" data-account-modal-close></div>
            <section class="aromamatrix-account-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="aromamatrix-account-modal-title" tabindex="-1">
                <button class="aromamatrix-account-modal__close" type="button" aria-label="<?php esc_attr_e('Close', 'aromamatrix-plugin'); ?>" data-account-modal-close>×</button>
                <p class="aromamatrix-account-modal__eyebrow"><?php esc_html_e('Customer account', 'aromamatrix-plugin'); ?></p>
                <h2 id="aromamatrix-account-modal-title"><?php esc_html_e('Sign in to continue', 'aromamatrix-plugin'); ?></h2>
                <p class="aromamatrix-account-modal__intro" data-account-modal-intro><?php esc_html_e('Sign in to manage your cart and place your order.', 'aromamatrix-plugin'); ?></p>

                <div class="aromamatrix-account-modal__tabs" role="tablist" aria-label="<?php esc_attr_e('Account access', 'aromamatrix-plugin'); ?>">
                    <button id="aromamatrix-login-tab" type="button" role="tab" aria-selected="true" aria-controls="aromamatrix-login-panel" data-account-tab="login"><?php esc_html_e('Sign in', 'aromamatrix-plugin'); ?></button>
                    <?php if ($can_register) : ?>
                        <button id="aromamatrix-register-tab" type="button" role="tab" aria-selected="false" aria-controls="aromamatrix-register-panel" data-account-tab="register"><?php esc_html_e('Create account', 'aromamatrix-plugin'); ?></button>
                    <?php endif; ?>
                </div>

                <p class="aromamatrix-account-modal__message" role="status" aria-live="polite" data-account-message></p>

                <form id="aromamatrix-login-panel" class="aromamatrix-account-form" data-account-form="login" role="tabpanel" aria-labelledby="aromamatrix-login-tab">
                    <label>
                        <span><?php esc_html_e('Email or WhatsApp number', 'aromamatrix-plugin'); ?></span>
                        <input name="log" type="text" autocomplete="username" placeholder="<?php esc_attr_e('e.g. +1 212 555 0100', 'aromamatrix-plugin'); ?>" required>
                    </label>
                    <label>
                        <span><?php esc_html_e('Password', 'aromamatrix-plugin'); ?></span>
                        <input name="pwd" type="password" autocomplete="current-password" required>
                    </label>
                    <label class="aromamatrix-account-form__remember"><input name="remember" type="checkbox" value="1"><span><?php esc_html_e('Remember me', 'aromamatrix-plugin'); ?></span></label>
                    <button type="submit" class="aromamatrix-account-form__submit"><?php esc_html_e('Sign in', 'aromamatrix-plugin'); ?></button>
                </form>

                <?php if ($can_register) : ?>
                    <form id="aromamatrix-register-panel" class="aromamatrix-account-form" data-account-form="register" role="tabpanel" aria-labelledby="aromamatrix-register-tab" hidden>
                        <label>
                            <span><?php esc_html_e('Email', 'aromamatrix-plugin'); ?> <span class="required" aria-hidden="true">*</span></span>
                            <input name="email" type="email" autocomplete="email" required>
                        </label>
                        <?php $this->render_whatsapp_field(); ?>
                        <label>
                            <span><?php esc_html_e('Create a password', 'aromamatrix-plugin'); ?></span>
                            <input name="password" type="password" autocomplete="new-password" minlength="8" required>
                        </label>
                        <label>
                            <span><?php esc_html_e('Confirm password', 'aromamatrix-plugin'); ?></span>
                            <input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
                        </label>
                        <button type="submit" class="aromamatrix-account-form__submit"><?php esc_html_e('Create account', 'aromamatrix-plugin'); ?></button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public function login(): void
    {
        $this->verify_request();

        $credentials = [
            'user_login'    => sanitize_text_field(wp_unslash($_POST['log'] ?? '')),
            'user_password' => (string) wp_unslash($_POST['pwd'] ?? ''),
            'remember'      => ! empty($_POST['remember']),
        ];

        if ($credentials['user_login'] === '' || $credentials['user_password'] === '') {
            wp_send_json_error(['message' => __('Enter your email or WhatsApp number and password.', 'aromamatrix-plugin')], 400);
        }

        $credentials['user_login'] = $this->resolve_login_identifier($credentials['user_login']);
        if (! is_email($credentials['user_login']) && $this->normalise_full_phone($credentials['user_login']) === '') {
            wp_send_json_error(['message' => __('Use the email address or full WhatsApp number associated with your account.', 'aromamatrix-plugin')], 400);
        }

        $user = wp_signon($credentials, $this->uses_secure_cookies());

        if (is_wp_error($user)) {
            wp_send_json_error(['message' => __('The email, WhatsApp number, or password is incorrect.', 'aromamatrix-plugin')], 401);
        }

        wp_send_json_success(['message' => __('You are signed in.', 'aromamatrix-plugin')]);
    }

    public function register_customer(): void
    {
        $this->verify_request();

        if (! $this->registration_is_enabled()) {
            wp_send_json_error(['message' => __('Account registration is currently unavailable.', 'aromamatrix-plugin')], 403);
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        $password_confirmation = (string) wp_unslash($_POST['password_confirmation'] ?? '');
        $whatsapp = $this->request_whatsapp_number();

        if (! is_email($email)) {
            wp_send_json_error(['message' => __('Enter a valid email address.', 'aromamatrix-plugin')], 400);
        }

        if (strlen($password) < 8) {
            wp_send_json_error(['message' => __('Use a password with at least 8 characters.', 'aromamatrix-plugin')], 400);
        }

        if ($password !== $password_confirmation) {
            wp_send_json_error(['message' => __('The password confirmation does not match.', 'aromamatrix-plugin')], 400);
        }

        if (is_wp_error($whatsapp)) {
            wp_send_json_error(['message' => $whatsapp->get_error_message()], 400);
        }

        $customer_id = wc_create_new_customer($email, '', $password);

        if (is_wp_error($customer_id)) {
            wp_send_json_error(['message' => $customer_id->get_error_message()], 400);
        }

        $this->save_customer_phone((int) $customer_id, $whatsapp);
        $this->sync_customer_identity((int) $customer_id);

        wp_set_current_user((int) $customer_id);
        $this->establish_customer_session((int) $customer_id, true);
        do_action('wp_login', $email, get_user_by('id', (int) $customer_id));

        wp_send_json_success(['message' => __('Your account is ready.', 'aromamatrix-plugin')]);
    }

    public function prevent_guest_cart_addition(bool $passed, int $product_id): bool
    {
        if (! $passed || ! self::requires_login_to_purchase() || is_user_logged_in()) {
            return $passed;
        }

        wc_add_notice(
            __('Please sign in or create an account before adding products to your cart.', 'aromamatrix-plugin'),
            'error'
        );

        return false;
    }

    /**
     * Allow a full international WhatsApp number to be used anywhere WordPress
     * normally accepts an email. The stored number is always E.164-like.
     *
     * @param \WP_User|\WP_Error|null $user Existing authentication result.
     * @return \WP_User|\WP_Error|null
     */
    public function authenticate_by_whatsapp($user, string $username, string $password)
    {
        if ($user instanceof \WP_User || $username === '') {
            return $user;
        }

        $resolved_login = $this->resolve_login_identifier($username);
        if ($resolved_login === $username) {
            return $user;
        }

        return wp_authenticate_email_password(null, $resolved_login, $password);
    }

    /**
     * Keep the public account identifier limited to email or WhatsApp number;
     * WordPress may still maintain an internal username for compatibility.
     *
     * @param \WP_User|\WP_Error|null $user Authentication result.
     * @return \WP_User|\WP_Error|null
     */
    public function prevent_username_authentication($user, string $username, string $password)
    {
        if ($username === '' || is_email($username) || $this->normalise_full_phone($username) !== '') {
            return $user;
        }

        return new \WP_Error(
            'aromamatrix_email_or_whatsapp_required',
            __('Please sign in with your email address or WhatsApp number.', 'aromamatrix-plugin')
        );
    }

    public function render_my_account_tabs(): void
    {
        if (is_user_logged_in() || ! $this->registration_is_enabled()) {
            return;
        }
        ?>
        <div class="aromamatrix-native-account-tabs" role="tablist" aria-label="<?php esc_attr_e('Account access', 'aromamatrix-plugin'); ?>">
            <button type="button" role="tab" aria-selected="true" aria-controls="aromamatrix-native-login" data-native-account-tab="login"><?php esc_html_e('Sign in', 'aromamatrix-plugin'); ?></button>
            <button type="button" role="tab" aria-selected="false" aria-controls="aromamatrix-native-register" data-native-account-tab="register"><?php esc_html_e('Create account', 'aromamatrix-plugin'); ?></button>
        </div>
        <?php
    }

    public function render_native_whatsapp_field(): void
    {
        $this->render_whatsapp_field(true);
    }

    public function render_native_password_confirmation(): void
    {
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="reg_password_confirmation"><?php esc_html_e('Confirm password', 'aromamatrix-plugin'); ?> <span class="required" aria-hidden="true">*</span></label>
            <input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_confirmation" id="reg_password_confirmation" autocomplete="new-password" minlength="8" required>
        </p>
        <?php
    }

    /**
     * @param \WP_Error $errors Registration validation errors.
     */
    public function validate_native_registration(\WP_Error $errors, string $username, string $email): \WP_Error
    {
        $phone = $this->request_whatsapp_number();

        if (is_wp_error($phone)) {
            $errors->add('aromamatrix_whatsapp_phone', $phone->get_error_message());
        }

        $password = (string) wp_unslash($_POST['password'] ?? '');
        $password_confirmation = (string) wp_unslash($_POST['password_confirmation'] ?? '');
        if ($password !== $password_confirmation) {
            $errors->add('aromamatrix_password_confirmation', __('The password confirmation does not match.', 'aromamatrix-plugin'));
        }

        return $errors;
    }

    public function save_native_registration_details(int $customer_id): void
    {
        $phone = $this->request_whatsapp_number();
        if (! is_wp_error($phone)) {
            $this->save_customer_phone($customer_id, $phone);
        }
        $this->sync_customer_identity($customer_id);
    }

    public function adapt_account_copy(string $translated, string $text, string $domain): string
    {
        if ($domain !== 'woocommerce' || ! function_exists('is_account_page') || ! is_account_page() || is_user_logged_in()) {
            return $translated;
        }

        if ($text === 'Username or email address') {
            return __('Email or WhatsApp number', 'aromamatrix-plugin');
        }

        if ($text === 'Email address') {
            return __('Email', 'aromamatrix-plugin');
        }

        if ($text === 'Login') {
            return __('Sign in', 'aromamatrix-plugin');
        }

        if ($text === 'Register') {
            return __('Create account', 'aromamatrix-plugin');
        }

        return $translated;
    }

    public function disable_generated_registration_passwords($value): string
    {
        return 'no';
    }

    private function render_whatsapp_field(bool $native_form = false): void
    {
        $countries = $this->whatsapp_countries();
        $selected_country = sanitize_text_field(wp_unslash($_POST['whatsapp_country'] ?? 'US'));
        $resolved_country = $this->resolve_whatsapp_country($selected_country, $countries);
        $country_value = $resolved_country === null
            ? $selected_country
            : $countries[$resolved_country]['calling_code'];
        $number = sanitize_text_field(wp_unslash($_POST['whatsapp_number'] ?? ''));
        $datalist_id = $native_form
            ? 'aromamatrix-native-whatsapp-countries'
            : 'aromamatrix-modal-whatsapp-countries';
        $wrapper = $native_form
            ? '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide aromamatrix-whatsapp-field">'
            : '<label class="aromamatrix-whatsapp-field">';
        $wrapper_end = $native_form ? '</p>' : '</label>';
        echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static wrapper.
        ?>
            <span><?php esc_html_e('WhatsApp number', 'aromamatrix-plugin'); ?> <span class="required" aria-hidden="true">*</span></span>
            <span class="aromamatrix-whatsapp-field__control">
                <input class="aromamatrix-whatsapp-country-search" name="whatsapp_country" type="text" list="<?php echo esc_attr($datalist_id); ?>" autocomplete="off" placeholder="<?php esc_attr_e('Search country or code', 'aromamatrix-plugin'); ?>" value="<?php echo esc_attr($country_value); ?>" aria-label="<?php esc_attr_e('Search WhatsApp country calling code', 'aromamatrix-plugin'); ?>" required>
                <datalist id="<?php echo esc_attr($datalist_id); ?>">
                    <?php foreach ($countries as $country_code => $country) : ?>
                        <option value="<?php echo esc_attr($country['calling_code']); ?>" label="<?php echo esc_attr($country['name']); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <input name="whatsapp_number" type="tel" autocomplete="tel" inputmode="tel" placeholder="<?php esc_attr_e('WhatsApp number', 'aromamatrix-plugin'); ?>" value="<?php echo esc_attr($number); ?>" required>
            </span>
        <?php
        echo $wrapper_end; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static wrapper.
    }

    /**
     * @return array<string, array{name: string, calling_code: string}>
     */
    private function whatsapp_countries(): array
    {
        $countries = WC()->countries->get_countries();
        $calling_countries = [];

        foreach ($countries as $country_code => $country_name) {
            $calling_code = trim((string) WC()->countries->get_country_calling_code($country_code));

            if ($calling_code === '') {
                continue;
            }

            $calling_countries[$country_code] = [
                'name'         => $country_name,
                'calling_code' => '+' . ltrim($calling_code, '+'),
            ];
        }

        return $calling_countries;
    }

    /**
     * @return string|\WP_Error
     */
    private function request_whatsapp_number()
    {
        $country_code = sanitize_text_field(wp_unslash($_POST['whatsapp_country'] ?? ''));
        $number = sanitize_text_field(wp_unslash($_POST['whatsapp_number'] ?? ''));
        $countries = $this->whatsapp_countries();
        $resolved_country = $this->resolve_whatsapp_country($country_code, $countries);

        if ($resolved_country === null) {
            return new \WP_Error('aromamatrix_whatsapp_country', __('Choose a WhatsApp country calling code.', 'aromamatrix-plugin'));
        }

        $digits = preg_replace('/\D+/', '', $number);
        $phone = $this->normalise_full_phone($countries[$resolved_country]['calling_code'] . $digits);

        if ($phone === '') {
            return new \WP_Error('aromamatrix_whatsapp_format', __('Enter a valid WhatsApp number.', 'aromamatrix-plugin'));
        }

        $matches = get_users([
            'fields'     => 'ids',
            'meta_key'   => '_aromamatrix_whatsapp_phone',
            'meta_value' => $phone,
            'number'     => 1,
        ]);
        if ($matches !== []) {
            return new \WP_Error('aromamatrix_whatsapp_exists', __('An account already uses this WhatsApp number. Sign in instead.', 'aromamatrix-plugin'));
        }

        return $phone;
    }

    /**
     * @param array<string, array{name: string, calling_code: string}> $countries
     */
    private function resolve_whatsapp_country(string $value, array $countries): ?string
    {
        if (isset($countries[$value])) {
            return $value;
        }

        foreach ($countries as $country_code => $country) {
            if (
                $this->whatsapp_country_label($country) === $value
                || $country['name'] === $value
                || $country['calling_code'] === $value
            ) {
                return $country_code;
            }
        }

        return null;
    }

    /**
     * @param array{name: string, calling_code: string} $country
     */
    private function whatsapp_country_label(array $country): string
    {
        return $country['calling_code'] . ' · ' . $country['name'];
    }

    private function normalise_full_phone(string $value): string
    {
        if (preg_match('/[^0-9+().\-\s]/', $value)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || strlen($digits) < 7 || strlen($digits) > 15) {
            return '';
        }

        return '+' . $digits;
    }

    private function resolve_login_identifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if (is_email($identifier)) {
            return $identifier;
        }

        $phone = $this->normalise_full_phone($identifier);
        if ($phone === '') {
            return $identifier;
        }

        $users = get_users([
            'fields'     => 'all',
            'meta_key'   => '_aromamatrix_whatsapp_phone',
            'meta_value' => $phone,
            'number'     => 2,
        ]);

        return count($users) === 1 && $users[0] instanceof \WP_User
            ? $users[0]->user_email
            : $identifier;
    }

    private function save_customer_phone(int $customer_id, string $phone): void
    {
        update_user_meta($customer_id, '_aromamatrix_whatsapp_phone', $phone);
        update_user_meta($customer_id, 'billing_phone', $phone);
    }

    private function sync_customer_identity(int $customer_id): void
    {
        $customer = get_user_by('id', $customer_id);
        if (! $customer instanceof \WP_User) {
            return;
        }

        wp_update_user([
            'ID'           => $customer_id,
            'display_name' => $customer->user_email,
            'nickname'     => $customer->user_email,
        ]);
    }

    private function establish_customer_session(int $customer_id, bool $remember): void
    {
        if (function_exists('wc_set_customer_auth_cookie')) {
            wc_set_customer_auth_cookie($customer_id);
        }

        wp_set_auth_cookie($customer_id, $remember, $this->uses_secure_cookies());

        if (function_exists('WC') && WC()->session) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    private function uses_secure_cookies(): bool
    {
        return is_ssl() || str_starts_with(home_url('/'), 'https://');
    }

    private function registration_is_enabled(): bool
    {
        return 'yes' === get_option('woocommerce_enable_myaccount_registration', 'yes');
    }

    private function verify_request(): void
    {
        if (! check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session has expired. Refresh the page and try again.', 'aromamatrix-plugin')], 403);
        }
    }
}
