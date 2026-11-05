<?php
/**
 * Public Viorine catalogue snapshot prepared for Trovesia migration.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

return [
    [
        'type'              => 'variable',
        'name'              => 'Viorine Advanced Batana Hair Regrowth Therapy',
        'sku'               => 'WBX8FPF',
        'source_handle'     => 'viorine-advanced-batana-hair-regrowth-therapy',
        'source_url'        => 'https://viorine.com/products/viorine-advanced-batana-hair-regrowth-therapy',
        'eyebrow'           => 'Botanical scalp & hair oil · 50 ml',
        'short_description' => 'A lightweight blend of batana, rosemary, castor, jojoba and vitamin E oils for a more nourished scalp and softer-looking hair.',
        'description'       => '<h2>A simple overnight ritual</h2><p>Warm three to five drops between your fingertips, massage gently through the scalp and leave overnight. Use consistently as part of your normal hair-care routine.</p><h2>Ingredient-led care</h2><p>Batana oil conditions dry-feeling hair, while rosemary, castor, jojoba and vitamin E oils support softness, shine and a comfortable scalp-care ritual.</p><p><small>Cosmetic product only. Results vary. Patch test before use and discontinue if irritation occurs.</small></p>',
        'benefits'          => [
            'Conditions dry, brittle-feeling hair',
            'Supports a nourished scalp-care routine',
            'Lightweight overnight application',
            'Suitable for textured and straight hair',
        ],
        'guarantee'         => 'If you are not satisfied within 30 days, contact us for return instructions and a full refund. Return shipping is covered.',
        'shipping'          => 'Orders are processed within 24–72 hours and delivered in 7–12 days. Tracking is emailed when the parcel ships.',
        'variations'        => [
            ['name' => 'Buy 1', 'sku' => 'WBX8FPF-1', 'regular_price' => '71.95', 'sale_price' => '35.95'],
            ['name' => 'Buy 2', 'sku' => 'WBX8FPF-2', 'regular_price' => '143.90', 'sale_price' => '71.95'],
            ['name' => 'Buy 3', 'sku' => 'WBX8FPF-3', 'regular_price' => '215.85', 'sale_price' => '107.90'],
        ],
        'addon_rules'       => [
            [
                'id'               => 'authentic-derma-roller',
                'product_sku'      => '1775139057712537603',
                'title'            => 'Authentic Derma Roller',
                'pricing_mode'     => 'follow',
                'fixed_price'      => '',
                'compare_at_price' => '47.22',
                'discount_percent' => '',
                'quantity'         => 1,
                'default_selected' => false,
                'allowed_values'   => [],
            ],
        ],
        'images'            => [
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260721_204852_ac395412-19d7-4865-bc33-35a3739bbad6.png?v=1784667180',
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260404_212728_8c87c079-e208-42ac-ac97-70104c354ea8.png?v=1775911606',
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260404_212716_a6ccdeaf-99bd-44ae-aa11-32e6d6ce5f18.png?v=1775911606',
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260405_065030_c73302d0-a68c-4a8b-a8f0-b9f69397c934.png?v=1775911606',
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260404_212624_ad8a0b3a-25fc-4d7f-b9a7-030076443cea.png?v=1775911606',
        ],
    ],
    [
        'type'              => 'simple',
        'name'              => 'Viorine Batana Shampoo',
        'sku'               => '7F9IAME',
        'source_handle'     => 'viorine-batana-shampoo',
        'source_url'        => 'https://viorine.com/products/viorine-batana-shampoo',
        'eyebrow'           => 'Daily botanical cleanse · 237 ml',
        'short_description' => 'A batana-oil shampoo designed to cleanse without leaving hair feeling stripped.',
        'description'       => '<h2>Cleanse, without compromise</h2><p>Massage through wet hair and scalp, work into a gentle lather, then rinse thoroughly. Follow with your preferred conditioner or scalp oil.</p><p><small>Cosmetic product only. Patch test before use and discontinue if irritation occurs.</small></p>',
        'benefits'          => [
            'Cleanses scalp and hair gently',
            'Helps hair feel soft and manageable',
            'Suitable for a regular wash routine',
            'Pairs with botanical scalp oils',
        ],
        'guarantee'         => 'If you are not satisfied within 30 days, contact us for return instructions and a full refund. Return shipping is covered.',
        'shipping'          => 'Orders are processed within 24–72 hours and delivered in 7–12 days. Tracking is emailed when the parcel ships.',
        'regular_price'     => '99.99',
        'sale_price'        => '49.95',
        'images'            => [
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260326_132423_3425f155-5dda-41bc-8b1e-dbfd0a16d719.jpg?v=1774531815',
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260326_103342_f261d76d-15ed-48c7-a871-92be1a2d1eff.jpg?v=1774531815',
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260326_131656_e221297b-eaeb-413c-84a6-a9fa1fa9004d.jpg?v=1774531815',
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/hf_20260326_114450_48a0e056-6451-43dd-8932-acb65ea9f9fc.jpg?v=1774531815',
        ],
    ],
    [
        'type'              => 'simple',
        'name'              => 'Authentic Derma Roller',
        'sku'               => '1775139057712537603',
        'source_handle'     => 'authentic-derma-roller',
        'source_url'        => 'https://viorine.com/products/authentic-derma-roller',
        'eyebrow'           => 'Scalp-care tool',
        'short_description' => 'An easy-grip roller designed to complement a considered topical scalp-care routine.',
        'description'       => '<h2>Use with care</h2><p>Sanitise the roller before and after every use. Apply light, even pressure and never share the tool. Do not use on broken, inflamed or irritated skin.</p><p><small>This cosmetic tool is not intended to diagnose, treat or prevent a medical condition. Ask a qualified professional if you are unsure whether it is suitable for you.</small></p>',
        'benefits'          => [
            'Comfortable, easy-grip handle',
            'Complements topical scalp care',
            'Compact and easy to store',
            'Clear cleaning guidance included',
        ],
        'guarantee'         => 'If you are not satisfied within 30 days, contact us for return instructions and a full refund. Return shipping is covered.',
        'shipping'          => 'Orders are processed within 24–72 hours and delivered in 7–12 days. Tracking is emailed when the parcel ships.',
        'regular_price'     => '47.22',
        'sale_price'        => '23.61',
        'images'            => [
            'https://cdn.shopify.com/s/files/1/0720/4100/5248/files/derma_roller.jpg?v=1771631007',
        ],
    ],
];
