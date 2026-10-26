<?php

$product = wc_get_product(31);
if (! $product instanceof WC_Product || ! \Tikool\FrameCustomizer\Config::is_enabled(31)) {
    WP_CLI::error('The frame-customizer product is unavailable.');
}

$original_query = $GLOBALS['wp_query'] ?? null;
try {
    $GLOBALS['wp_query'] = new WP_Query(['post_type' => 'product', 'p' => 31]);
    if (! is_product() || get_queried_object_id() !== 31) {
        WP_CLI::error('Product-page test context was not created.');
    }

    $locations = apply_filters('woocommerce_paypal_payments_selected_button_locations', ['product', 'cart', 'checkout', 'mini-cart'], 'locations');
    if (in_array('product', $locations, true) || ! in_array('checkout', $locations, true) || ! in_array('cart', $locations, true)) {
        WP_CLI::error('PayPal product payment was not isolated from cart/checkout payments.');
    }
    if (apply_filters('woocommerce_paypal_payments_product_supports_payment_request_button', true, $product)) {
        WP_CLI::error('Legacy PayPal product button remains available.');
    }
    if (! apply_filters('wc_stripe_hide_payment_request_on_product_page', false, get_post(31))) {
        WP_CLI::error('Stripe product express checkout remains available.');
    }

    $GLOBALS['wp_query'] = new WP_Query(['page_id' => (int) get_option('woocommerce_cart_page_id')]);
    $cart_locations = apply_filters('woocommerce_paypal_payments_selected_button_locations', ['product', 'cart', 'checkout'], 'locations');
    if ($cart_locations !== ['product', 'cart', 'checkout']) {
        WP_CLI::error('PayPal locations changed outside the customized product page.');
    }
} finally {
    $GLOBALS['wp_query'] = $original_query;
}

WP_CLI::success('Product express checkout is suppressed; cart and checkout payment locations remain intact.');
