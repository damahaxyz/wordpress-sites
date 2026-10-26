<?php

$customizer = \Tikool\FrameCustomizer\Customizer::instance();
$sanitize = new ReflectionMethod($customizer, 'sanitize_recipe');
$sanitize->setAccessible(true);
$crop = ['x' => 0.1, 'y' => 0.1, 'width' => 0.7, 'height' => 0.7, 'zoom' => 1.2];
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        WP_CLI::error($message);
    }
};

$legacy = $sanitize->invoke($customizer, wp_json_encode($crop), 'Milo');
$check(is_array($legacy) && empty($legacy['nameOverlay']['enabled']), 'Legacy no-text recipe failed.');

$valid = $crop + ['nameOverlay' => [
    'enabled' => true,
    'text' => 'Milo',
    'font' => 'georgia',
    'sizePercent' => 6,
    'positionX' => 0.35,
    'positionY' => 0.75,
    'color' => '#123abc',
    'outlineEnabled' => true,
    'outlineColor' => '#fedcba',
]];
$accepted = $sanitize->invoke($customizer, wp_json_encode($valid), 'Milo');
$check(is_array($accepted) && $accepted['nameOverlay']['text'] === 'Milo', 'Valid printed name was rejected.');
$check($accepted['nameOverlay']['positionX'] === 0.35 && $accepted['nameOverlay']['positionY'] === 0.75, 'Dragged name position was not saved.');
$check($accepted['nameOverlay']['color'] === '#123abc', 'Text color was not saved.');
$check($accepted['nameOverlay']['outlineEnabled'] === true && $accepted['nameOverlay']['outlineColor'] === '#fedcba', 'Outline settings were not saved.');

$legacy_appearance = $valid;
unset($legacy_appearance['nameOverlay']['color'], $legacy_appearance['nameOverlay']['outlineEnabled'], $legacy_appearance['nameOverlay']['outlineColor']);
$legacy_appearance_recipe = $sanitize->invoke($customizer, wp_json_encode($legacy_appearance), 'Milo');
$check($legacy_appearance_recipe['nameOverlay']['color'] === '#2d2522', 'Legacy text color default was not preserved.');
$check($legacy_appearance_recipe['nameOverlay']['outlineEnabled'] === true && $legacy_appearance_recipe['nameOverlay']['outlineColor'] === '#ffffff', 'Legacy outline default was not preserved.');

$oswald = $valid;
$oswald['nameOverlay']['font'] = 'oswald';
$oswald_recipe = $sanitize->invoke($customizer, wp_json_encode($oswald), 'Milo');
$check(is_array($oswald_recipe) && $oswald_recipe['nameOverlay']['font'] === 'oswald', 'Condensed capitals font was rejected.');

foreach (['playfair', 'cormorant', 'montserrat', 'great-vibes'] as $font) {
    $font_recipe = $valid;
    $font_recipe['nameOverlay']['font'] = $font;
    $accepted_font = $sanitize->invoke($customizer, wp_json_encode($font_recipe), 'Milo');
    $check(is_array($accepted_font) && $accepted_font['nameOverlay']['font'] === $font, sprintf('Bundled font %s was rejected.', $font));
}

$legacy_overlay = $valid;
unset($legacy_overlay['nameOverlay']['positionX'], $legacy_overlay['nameOverlay']['positionY']);
$legacy_position = $sanitize->invoke($customizer, wp_json_encode($legacy_overlay), 'Milo');
$check($legacy_position['nameOverlay']['positionX'] === 0.5 && $legacy_position['nameOverlay']['positionY'] === 0.94, 'Previous name placement was not preserved.');

foreach ([
    ['font' => 'invalid'],
    ['sizePercent' => 20],
    ['text' => 'Other pet'],
    ['positionX' => -0.1],
    ['positionY' => 1.1],
    ['color' => 'red'],
    ['outlineColor' => '#xyzxyz'],
    ['outlineEnabled' => 'yes'],
] as $change) {
    $invalid = $valid;
    $invalid['nameOverlay'] = array_merge($invalid['nameOverlay'], $change);
    $check($sanitize->invoke($customizer, wp_json_encode($invalid), 'Milo') === null, 'Unsafe name settings were accepted.');
}

$product = wc_get_product(31);
$check($product instanceof WC_Product && \Tikool\FrameCustomizer\Config::is_enabled(31), 'Test product/customizer unavailable.');
$GLOBALS['product'] = $product;
ob_start();
$customizer->render_editor();
$markup = (string) ob_get_clean();
$check(str_contains($markup, 'data-tikool-fc-name-overlay'), 'Name toggle was not rendered.');
$check(str_contains($markup, '<button class="tikool-fc-placeholder" type="button"'), 'Photo placeholder is not an upload button.');
$options_position = strpos($markup, 'data-tikool-fc-name-options');
$name_position = strpos($markup, 'name="tikool_fc_name"');
$check($options_position !== false && $name_position !== false && $name_position < $options_position, 'Required pet name field is not outside the conditional print options.');
$check(str_contains($markup, 'name="tikool_fc_name" type="text" maxlength="100" autocomplete="off" required'), 'Pet name field is not required by default.');
$check(! str_contains($markup, 'name="tikool_fc_name" type="text" maxlength="100" autocomplete="off" disabled'), 'Pet name field is still disabled behind the print-name toggle.');
$check(str_contains($markup, 'data-tikool-fc-name-font'), 'Font selector was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-name-size'), 'Text-size slider was not rendered.');
$check(str_contains($markup, 'value="oswald"'), 'Condensed capitals font was not rendered.');
$check(str_contains($markup, 'value="playfair"') && str_contains($markup, 'value="great-vibes"'), 'New bundled fonts were not rendered.');
$check(str_contains($markup, 'data-tikool-fc-name-color'), 'Text color control was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-name-outline'), 'Outline toggle was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-name-outline-color'), 'Outline color control was not rendered.');
$check(str_contains($markup, 'data-tikool-fc-cart-preview-file'), 'Framed cart preview upload was not rendered.');
$check(! str_contains($markup, 'tikool-fc-nfc-notice'), 'NFC notice was rendered for a non-NFC product.');

$nfc_product = wc_get_product(138);
$check($nfc_product instanceof WC_Product && get_post_meta(138, '_tikool_memory_page_enabled', true) === 'yes', 'NFC test product unavailable.');
$GLOBALS['product'] = $nfc_product;
ob_start();
$customizer->render_editor();
$nfc_markup = (string) ob_get_clean();
$check(str_contains($nfc_markup, 'tikool-fc-nfc-notice'), 'NFC payment/upload notice was not rendered.');
$check(str_contains($nfc_markup, 'photos, videos, and audio') && str_contains($nfc_markup, 'update it later'), 'NFC notice is missing the upload or later-edit guidance.');
$GLOBALS['product'] = $product;

$private_directory = WP_CONTENT_DIR . '/uploads/tikool-customer-photos';
$check(wp_mkdir_p($private_directory), 'Private preview directory unavailable.');
$test_preview = $private_directory . '/' . wp_generate_password(24, false, false) . '-preview.jpg';
$check(file_put_contents($test_preview, 'test preview') !== false, 'Could not create a temporary private preview.');
try {
    $cart_item = [
        'data' => $product,
        '_tikool_frame_customization' => ['preview_path' => $test_preview],
    ];
    $cart_key = str_repeat('a', 32);
    $thumbnail = $customizer->cart_item_thumbnail('product thumbnail', $cart_item, $cart_key);
    $check(str_contains($thumbnail, 'tikool_frame_customizer_cart_preview') && ! str_contains($thumbnail, $test_preview), 'Classic cart thumbnail did not use the private preview route.');
    $images = $customizer->store_api_cart_item_images([], $cart_item, $cart_key);
    $check(count($images) === 1 && str_contains($images[0]->src, 'tikool_frame_customizer_cart_preview'), 'Cart block image did not use the private preview route.');
} finally {
    unlink($test_preview);
}

WP_CLI::success('Verified draggable name, bundled fonts, text/outline colors, legacy recipes, cart images, and server-side validation.');
