<?php
/** Complete the existing Batana add-on selected for the applicator comb. */

if (! defined('ABSPATH') || ! class_exists('WooCommerce')) {
    throw new RuntimeException('WooCommerce must be active.');
}

$comb = wc_get_product(78);
$addon = wc_get_product(17);
$batana = wc_get_product(20);
$bundle = wc_get_product(21);
if (
    ! $comb instanceof WC_Product_Variable
    || ! $addon instanceof WC_Product_Simple
    || ! $batana instanceof WC_Product_Variable
    || ! $bundle instanceof WC_Product_Variation
) {
    throw new RuntimeException('Expected comb and Batana products are missing or changed type.');
}

$sku = 'TV-BATANA-2B-ADDON';
if ($addon->get_sku() !== '' && $addon->get_sku() !== $sku) {
    throw new RuntimeException('The selected add-on has a different SKU; refusing to overwrite it.');
}
if (wc_get_product_id_by_sku($sku) && wc_get_product_id_by_sku($sku) !== $addon->get_id()) {
    throw new RuntimeException('The add-on SKU is already used by another product.');
}

$rules = \Trovesia\Plugin\Addon_Pricing::get_rules($comb->get_id());
$found = false;
foreach ($rules as &$rule) {
    if ((int) $rule['product_id'] === $addon->get_id()) {
        // Empty means the same add-on is offered for both Clear and Pink.
        $rule['allowed_values'] = [];
        $found = true;
    }
}
unset($rule);
if (! $found) {
    throw new RuntimeException('The comb no longer has the selected Batana add-on rule.');
}

$addon->set_name('Advanced Batana Hair Regrowth Therapy — 2 Bottle Bundle');
$addon->set_status('publish');
$addon->set_catalog_visibility('hidden');
$addon->set_sku($sku);
$addon->set_regular_price('71.95');
$addon->set_sale_price('');
$addon->set_image_id($batana->get_image_id());
$addon->set_category_ids($batana->get_category_ids());
$addon->set_short_description('Two bottles of Advanced Batana Hair Regrowth Therapy.');
$addon->set_description('<p>Two bottles of Batana hair oil for your scalp-care routine. Applicator comb sold separately.</p>');
$addon->set_tax_status($bundle->get_tax_status());
$addon->set_shipping_class_id($bundle->get_shipping_class_id());
$addon->set_weight($bundle->get_weight());
$addon->set_manage_stock(false);
$addon->save();

update_post_meta($comb->get_id(), '_trovesia_addon_rules', $rules);
wc_delete_product_transients($comb->get_id());
clean_post_cache($comb->get_id());

echo wp_json_encode([
    'comb_id' => $comb->get_id(),
    'addon_id' => $addon->get_id(),
    'addon_status' => $addon->get_status(),
    'addon_visibility' => $addon->get_catalog_visibility(),
    'addon_purchasable' => $addon->is_purchasable(),
    'allowed_values' => [],
    'fixed_addon_price' => $rules[0]['fixed_price'] ?? null,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
