<?php
/**
 * Theme setup and WooCommerce integration.
 *
 * @package MangaShop
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

function manga_shop_setup(): void
{
    load_theme_textdomain('manga-shop', get_template_directory() . '/languages');

    add_theme_support('automatic-feed-links');
    add_theme_support('custom-logo', [
        'height'      => 72,
        'width'       => 260,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    add_theme_support('html5', [
        'caption', 'comment-form', 'comment-list', 'gallery',
        'navigation-widgets', 'search-form', 'script', 'style',
    ]);
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('title-tag');
    add_theme_support('wp-block-styles');
    add_theme_support('align-wide');
    add_theme_support('woocommerce', [
        'thumbnail_image_width' => 720,
        'single_image_width'    => 980,
        'product_grid'          => [
            'default_rows' => 3, 'min_rows' => 1, 'max_rows' => 8,
            'default_columns' => 4, 'min_columns' => 2, 'max_columns' => 4,
        ],
    ]);
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    add_theme_support('wc-product-gallery-zoom');

    add_image_size('manga-product-card', 720, 720, true);
    add_image_size('manga-category-card', 900, 620, true);

    register_nav_menus([
        'primary' => __('Primary menu', 'manga-shop'),
        'footer'  => __('Footer menu', 'manga-shop'),
        'legal'   => __('Legal menu', 'manga-shop'),
    ]);
}
add_action('after_setup_theme', 'manga_shop_setup');

function manga_shop_enqueue_assets(): void
{
    $stylePath = get_stylesheet_directory() . '/style.css';
    $wooPath = get_template_directory() . '/assets/css/woocommerce.css';
    $scriptPath = get_template_directory() . '/assets/js/theme.js';

    wp_enqueue_style(
        'manga-shop',
        get_stylesheet_uri(),
        [],
        is_file($stylePath) ? (string) filemtime($stylePath) : (string) wp_get_theme()->get('Version')
    );

    if (class_exists('WooCommerce') && is_file($wooPath)) {
        wp_enqueue_style(
            'manga-shop-woocommerce',
            get_template_directory_uri() . '/assets/css/woocommerce.css',
            ['manga-shop'],
            (string) filemtime($wooPath)
        );
    }

    if (class_exists('WooCommerce')) {
        wp_enqueue_script('wc-cart-fragments');
    }

    if (is_file($scriptPath)) {
        $scriptDependencies = class_exists('WooCommerce') ? ['jquery', 'wc-cart-fragments'] : [];
        wp_enqueue_script(
            'manga-shop-theme',
            get_template_directory_uri() . '/assets/js/theme.js',
            $scriptDependencies,
            (string) filemtime($scriptPath),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'manga_shop_enqueue_assets');

/**
 * Resolve one migrated media item by its filename slug.
 */
function manga_shop_media_id(string $slug): int
{
    static $cache = [];
    if (isset($cache[$slug])) {
        return $cache[$slug];
    }

    $attachment = get_page_by_path($slug, OBJECT, 'attachment');
    $cache[$slug] = $attachment instanceof WP_Post ? (int) $attachment->ID : 0;
    return $cache[$slug];
}

/**
 * Small inline icon set avoids a blocking icon-font request.
 */
function manga_shop_icon(string $name): string
{
    $icons = [
        'account' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg>',
        'arrow'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
        'bag'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg>',
        'close'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>',
        'filter'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>',
        'menu'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
        'search'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>',
        'spark'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2c.7 5.4 4.6 9.3 10 10-5.4.7-9.3 4.6-10 10-.7-5.4-4.6-9.3-10-10 5.4-.7 9.3-4.6 10-10Z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function manga_shop_brand(): void
{
    if (has_custom_logo()) {
        the_custom_logo();
        return;
    }

    $logoId = manga_shop_media_id('manga-white');
    if ($logoId > 0) {
        echo '<a class="brand-link" href="' . esc_url(home_url('/')) . '" rel="home">';
        echo wp_get_attachment_image($logoId, 'medium', false, [
            'class' => 'brand-logo', 'alt' => get_bloginfo('name'), 'loading' => 'eager',
        ]);
        echo '</a>';
        return;
    }

    echo '<a class="brand-wordmark" href="' . esc_url(home_url('/')) . '" rel="home">Manga<span>Product</span></a>';
}

function manga_shop_fallback_menu(): void
{
    $shopUrl = class_exists('WooCommerce') ? wc_get_page_permalink('shop') : home_url('/#shop');
    $links = [
        home_url('/') => __('Home', 'manga-shop'),
        $shopUrl => __('All products', 'manga-shop'),
        home_url('/product-category/one-piece/') => __('One Piece', 'manga-shop'),
        home_url('/product-category/naruto/') => __('Naruto', 'manga-shop'),
        home_url('/product-category/demon-slayer/') => __('Demon Slayer', 'manga-shop'),
        home_url('/product-category/dragen-ball-d/') => __('Dragon Ball', 'manga-shop'),
    ];
    ?>
    <ul class="primary-menu">
        <?php foreach ($links as $url => $label) : ?>
            <li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php
}

function manga_shop_cart_count(): int
{
    if (! class_exists('WooCommerce') || ! WC()->cart) {
        return 0;
    }
    return WC()->cart->get_cart_contents_count();
}

function manga_shop_cart_fragment(array $fragments): array
{
    ob_start();
    ?><span class="header-cart__count" aria-label="<?php esc_attr_e('Items in cart', 'manga-shop'); ?>"><?php echo esc_html((string) manga_shop_cart_count()); ?></span><?php
    $fragments['.header-cart__count'] = (string) ob_get_clean();
    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'manga_shop_cart_fragment');

function manga_shop_woocommerce_trust_panel(): void
{
    ?>
    <div class="product-assurance" aria-label="<?php esc_attr_e('Purchase benefits', 'manga-shop'); ?>">
        <div><strong><?php esc_html_e('Free shipping', 'manga-shop'); ?></strong><span><?php esc_html_e('On every order', 'manga-shop'); ?></span></div>
        <div><strong><?php esc_html_e('90-day support', 'manga-shop'); ?></strong><span><?php esc_html_e('Helpful after-sale service', 'manga-shop'); ?></span></div>
        <div><strong><?php esc_html_e('Secure checkout', 'manga-shop'); ?></strong><span><?php esc_html_e('Protected PayPal payment', 'manga-shop'); ?></span></div>
    </div>
    <?php
}
add_action('woocommerce_single_product_summary', 'manga_shop_woocommerce_trust_panel', 36);

function manga_shop_product_faq(): void
{
    if (! is_product()) {
        return;
    }
    ?>
    <section class="product-faq" aria-labelledby="product-faq-title">
        <p class="section-kicker"><?php esc_html_e('Good to know', 'manga-shop'); ?></p>
        <h2 id="product-faq-title"><?php esc_html_e('Shopping with confidence', 'manga-shop'); ?></h2>
        <div class="faq-grid">
            <article><h3><?php esc_html_e('Is shipping free?', 'manga-shop'); ?></h3><p><?php esc_html_e('Yes. Every product in our store includes free shipping.', 'manga-shop'); ?></p></article>
            <article><h3><?php esc_html_e('What after-sale support is included?', 'manga-shop'); ?></h3><p><?php esc_html_e('We provide 90-day after-sale service. Contact our support team and include your order number so we can help quickly.', 'manga-shop'); ?></p></article>
            <article><h3><?php esc_html_e('How do I choose a style?', 'manga-shop'); ?></h3><p><?php esc_html_e('When a product has multiple designs, select each available option above before adding it to your cart.', 'manga-shop'); ?></p></article>
        </div>
    </section>
    <?php
}
add_action('woocommerce_after_single_product_summary', 'manga_shop_product_faq', 16);

add_filter('woocommerce_output_related_products_args', static function (array $args): array {
    $args['posts_per_page'] = 4;
    $args['columns'] = 4;
    return $args;
});
add_filter('loop_shop_columns', static fn(): int => 4);
add_filter('woocommerce_show_page_title', '__return_false');

/**
 * Product cards act as the catalogue navigation, so the extra action button is
 * intentionally omitted from shop, category, related and upsell grids.
 */
function manga_shop_remove_loop_action_button(): void
{
    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
}
add_action('init', 'manga_shop_remove_loop_action_button', 20);

/** Whether the current request is a WooCommerce product search. */
function manga_shop_is_product_search(): bool
{
    if (! is_search()) {
        return false;
    }

    $postType = get_query_var('post_type');
    return $postType === 'product' || (is_array($postType) && in_array('product', $postType, true));
}

/** Shop and product search share the same catalogue experience. */
function manga_shop_is_catalogue_view(): bool
{
    return is_shop() || manga_shop_is_product_search();
}

/** Return the catalogue URL while retaining the current product search. */
function manga_shop_catalogue_url(): string
{
    if (! manga_shop_is_product_search()) {
        return wc_get_page_permalink('shop');
    }

    return add_query_arg([
        's'         => get_search_query(),
        'post_type' => 'product',
    ], home_url('/'));
}

/** Group the result count and ordering control into one responsive toolbar. */
function manga_shop_catalogue_toolbar_open(): void
{
    if (manga_shop_is_catalogue_view()) {
        echo '<div class="shop-toolbar">';
    }
}

function manga_shop_catalogue_toolbar_close(): void
{
    if (manga_shop_is_catalogue_view()) {
        echo '</div>';
    }
}
add_action('woocommerce_before_shop_loop', 'manga_shop_catalogue_toolbar_open', 15);
add_action('woocommerce_before_shop_loop', 'manga_shop_catalogue_toolbar_close', 35);

/**
 * Validate and normalize the public Shop filter state.
 *
 * @return array{
 *     categories: array<int, array{slug:string,label:string}>,
 *     tags: array<int, array{slug:string,label:string}>,
 *     min_price: ?float,
 *     max_price: ?float,
 *     on_sale: bool,
 *     in_stock: bool
 * }
 */
function manga_shop_get_filter_state(): array
{
    static $state = null;

    if (is_array($state)) {
        return $state;
    }

    $state = [
        'categories' => [],
        'tags'       => [],
        'min_price'  => null,
        'max_price'  => null,
        'on_sale'    => isset($_GET['manga_sale']) && wc_clean(wp_unslash($_GET['manga_sale'])) === '1',
        'in_stock'   => isset($_GET['manga_stock']) && wc_clean(wp_unslash($_GET['manga_stock'])) === '1',
    ];

    $requestedCategories = isset($_GET['manga_cat']) ? (array) wp_unslash($_GET['manga_cat']) : [];
    foreach (array_unique(array_map('sanitize_title', $requestedCategories)) as $slug) {
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term instanceof WP_Term) {
            $state['categories'][] = ['slug' => $term->slug, 'label' => $term->name];
        }
    }

    $requestedTags = isset($_GET['manga_tag']) ? (array) wp_unslash($_GET['manga_tag']) : [];
    foreach (array_unique(array_map('sanitize_title', $requestedTags)) as $slug) {
        $term = get_term_by('slug', $slug, 'product_tag');
        if ($term instanceof WP_Term) {
            $state['tags'][] = ['slug' => $term->slug, 'label' => $term->name];
        }
    }

    foreach (['min_price' => 'manga_min_price', 'max_price' => 'manga_max_price'] as $key => $requestKey) {
        if (isset($_GET[$requestKey]) && is_scalar($_GET[$requestKey]) && $_GET[$requestKey] !== '') {
            $value = (float) wc_format_decimal(wp_unslash($_GET[$requestKey]));
            $state[$key] = max(0, $value);
        }
    }

    if ($state['min_price'] !== null && $state['max_price'] !== null && $state['min_price'] > $state['max_price']) {
        [$state['min_price'], $state['max_price']] = [$state['max_price'], $state['min_price']];
    }

    return $state;
}

/** @return array{min:int,max:int} */
function manga_shop_catalog_price_range(): array
{
    global $wpdb;

    $lookupTable = $wpdb->wc_product_meta_lookup;
    $row = $wpdb->get_row(
        "SELECT FLOOR(MIN(lookup.min_price)) AS min_price, CEIL(MAX(lookup.max_price)) AS max_price
        FROM {$lookupTable} AS lookup
        INNER JOIN {$wpdb->posts} AS posts ON posts.ID = lookup.product_id
        WHERE posts.post_type = 'product' AND posts.post_status = 'publish' AND lookup.max_price > 0"
    );

    return [
        'min' => isset($row->min_price) ? (int) $row->min_price : 0,
        'max' => isset($row->max_price) ? (int) $row->max_price : 0,
    ];
}

function manga_shop_active_filter_count(): int
{
    $state = manga_shop_get_filter_state();
    $priceActive = $state['min_price'] !== null || $state['max_price'] !== null;

    return count($state['categories']) + count($state['tags']) + (int) $priceActive + (int) $state['on_sale'] + (int) $state['in_stock'];
}

/**
 * Build a Shop URL while optionally removing one or more filter identifiers.
 *
 * @param string[] $excluded e.g. category:one-piece, price, sale, stock.
 */
function manga_shop_filter_url(array $excluded = []): string
{
    $state = manga_shop_get_filter_state();
    $arguments = [];

    foreach ($state['categories'] as $category) {
        if (! in_array('category:' . $category['slug'], $excluded, true)) {
            $arguments['manga_cat'][] = $category['slug'];
        }
    }

    foreach ($state['tags'] as $tag) {
        if (! in_array('tag:' . $tag['slug'], $excluded, true)) {
            $arguments['manga_tag'][] = $tag['slug'];
        }
    }

    if (! in_array('price', $excluded, true)) {
        if ($state['min_price'] !== null) {
            $arguments['manga_min_price'] = $state['min_price'];
        }
        if ($state['max_price'] !== null) {
            $arguments['manga_max_price'] = $state['max_price'];
        }
    }
    if ($state['on_sale'] && ! in_array('sale', $excluded, true)) {
        $arguments['manga_sale'] = '1';
    }
    if ($state['in_stock'] && ! in_array('stock', $excluded, true)) {
        $arguments['manga_stock'] = '1';
    }
    if (isset($_GET['orderby']) && is_scalar($_GET['orderby'])) {
        $arguments['orderby'] = wc_clean(wp_unslash($_GET['orderby']));
    }

    return add_query_arg($arguments, manga_shop_catalogue_url());
}

/** Apply category, tag, price and availability facets to the Shop query. */
add_action('woocommerce_product_query', static function (WP_Query $query): void {
    $postType = $query->get('post_type');
    $isProductSearch = $query->is_search()
        && ($postType === 'product' || (is_array($postType) && in_array('product', $postType, true)));

    if (is_admin() || (! is_shop() && ! $isProductSearch) || ! $query->is_main_query()) {
        return;
    }

    $state = manga_shop_get_filter_state();
    $taxQuery = (array) $query->get('tax_query');
    $metaQuery = (array) $query->get('meta_query');

    if ($state['categories']) {
        $taxQuery[] = [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => wp_list_pluck($state['categories'], 'slug'),
            'operator' => 'IN',
        ];
    }
    if ($state['tags']) {
        $taxQuery[] = [
            'taxonomy' => 'product_tag',
            'field'    => 'slug',
            'terms'    => wp_list_pluck($state['tags'], 'slug'),
            'operator' => 'IN',
        ];
    }
    if ($state['min_price'] !== null) {
        $metaQuery[] = ['key' => '_price', 'value' => $state['min_price'], 'compare' => '>=', 'type' => 'DECIMAL(10,2)'];
    }
    if ($state['max_price'] !== null) {
        $metaQuery[] = ['key' => '_price', 'value' => $state['max_price'], 'compare' => '<=', 'type' => 'DECIMAL(10,2)'];
    }
    if ($state['in_stock']) {
        $metaQuery[] = ['key' => '_stock_status', 'value' => 'instock'];
    }
    if ($state['on_sale']) {
        $saleIds = array_map('intval', wc_get_product_ids_on_sale());
        $currentIds = array_map('intval', (array) $query->get('post__in'));
        $query->set('post__in', $currentIds ? array_values(array_intersect($currentIds, $saleIds)) ?: [0] : ($saleIds ?: [0]));
    }

    if ($taxQuery) {
        $query->set('tax_query', $taxQuery);
    }
    if ($metaQuery) {
        $query->set('meta_query', $metaQuery);
    }
});

function manga_shop_render_active_filters(): void
{
    $state = manga_shop_get_filter_state();
    $currencySymbol = get_woocommerce_currency_symbol();
    $searchTerm = manga_shop_is_product_search() ? trim(get_search_query()) : '';

    if (manga_shop_active_filter_count() < 1 && $searchTerm === '') {
        return;
    }
    ?>
    <div class="shop-active-filters" aria-label="<?php esc_attr_e('Active filters', 'manga-shop'); ?>">
        <span class="shop-active-filters__label"><?php esc_html_e('Filtered by', 'manga-shop'); ?></span>
        <ul>
            <?php if ($searchTerm !== '') : ?>
                <li><a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php echo esc_html(sprintf(__('Search: “%s”', 'manga-shop'), $searchTerm)); ?><span aria-hidden="true">×</span></a></li>
            <?php endif; ?>
            <?php foreach ($state['categories'] as $category) : ?>
                <li><a href="<?php echo esc_url(manga_shop_filter_url(['category:' . $category['slug']])); ?>"><?php echo esc_html($category['label']); ?><span aria-hidden="true">×</span></a></li>
            <?php endforeach; ?>
            <?php foreach ($state['tags'] as $tag) : ?>
                <li><a href="<?php echo esc_url(manga_shop_filter_url(['tag:' . $tag['slug']])); ?>"><?php echo esc_html($tag['label']); ?><span aria-hidden="true">×</span></a></li>
            <?php endforeach; ?>
            <?php if ($state['min_price'] !== null || $state['max_price'] !== null) : ?>
                <li><a href="<?php echo esc_url(manga_shop_filter_url(['price'])); ?>"><?php echo esc_html(sprintf(__('Price: %1$s%2$s–%1$s%3$s', 'manga-shop'), $currencySymbol, $state['min_price'] ?? '0', $state['max_price'] ?? '∞')); ?><span aria-hidden="true">×</span></a></li>
            <?php endif; ?>
            <?php if ($state['on_sale']) : ?><li><a href="<?php echo esc_url(manga_shop_filter_url(['sale'])); ?>"><?php esc_html_e('On sale', 'manga-shop'); ?><span aria-hidden="true">×</span></a></li><?php endif; ?>
            <?php if ($state['in_stock']) : ?><li><a href="<?php echo esc_url(manga_shop_filter_url(['stock'])); ?>"><?php esc_html_e('In stock', 'manga-shop'); ?><span aria-hidden="true">×</span></a></li><?php endif; ?>
        </ul>
        <a class="shop-active-filters__clear" href="<?php echo esc_url($searchTerm !== '' ? wc_get_page_permalink('shop') : manga_shop_catalogue_url()); ?>"><?php esc_html_e('Clear all', 'manga-shop'); ?></a>
    </div>
    <?php
}

add_action('wp', static function (): void {
    if (class_exists('WooCommerce')) {
        remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
        remove_action('woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10);
        remove_action('woocommerce_archive_description', 'woocommerce_product_archive_description', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
    }
});
