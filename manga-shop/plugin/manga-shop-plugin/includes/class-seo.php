<?php
/**
 * Search and generative-engine discoverability.
 *
 * @package MangaShopPlugin
 */

declare(strict_types=1);

namespace MangaShop\Plugin;

use WC_Product;
use WP_Post;
use WP_Term;

if (! defined('ABSPATH')) {
    exit;
}

final class SEO
{
    public function register(): void
    {
        add_filter('wp_robots', [$this, 'robots']);
        add_action('wp', [$this, 'disable_native_product_schema'], 20);
        add_action('template_redirect', [$this, 'serve_llms_txt'], -100);
        add_action('wp_head', [$this, 'fallback_metadata'], 2);
        add_action('wp_head', [$this, 'fallback_rank_math_schema'], 98);
        add_action('wp_head', [$this, 'product_schema'], 98);
        add_action('wp_head', [$this, 'product_faq_schema'], 99);
        add_filter('rank_math/frontend/title', [$this, 'rank_math_title'], 99);
        add_filter('rank_math/frontend/description', [$this, 'rank_math_description'], 99);
        add_filter('pre_get_document_title', [$this, 'rank_math_title'], 99);
        add_filter('rank_math/json_ld', [$this, 'rank_math_schema'], 98, 2);
        add_filter('woocommerce_structured_data_product', [$this, 'woocommerce_product_schema'], 20, 2);
    }

    public function robots(array $robots): array
    {
        if (isset($_GET['add-to-cart'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $robots['noindex'] = true;
            $robots['follow'] = true;
            unset($robots['index'], $robots['nofollow']);
            return $robots;
        }

        if (! is_search() && ! is_404()) {
            $robots['max-image-preview'] = 'large';
            $robots['max-snippet'] = '-1';
            $robots['max-video-preview'] = '-1';
        }
        return $robots;
    }

    public function rank_math_description(mixed $description): string
    {
        $productDescription = $this->product_seo_meta('_manga_seo_description');
        if ($productDescription !== '') {
            return $productDescription;
        }

        $description = is_string($description) ? $description : '';
        return trim($description) !== '' ? $description : $this->context_description();
    }

    public function rank_math_title(mixed $title): string
    {
        $productTitle = $this->product_seo_meta('_manga_seo_title');
        if ($productTitle !== '') {
            return $productTitle;
        }

        if (is_front_page()) {
            return __('Manga Product | Anime Collectibles, Accessories & Gifts', 'manga-shop-plugin');
        }

        if (is_tax('product_cat')) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                return sprintf(
                    /* translators: %s: product collection name. */
                    __('%s Anime Products & Gifts | Manga Product', 'manga-shop-plugin'),
                    $term->name
                );
            }
        }

        return is_string($title) ? $title : '';
    }

    /**
     * Add stable business facts to Rank Math's graph without duplicating it.
     *
     * @param array<string, array<string, mixed>> $data
     * @return array<string, array<string, mixed>>
     */
    public function rank_math_schema(array $data, mixed $jsonLd = null): array
    {
        $organizationFound = false;
        foreach ($data as &$entity) {
            if (! is_array($entity)) {
                continue;
            }

            $types = (array) ($entity['@type'] ?? []);
            if (in_array('Organization', $types, true) || in_array('OnlineStore', $types, true)) {
                $organizationFound = true;
                $entity = array_merge($this->organization_schema(), $entity);
            }

            if (in_array('WebSite', $types, true) && empty($entity['description'])) {
                $entity['description'] = $this->settings()['organization_description'];
            }

            if (in_array('Product', $types, true) || in_array('ProductGroup', $types, true)) {
                $this->enhance_product_entity($entity);
            }
        }
        unset($entity);

        if (! $organizationFound) {
            $data['manga-organization'] = $this->organization_schema();
        }

        return $data;
    }

    /**
     * Enhance WooCommerce's native Product schema when Rank Math is unavailable.
     */
    public function woocommerce_product_schema(array $markup, WC_Product $product): array
    {
        if ($product->is_type('variable')) {
            $markup = $this->variable_product_schema($markup, $product);
        }

        if (empty($markup['category'])) {
            $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
            if (is_array($categories) && $categories !== []) {
                $markup['category'] = implode(', ', array_map('strval', $categories));
            }
        }

        $markup['mainEntityOfPage'] = get_permalink($product->get_id());
        return $markup;
    }

    /**
     * Rank Math owns normal metadata when active; this is a complete fallback.
     */
    public function fallback_metadata(): void
    {
        if (did_action('rank_math/head') > 0 || is_admin() || is_feed() || is_robots() || is_search() || is_404()) {
            return;
        }

        $description = $this->context_description();
        $canonical = $this->context_canonical();
        $image = $this->context_image();
        $type = is_singular('product') ? 'product' : 'website';
        ?>
        <meta name="description" content="<?php echo esc_attr($description); ?>">
        <?php if (is_string($canonical) && $canonical !== '' && (! is_singular() || ! has_action('wp_head', 'rel_canonical'))) : ?><link rel="canonical" href="<?php echo esc_url($canonical); ?>"><?php endif; ?>
        <meta property="og:type" content="<?php echo esc_attr($type); ?>">
        <meta property="og:title" content="<?php echo esc_attr(wp_get_document_title()); ?>">
        <meta property="og:description" content="<?php echo esc_attr($description); ?>">
        <meta property="og:url" content="<?php echo esc_url((string) $canonical); ?>">
        <meta property="og:site_name" content="<?php echo esc_attr($this->settings()['organization_name']); ?>">
        <?php if ($image !== '') : ?><meta property="og:image" content="<?php echo esc_url($image); ?>"><?php endif; ?>
        <meta name="twitter:card" content="summary_large_image">
        <script type="application/ld+json"><?php echo wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                $this->organization_schema(),
                [
                    '@type' => 'WebSite',
                    '@id' => home_url('/#website'),
                    'url' => home_url('/'),
                    'name' => $this->settings()['organization_name'],
                    'description' => $this->settings()['organization_description'],
                    'publisher' => ['@id' => home_url('/#organization')],
                    'inLanguage' => get_bloginfo('language'),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
        <?php
    }

    /**
     * Rank Math can manage titles while its Schema module is disabled. In that
     * configuration we still publish one canonical store/website graph on home.
     */
    public function fallback_rank_math_schema(): void
    {
        if (! is_front_page() || did_action('rank_math/head') === 0 || $this->rank_math_schema_active()) {
            return;
        }

        echo '<script type="application/ld+json">' . wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                $this->organization_schema(),
                [
                    '@type' => 'WebSite',
                    '@id' => home_url('/#website'),
                    'url' => home_url('/'),
                    'name' => $this->settings()['organization_name'],
                    'description' => $this->settings()['organization_description'],
                    'publisher' => ['@id' => home_url('/#organization')],
                    'inLanguage' => get_bloginfo('language'),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    public function product_faq_schema(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => get_permalink() . '#shopping-faq',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => __('Is shipping free?', 'manga-shop-plugin'),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => __('Yes. Every product in our store includes free shipping.', 'manga-shop-plugin'),
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => __('What after-sale support is included?', 'manga-shop-plugin'),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => __('Manga Product provides 90-day after-sale service. Include your order number when contacting support.', 'manga-shop-plugin'),
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => __('How do I choose a product style?', 'manga-shop-plugin'),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => __('For products with multiple designs, select every required option on the product page before adding the item to your cart.', 'manga-shop-plugin'),
                    ],
                ],
            ],
        ];
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    /**
     * Publish one deterministic Product/ProductGroup graph. WooCommerce's
     * deferred footer graph is disabled below to prevent duplicate products.
     */
    public function product_schema(): void
    {
        if (! function_exists('is_product') || ! is_product() || ! function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product(get_queried_object_id());
        if (! $product instanceof WC_Product) {
            return;
        }

        $permalink = get_permalink($product->get_id());
        $description = (string) get_post_meta($product->get_id(), '_manga_seo_description', true);
        if ($description === '') {
            $description = wp_trim_words(
                wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                28,
                '…'
            );
        }

        $images = [];
        foreach (array_unique(array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids()))) as $imageId) {
            $image = wp_get_attachment_image_url((int) $imageId, 'full');
            if (is_string($image) && $image !== '') {
                $images[] = $image;
            }
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $permalink . '#product',
            'name' => $product->get_name(),
            'url' => $permalink,
            'description' => $description,
            'mainEntityOfPage' => $permalink,
        ];
        if ($images !== []) {
            $schema['image'] = $images;
        }
        if ($product->get_sku() !== '') {
            $schema['sku'] = $product->get_sku();
        }

        $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        if (is_array($categories) && $categories !== []) {
            $schema['category'] = implode(', ', array_map('strval', $categories));
        }

        if ($product->get_rating_count() > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $product->get_average_rating(),
                'reviewCount' => $product->get_rating_count(),
            ];
        }

        if ($product->is_type('variable')) {
            $schema = $this->variable_product_schema($schema, $product);
        } elseif ($product->get_price() !== '') {
            $schema['offers'] = [
                '@type' => 'Offer',
                'url' => $permalink,
                'priceCurrency' => get_woocommerce_currency(),
                'price' => wc_format_decimal($product->get_price(), wc_get_price_decimals()),
                'availability' => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => ['@id' => home_url('/#organization')],
            ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    public function disable_native_product_schema(): void
    {
        if (! function_exists('is_product') || ! is_product() || ! function_exists('WC')) {
            return;
        }

        $woocommerce = WC();
        if (isset($woocommerce->structured_data)) {
            remove_action(
                'woocommerce_single_product_summary',
                [$woocommerce->structured_data, 'generate_product_data'],
                60
            );
        }
    }

    /**
     * Serve a concise catalog guide for AI retrieval systems. This deliberately
     * takes precedence over third-party llms.txt output so it remains portable.
     */
    public function serve_llms_txt(): void
    {
        if (is_admin() || ! isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        $path = (string) wp_parse_url(wp_unslash((string) $_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (trim($path, '/') !== 'llms.txt') {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (! in_array($method, ['GET', 'HEAD'], true)) {
            status_header(405);
            header('Allow: GET, HEAD');
            exit;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('X-Robots-Tag: noindex, follow', true);

        if ($method === 'GET') {
            echo $this->llms_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        exit;
    }

    private function llms_content(): string
    {
        $settings = $this->settings();
        $lines = [
            '# ' . $this->markdown_text($settings['organization_name']),
            '',
            '> ' . $this->markdown_text($settings['organization_description']),
            '',
            'Manga Product is an online store for anime-inspired accessories, collectibles and gifts. Every store item includes free shipping and 90-day after-sale service.',
            '',
            '## Primary pages',
            '- [Home](' . home_url('/') . '): Brand overview, featured series and current products.',
        ];

        if (function_exists('wc_get_page_permalink')) {
            $lines[] = '- [All products](' . wc_get_page_permalink('shop') . '): Complete product catalog.';
        }
        $lines[] = '- [Customer account](' . home_url('/my-account/') . '): Order history and customer account tools.';
        $lines[] = '';
        $lines[] = '## Product collections';

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'number' => 30,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (! $term instanceof WP_Term || $term->slug === 'uncategorized') {
                    continue;
                }
                $lines[] = sprintf(
                    '- [%s](%s): %s products.',
                    $this->markdown_text($term->name),
                    get_term_link($term),
                    number_format_i18n($term->count)
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Recently added products';
        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 30,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        foreach ($products as $productPost) {
            if (! $productPost instanceof WP_Post) {
                continue;
            }
            $excerpt = get_the_excerpt($productPost);
            $suffix = $excerpt !== '' ? ': ' . $this->markdown_text(wp_trim_words($excerpt, 24)) : '';
            $lines[] = '- [' . $this->markdown_text(get_the_title($productPost)) . '](' . get_permalink($productPost) . ')' . $suffix;
        }

        $lines[] = '';
        $lines[] = '## Store policies and support';
        foreach ([
            'shipping-information' => 'Shipping information',
            'return-refund-policy' => 'Return and refund policy',
            'privacy-policy' => 'Privacy policy',
            'terms-and-conditions' => 'Terms and conditions',
        ] as $slug => $label) {
            $page = get_page_by_path($slug);
            if ($page instanceof WP_Post && $page->post_status === 'publish') {
                $lines[] = '- [' . $label . '](' . get_permalink($page) . ')';
            }
        }
        $lines[] = '- Support email: ' . $settings['support_email'];
        $lines[] = '';
        $lines[] = '## Structured discovery';
        $lines[] = '- [XML sitemap](' . home_url('/wp-sitemap.xml') . ')';
        $lines[] = '- Product pages expose price, availability, variants, images and reviews through structured data.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Represent variable products as a ProductGroup so search engines can
     * understand the purchasable variants and their real stock state.
     *
     * @param array<string, mixed> $markup
     * @return array<string, mixed>
     */
    private function variable_product_schema(array $markup, WC_Product $product): array
    {
        $groupId = get_permalink($product->get_id()) . '#product-group';
        $variesBy = [];
        foreach (array_keys($product->get_variation_attributes()) as $attributeName) {
            $variesBy[] = 'https://schema.org/' . $this->schema_variant_property((string) $attributeName);
        }

        $variants = [];
        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if (! $variation instanceof WC_Product || ! $variation->is_type('variation')) {
                continue;
            }

            $query = [];
            $variantProperties = [];
            $labelParts = [];
            foreach ($variation->get_variation_attributes() as $attributeName => $attributeValue) {
                if ($attributeValue === '') {
                    continue;
                }

                $query[$attributeName] = $attributeValue;
                $taxonomy = str_replace('attribute_', '', (string) $attributeName);
                $label = wc_attribute_label($taxonomy, $product);
                $displayValue = (string) $attributeValue;
                if (taxonomy_exists($taxonomy)) {
                    $term = get_term_by('slug', (string) $attributeValue, $taxonomy);
                    if ($term instanceof WP_Term) {
                        $displayValue = $term->name;
                    }
                }
                $labelParts[] = $label !== '' ? $label . ': ' . $displayValue : $displayValue;
                $variantProperties[$this->schema_variant_property($taxonomy)] = $displayValue;
            }

            $variantUrl = add_query_arg($query, get_permalink($product->get_id()));
            $variantName = $product->get_name();
            if ($labelParts !== []) {
                $variantName .= ' — ' . implode(', ', $labelParts);
            }

            $variant = [
                '@type' => 'Product',
                '@id' => $variantUrl . '#product',
                'name' => $variantName,
                'url' => $variantUrl,
                'isVariantOf' => ['@id' => $groupId],
            ];
            if ($variation->get_sku() !== '') {
                $variant['sku'] = $variation->get_sku();
            }

            $imageId = $variation->get_image_id() ?: $product->get_image_id();
            $image = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'full') : false;
            if (is_string($image) && $image !== '') {
                $variant['image'] = [$image];
            }

            foreach ($variantProperties as $property => $value) {
                $variant[$property] = $value;
            }

            if ($variation->get_price() !== '') {
                $variant['offers'] = [
                    '@type' => 'Offer',
                    'url' => $variantUrl,
                    'priceCurrency' => get_woocommerce_currency(),
                    'price' => wc_format_decimal($variation->get_price(), wc_get_price_decimals()),
                    'availability' => $variation->is_in_stock()
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => ['@id' => home_url('/#organization')],
                ];
            }
            $variants[] = $variant;
        }

        $group = $markup;
        $group['@type'] = 'ProductGroup';
        $group['@id'] = $groupId;
        $group['productGroupID'] = (string) $product->get_id();
        $group['variesBy'] = array_values(array_unique($variesBy));
        $group['hasVariant'] = $variants;
        unset($group['offers'], $group['sku'], $group['mpn'], $group['gtin']);

        $seoDescription = (string) get_post_meta($product->get_id(), '_manga_seo_description', true);
        if ($seoDescription !== '') {
            $group['description'] = $seoDescription;
        }

        return $group;
    }

    private function schema_variant_property(string $attributeName): string
    {
        $attributeName = strtolower(str_replace(['attribute_', 'pa_'], '', $attributeName));
        if (str_contains($attributeName, 'color') || str_contains($attributeName, 'colour')) {
            return 'color';
        }
        if (str_contains($attributeName, 'size')) {
            return 'size';
        }
        if (str_contains($attributeName, 'material')) {
            return 'material';
        }
        return 'pattern';
    }

    /** @param array<string, mixed> $entity */
    private function enhance_product_entity(array &$entity): void
    {
        if (! is_singular('product')) {
            return;
        }

        $categories = wp_get_post_terms(get_queried_object_id(), 'product_cat', ['fields' => 'names']);
        if (is_array($categories) && $categories !== [] && empty($entity['category'])) {
            $entity['category'] = implode(', ', array_map('strval', $categories));
        }
        if (empty($entity['mainEntityOfPage'])) {
            $entity['mainEntityOfPage'] = get_permalink(get_queried_object_id());
        }
    }

    /** @return array<string, mixed> */
    private function organization_schema(): array
    {
        $settings = $this->settings();
        $schema = [
            '@type' => ['Organization', 'OnlineStore'],
            '@id' => home_url('/#organization'),
            'name' => $settings['organization_name'],
            'url' => home_url('/'),
            'description' => $settings['organization_description'],
            'email' => $settings['support_email'],
            'sameAs' => [
                'https://twitter.com/mangafunshop1',
                'https://facebook.com/mangafunshop/',
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'email' => $settings['support_email'],
                'availableLanguage' => ['English'],
            ],
        ];

        $logo = $this->logo_url();
        if ($logo !== '') {
            $schema['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }
        return $schema;
    }

    private function context_description(): string
    {
        if (is_singular('product') && function_exists('wc_get_product')) {
            $seoDescription = $this->product_seo_meta('_manga_seo_description');
            if ($seoDescription !== '') {
                return $seoDescription;
            }

            $product = wc_get_product(get_queried_object_id());
            if ($product instanceof WC_Product) {
                $description = $product->get_short_description() ?: $product->get_description();
                if ($description !== '') {
                    return wp_trim_words(wp_strip_all_tags(strip_shortcodes($description)), 28, '…');
                }
            }
        }

        if (is_tax('product_cat')) {
            $description = term_description();
            if ($description !== '') {
                return wp_trim_words(wp_strip_all_tags($description), 28, '…');
            }
        }

        if (is_singular()) {
            $post = get_queried_object();
            if ($post instanceof WP_Post) {
                $description = has_excerpt($post) ? get_the_excerpt($post) : $post->post_content;
                if ($description !== '') {
                    return wp_trim_words(wp_strip_all_tags(strip_shortcodes($description)), 28, '…');
                }
            }
        }

        return $this->settings()['organization_description'];
    }

    private function product_seo_meta(string $key): string
    {
        if (! is_singular('product')) {
            return '';
        }

        return trim((string) get_post_meta(get_queried_object_id(), $key, true));
    }

    private function context_image(): string
    {
        if (is_singular() && has_post_thumbnail(get_queried_object_id())) {
            return (string) get_the_post_thumbnail_url(get_queried_object_id(), 'large');
        }
        return $this->logo_url();
    }

    private function context_canonical(): string
    {
        if (is_front_page()) {
            return home_url('/');
        }

        if (is_singular()) {
            return (string) wp_get_canonical_url();
        }

        if (function_exists('is_shop') && is_shop() && function_exists('wc_get_page_permalink')) {
            return wc_get_page_permalink('shop');
        }

        if (is_tax() || is_category() || is_tag()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $url = get_term_link($term);
                return is_wp_error($url) ? '' : (string) $url;
            }
        }

        return (string) get_pagenum_link(max(1, (int) get_query_var('paged')));
    }

    private function logo_url(): string
    {
        $logoId = (int) get_theme_mod('custom_logo');
        if ($logoId <= 0) {
            $logo = get_page_by_path('manga-white', OBJECT, 'attachment');
            $logoId = $logo instanceof WP_Post ? (int) $logo->ID : 0;
        }
        return $logoId > 0 ? (string) wp_get_attachment_image_url($logoId, 'full') : '';
    }

    private function rank_math_schema_active(): bool
    {
        return class_exists('RankMath\\Helper')
            && method_exists('RankMath\\Helper', 'is_module_active')
            && \RankMath\Helper::is_module_active('schema');
    }

    /** @return array{source_url:string,organization_name:string,organization_description:string,support_email:string} */
    private function settings(): array
    {
        $settings = get_option(Settings::OPTION_NAME, Settings::defaults());
        return wp_parse_args(is_array($settings) ? $settings : [], Settings::defaults());
    }

    private function markdown_text(string $text): string
    {
        return trim(str_replace(['[', ']', "\r", "\n"], ['', '', ' ', ' '], wp_strip_all_tags($text)));
    }
}
