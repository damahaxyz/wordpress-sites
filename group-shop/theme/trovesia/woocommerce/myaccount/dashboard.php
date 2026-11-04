<?php
/**
 * Branded account dashboard; endpoint data and actions remain native WooCommerce.
 *
 * @package TrovesiaShop
 * @version 4.4.0
 */

defined('ABSPATH') || exit;

$menu = wc_get_account_menu_items();
$descriptions = [
    'orders'          => __('Follow your orders and view purchase details.', 'trovesia'),
    'downloads'       => __('Find files available with your purchases.', 'trovesia'),
    'edit-address'    => __('Keep your shipping and billing details up to date.', 'trovesia'),
    'payment-methods' => __('Manage your saved payment methods.', 'trovesia'),
    'edit-account'    => __('Update your profile, email and password.', 'trovesia'),
];
?>
<section class="trovesia-account-welcome" aria-labelledby="trovesia-welcome-title">
    <span class="trovesia-account-eyebrow"><?php esc_html_e('Made for you', 'trovesia'); ?></span>
    <h2 id="trovesia-welcome-title"><?php printf(esc_html__('Welcome back, %s.', 'trovesia'), esc_html($current_user->first_name ?: $current_user->display_name)); ?></h2>
    <p><?php esc_html_e('Make yourself at home. Everything you need to manage your Trovesia experience is right here.', 'trovesia'); ?></p>
</section>

<div class="trovesia-account-shortcuts">
    <?php foreach ($descriptions as $endpoint => $description) : ?>
        <?php if (! isset($menu[$endpoint])) { continue; } ?>
        <a class="trovesia-account-shortcut" href="<?php echo esc_url(wc_get_account_endpoint_url($endpoint)); ?>">
            <span class="trovesia-account-shortcut__arrow" aria-hidden="true">↗</span>
            <h3><?php echo esc_html($menu[$endpoint]); ?></h3>
            <p><?php echo esc_html($description); ?></p>
        </a>
    <?php endforeach; ?>
</div>
<div class="trovesia-account-help">
    <div><strong><?php esc_html_e('Need a hand?', 'trovesia'); ?></strong><p><?php esc_html_e('We are here for product questions, deliveries and returns.', 'trovesia'); ?></p></div>
    <a href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Contact us', 'trovesia'); ?> <span aria-hidden="true">↗</span></a>
</div>
<?php
do_action('woocommerce_account_dashboard');
do_action('woocommerce_before_my_account');
do_action('woocommerce_after_my_account');
