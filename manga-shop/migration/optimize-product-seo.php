<?php
/**
 * Apply curated product titles, SEO metadata and concise key-benefit excerpts.
 *
 * Dry run:
 *   wp eval-file /migration/optimize-product-seo.php
 * Apply:
 *   MANGA_APPLY_PRODUCT_SEO=1 wp eval-file /migration/optimize-product-seo.php
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI eval-file.\n");
    exit(1);
}

$titleMap = require __DIR__ . '/product-seo-title-map.php';
$apply = getenv('MANGA_APPLY_PRODUCT_SEO') === '1';
$seenTitles = [];
$changes = [];

/** @return string[] */
function manga_product_key_benefits(WC_Product $product, string $title): array
{
    $typeText = strtolower($title);
    $haystack = strtolower($title . ' ' . wp_strip_all_tags($product->get_description()));

    if (preg_match('/\b(drift bottle|puzzles?)\b/', $typeText)) {
        $benefits = [
            'Display-ready collectible for anime fans',
            'Adds character detail to a desk, shelf or room',
            'A memorable gift for collectors',
            'Designed for hands-on enjoyment or display',
        ];
    } elseif (preg_match('/earbud|earphone|headphone|headset/', $typeText)) {
        $benefits = [
            'Wireless Bluetooth connectivity',
            'Portable design for everyday listening',
            'Anime-inspired character styling',
            'Suitable for commuting, travel or relaxing at home',
        ];
        if (str_contains($haystack, 'noise cancellation') || str_contains($haystack, ' anc ')) {
            $benefits[1] = 'Active noise cancellation for focused listening';
        }
    } elseif (preg_match('/mouse pad/', $typeText)) {
        $benefits = [
            'Smooth surface for work and gaming',
            'Protects and defines your desktop space',
            'One Piece artwork for an anime-inspired setup',
            'Easy upgrade for a desk or gaming station',
        ];
        if (str_contains($haystack, 'led')) {
            $benefits[1] = 'LED lighting adds color to your setup';
        }
    } elseif (preg_match('/\b(keyboard|keycaps?|gamepad|controller)\b/', $typeText)) {
        $benefits = [
            'Designed for a more personalized gaming setup',
            'Practical controls for everyday play',
            'Character-inspired styling for anime fans',
            'A distinctive gift for gamers and collectors',
        ];
    } elseif (preg_match('/cup|mug|bottle|tumbler|thermos/', $typeText)) {
        $benefits = [
            'Reusable drinkware for everyday hydration',
            'Practical for home, work or travel',
            'Character-inspired design for anime fans',
            'A useful gift for collectors and fans',
        ];
        if (preg_match('/insulated|thermos|thermostatic/', $haystack)) {
            $benefits[0] = 'Insulated design helps maintain drink temperature';
        }
    } elseif (preg_match('/night light|table lamp|neon|light-up|illuminated|led character art/', $typeText)) {
        $benefits = [
            'Adds soft ambient light to your space',
            'Works as desk, shelf or bedroom decor',
            'Character-inspired design for anime fans',
            'Combines a practical light with display appeal',
        ];
    } elseif (preg_match('/\b(pens?|stationery|notebook|pencil case|pen holder|bookmarks?)\b/', $typeText)) {
        $benefits = [
            'Practical for school, journaling or office use',
            'Helps keep everyday stationery organized',
            'Anime-inspired details brighten your desk',
            'A useful gift for students and anime fans',
        ];
    } elseif (preg_match('/umbrella/', $typeText)) {
        $benefits = [
            'Everyday coverage for changing weather',
            'Easy to carry between home, work and travel',
            'Anime-inspired design with collectible appeal',
            'A practical accessory for anime fans',
        ];
        if (str_contains($haystack, 'fold')) {
            $benefits[1] = 'Foldable design is easy to carry and store';
        }
    } elseif (preg_match('/\b(bracelets?|necklaces?|rings?|watches|watch|hats?|t-shirts?)\b/', $typeText)) {
        $benefits = [
            'Anime-inspired accent for everyday outfits',
            'Easy way to show your favorite series',
            'A thoughtful gift for anime fans',
            'Display-worthy detail with wearable appeal',
        ];
    } elseif (preg_match('/\b(backpacks?|bags?|pouches|pouch|storage box|phone case|iphone case)\b/', $typeText)) {
        $benefits = [
            'Keeps everyday essentials organized',
            'Practical design for daily use',
            'Character-inspired styling for anime fans',
            'Suitable for school, work or travel',
        ];
        if (preg_match('/\b(phone case|iphone case)\b/', $typeText)) {
            $benefits[0] = 'Helps protect your phone from everyday bumps';
            $benefits[1] = 'Slim design keeps controls and ports accessible';
            $benefits[3] = 'Easy way to personalize your phone';
        }
    } elseif (preg_match('/helmet/', $typeText)) {
        $benefits = [
            'Full-face design with anime-inspired graphics',
            'A distinctive choice for riders and collectors',
            'Character artwork adds a personalized look',
            'Designed as functional fan merchandise',
        ];
    } elseif (preg_match('/phone stand/', $typeText)) {
        $benefits = [
            'Keeps your phone upright and easy to view',
            'Useful for video calls, streaming or desk use',
            'Compact footprint for a tidy workspace',
            'Anime-inspired display piece for fans',
        ];
    } elseif (preg_match('/badminton|racket/', $typeText)) {
        $benefits = [
            'Designed for casual badminton play',
            'Character-inspired styling for anime fans',
            'Easy to bring to parks or recreation spaces',
            'A fun gift for active One Piece fans',
        ];
    } elseif (preg_match('/keychain/', $typeText)) {
        $benefits = [
            'Compact accessory for keys, bags or display',
            'Easy way to carry a favorite character',
            'Anime-inspired collectible detail',
            'A small and practical gift for fans',
        ];
    } elseif (preg_match('/\b(building blocks?|models?|figures?|puzzles?|papercraft|wall art|art display|decor|magnets?|piggy bank|plush|katana|swords?|rubik)\b/', $typeText)) {
        $benefits = [
            'Display-ready collectible for anime fans',
            'Adds character detail to a desk, shelf or room',
            'A memorable gift for collectors',
            'Designed for hands-on enjoyment or display',
        ];
    } elseif (preg_match('/clock/', $typeText)) {
        $benefits = [
            'Clear everyday timekeeping',
            'Functional wall decor for home or office',
            'One Piece design for anime fans',
            'Combines practical use with collectible style',
        ];
    } else {
        $benefits = [
            'Character-inspired design for anime fans',
            'Practical for personal use or display',
            'A memorable gift for collectors',
            'Easy addition to an anime-themed collection',
        ];
    }

    // Add only concrete features that are explicitly present in the product data.
    $featureBenefits = [
        '/\bwireless\b/' => 'Wireless design reduces cable clutter',
        '/\bbluetooth\b/' => 'Bluetooth support makes pairing straightforward',
        '/\b(rgb|led)\b/' => 'Integrated lighting adds visual impact',
        '/\bfoldable\b|\bfolding\b/' => 'Foldable construction makes storage easier',
        '/\bportable\b|\bcompact\b/' => 'Compact design is easy to carry and store',
        '/\blarge[- ]capacity\b/' => 'Large-capacity design supports everyday use',
        '/\bheat[- ]resistant\b/' => 'Heat-resistant construction supports hot-drink use',
        '/\bceramic\b/' => 'Ceramic construction gives it a classic drinkware feel',
        '/\bstainless steel\b/' => 'Stainless-steel construction supports everyday durability',
        '/\bsterling silver\b|\b925 silver\b/' => 'Sterling-silver construction adds a refined finish',
        '/\bsilicone\b/' => 'Flexible silicone construction is comfortable to handle',
        '/\bleather\b/' => 'Leather-look finish gives it a polished appearance',
        '/\bpbt\b/' => 'PBT construction is suited to regular keyboard use',
        '/\bdiy\b|\bself-assembl/' => 'DIY assembly adds a hands-on building experience',
        '/\bblind box\b/' => 'Blind-box format adds a surprise to each unboxing',
    ];
    foreach ($featureBenefits as $pattern => $benefit) {
        if (preg_match($pattern, $haystack)) {
            $benefits[] = $benefit;
        }
    }

    if ($product->is_type('variable')) {
        $optionCount = 0;
        $attributeNames = [];
        foreach ($product->get_variation_attributes() as $attributeName => $options) {
            $optionCount = max($optionCount, count($options));
            $label = wc_attribute_label($attributeName, $product);
            if ($label !== '') {
                $attributeNames[] = strtolower($label);
            }
        }

        $choice = $optionCount > 1
            ? sprintf('Choose from %d available options', $optionCount)
            : 'Select the available option that suits you';
        if (count($attributeNames) === 1 && $optionCount > 1) {
            $choice = sprintf('Choose from %d available %s options', $optionCount, $attributeNames[0]);
        }
        $benefits[] = $choice;
    } else {
        $benefits[] = 'Easy to add to a personal collection or gift';
    }

    return array_values(array_unique($benefits));
}

function manga_product_key_benefits_html(array $benefits): string
{
    $items = implode('', array_map(
        static fn(string $benefit): string => '<li>' . esc_html($benefit) . '</li>',
        $benefits
    ));

    return '<div class="product-key-benefits"><p class="product-key-benefits__title"><strong>Key benefits</strong></p><ul>' . $items . '</ul></div>';
}

function manga_product_meta_description(string $title, array $benefits): string
{
    $description = sprintf(
        'Shop %s. %s. %s. Free shipping and 90-day after-sale support.',
        $title,
        rtrim($benefits[0], '. '),
        rtrim($benefits[1], '. ')
    );

    if (mb_strlen($description) <= 158) {
        return $description;
    }

    $description = sprintf(
        'Shop %s. %s. Free shipping and 90-day after-sale support.',
        $title,
        rtrim($benefits[0], '. ')
    );

    if (mb_strlen($description) <= 158) {
        return $description;
    }

    return rtrim(wp_html_excerpt($description, 157, '…'));
}

foreach ($titleMap as $productId => $newTitle) {
    $product = wc_get_product((int) $productId);
    if (! $product instanceof WC_Product || $product->is_type('variation')) {
        throw new RuntimeException(sprintf('Product %d is missing or is a variation.', $productId));
    }

    $normalizedTitle = trim(preg_replace('/\s+/u', ' ', (string) $newTitle));
    $titleKey = strtolower($normalizedTitle);
    if (isset($seenTitles[$titleKey])) {
        throw new RuntimeException(sprintf('Duplicate optimized title: %s', $normalizedTitle));
    }
    $seenTitles[$titleKey] = true;

    $benefits = manga_product_key_benefits($product, $normalizedTitle);
    $shortDescription = manga_product_key_benefits_html($benefits);
    $seoTitle = mb_strlen($normalizedTitle) <= 43
        ? $normalizedTitle . ' | Manga Product'
        : $normalizedTitle;
    $seoDescription = manga_product_meta_description($normalizedTitle, $benefits);

    $changes[] = [
        'id' => (int) $productId,
        'old_title' => $product->get_name(),
        'new_title' => $normalizedTitle,
        'seo_title' => $seoTitle,
        'seo_description' => $seoDescription,
        'key_benefits' => $benefits,
        'slug' => $product->get_slug(),
    ];

    if (! $apply) {
        continue;
    }

    $result = wp_update_post([
        'ID' => (int) $productId,
        'post_title' => $normalizedTitle,
        'post_excerpt' => $shortDescription,
    ], true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }

    update_post_meta((int) $productId, '_manga_seo_title', $seoTitle);
    update_post_meta((int) $productId, '_manga_seo_description', $seoDescription);
    update_post_meta((int) $productId, 'rank_math_title', $seoTitle);
    update_post_meta((int) $productId, 'rank_math_description', $seoDescription);

    if ($product->is_type('variable')) {
        WC_Product_Variable::sync((int) $productId);
    }
    wc_delete_product_transients((int) $productId);
    clean_post_cache((int) $productId);
}

echo wp_json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'count' => count($changes),
    'changes' => $changes,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
