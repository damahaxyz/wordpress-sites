<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit;
}

$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        WP_CLI::error($message);
    }
};

$products = wc_get_products(['status' => 'publish', 'type' => 'simple', 'limit' => 20]);
$product = null;
$parent_id = 0;
$variation_id = 0;
$variation_attributes = [];
foreach ($products as $candidate) {
    if ($candidate instanceof WC_Product && ! \Tikool\FrameCustomizer\Config::is_enabled($candidate->get_id())) {
        $product = $candidate;
        break;
    }
}
if (! $product instanceof WC_Product) {
    $parent = wc_get_product(31);
    $children = $parent instanceof WC_Product_Variable ? $parent->get_children() : [];
    $variation = $children ? wc_get_product((int) $children[0]) : null;
    if ($variation instanceof WC_Product_Variation) {
        $product = $variation;
        $parent_id = $parent->get_id();
        $variation_id = $variation->get_id();
        $variation_attributes = $variation->get_variation_attributes();
    }
}
$check($product instanceof WC_Product, 'No product is available for the isolated cart test.');

wc_load_cart();
$private_directory = WP_CONTENT_DIR . '/uploads/tikool-customer-photos';
$check(wp_mkdir_p($private_directory), 'Private preview directory unavailable.');
$test_preview = $private_directory . '/' . wp_generate_password(24, false, false) . '-preview.jpg';
$test_framed = $private_directory . '/' . wp_generate_password(24, false, false) . '-cart-preview.jpg';
$check(file_put_contents($test_preview, 'print-only-preview') !== false, 'Could not create a print preview.');
$marker = 'tikool-framed-preview-' . wp_generate_password(16, false, false);
$check(file_put_contents($test_framed, $marker) !== false, 'Could not create a framed preview.');

$cookie_name = '';
$cookie_value = '';
add_filter('woocommerce_set_cookie_options', static function (array $options, string $name, string $value) use (&$cookie_name, &$cookie_value): array {
    if (str_starts_with($name, 'wp_woocommerce_session_')) {
        $cookie_name = $name;
        $cookie_value = $value;
    }
    return $options;
}, 10, 3);

try {
    $key = md5(wp_generate_uuid4());
    WC()->cart->set_cart_contents([$key => [
        'key' => $key,
        'product_id' => $parent_id ?: $product->get_id(),
        'variation_id' => $variation_id,
        'variation' => $variation_attributes,
        'quantity' => 1,
        'data' => $product,
        '_tikool_frame_customization' => ['preview_path' => $test_preview, 'cart_preview_path' => $test_framed],
    ]]);
    $cart_session_property = new ReflectionProperty(WC_Cart::class, 'session');
    $cart_session_property->setAccessible(true);
    $cart_session_property->getValue(WC()->cart)->set_session();
    WC()->session->set_customer_session_cookie(true);
    WC()->session->save_data();
    $check($cookie_name !== '' && $cookie_value !== '', 'WooCommerce did not create a test-session cookie.');
    $session_cart = WC()->session->get('cart', []);
    $check(is_array($session_cart) && isset($session_cart[$key]), 'Test cart was not saved into the WooCommerce session.');

    $url = add_query_arg(
        ['action' => 'tikool_frame_customizer_cart_preview', 'cart_item_key' => $key],
        admin_url('admin-post.php')
    );
    $authorized = wp_remote_get($url, [
        'headers' => ['Cookie' => $cookie_name . '=' . $cookie_value],
        'timeout' => 20,
    ]);
    $check(! is_wp_error($authorized), 'The authorized cart-image request failed.');
    if (wp_remote_retrieve_response_code($authorized) !== 200) {
        WP_CLI::warning('Authorized response: ' . wp_remote_retrieve_response_code($authorized) . ' · ' . substr(trim(wp_strip_all_tags(wp_remote_retrieve_body($authorized))), 0, 160));
    }
    $check(wp_remote_retrieve_response_code($authorized) === 200 && wp_remote_retrieve_body($authorized) === $marker, 'The owner could not load the private preview.');
    $check(str_contains((string) wp_remote_retrieve_header($authorized, 'cache-control'), 'no-store'), 'The private preview was cacheable.');

    $store_cart = wp_remote_get(rest_url('wc/store/v1/cart'), [
        'headers' => ['Cookie' => $cookie_name . '=' . $cookie_value],
        'timeout' => 20,
    ]);
    $check(! is_wp_error($store_cart) && wp_remote_retrieve_response_code($store_cart) === 200, 'The Cart block API request failed.');
    $cart_data = json_decode(wp_remote_retrieve_body($store_cart), true);
    $matching_items = array_values(array_filter((array) ($cart_data['items'] ?? []), static fn ($item) => ($item['key'] ?? '') === $key));
    $check(count($matching_items) === 1 && str_contains((string) ($matching_items[0]['images'][0]['src'] ?? ''), 'tikool_frame_customizer_cart_preview'), 'The Cart block did not receive the custom preview image.');

    $unauthorized = wp_remote_get($url, ['timeout' => 20]);
    $check(! is_wp_error($unauthorized) && wp_remote_retrieve_response_code($unauthorized) === 404, 'The private preview was exposed without the cart session.');
} finally {
    WC()->session->destroy_session();
    unlink($test_preview);
    unlink($test_framed);
}

WP_CLI::success('Classic and Cart block images use the private preview; cart owner access is allowed, anonymous access denied, and responses are not cached.');
