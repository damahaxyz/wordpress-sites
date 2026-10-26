<?php

$customizer = \Tikool\FrameCustomizer\Customizer::instance();
$future_item = ['_tikool_mug_customization' => ['design' => 'custom']];
$fallback_image = '<img src="https://example.com/mug.jpg" alt="Mug">';
$cart_key = str_repeat('a', 32);
if ($customizer->cart_item_thumbnail($fallback_image, $future_item, $cart_key) !== $fallback_image) {
    WP_CLI::error('Frame customizer replaced another product type’s classic cart image.');
}

$images = [(object) ['id' => 1, 'src' => 'https://example.com/mug.jpg']];
if ($customizer->store_api_cart_item_images($images, $future_item, $cart_key) !== $images) {
    WP_CLI::error('Frame customizer replaced another product type’s block cart image.');
}

$details = [['key' => 'Mug design', 'value' => 'Custom']];
if ($customizer->display_cart_item_data($details, $future_item) !== $details) {
    WP_CLI::error('Frame customizer changed another product type’s cart details.');
}

WP_CLI::success('Frame customizer leaves future mug/rug cart images and customer-facing details untouched.');
