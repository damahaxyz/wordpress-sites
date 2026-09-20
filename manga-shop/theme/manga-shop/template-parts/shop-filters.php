<?php
/**
 * Product filtering panel for the Shop archive.
 *
 * @package MangaShop
 */

declare(strict_types=1);

$catalogueUrl = manga_shop_catalogue_url();
$formAction = manga_shop_is_product_search() ? home_url('/') : wc_get_page_permalink('shop');
$state = manga_shop_get_filter_state();
$priceRange = manga_shop_catalog_price_range();
$selectedCategories = wp_list_pluck($state['categories'], 'slug');
$selectedTags = wp_list_pluck($state['tags'], 'slug');
$categories = get_terms([
    'taxonomy'   => 'product_cat',
    'hide_empty' => true,
    'orderby'    => 'count',
    'order'      => 'DESC',
]);
$tags = get_terms([
    'taxonomy'   => 'product_tag',
    'hide_empty' => true,
    'orderby'    => 'count',
    'order'      => 'DESC',
]);
$activeCount = manga_shop_active_filter_count();
?>
<button class="shop-filter-toggle" type="button" aria-expanded="false" aria-controls="shop-filter-panel" data-shop-filter-open>
    <span class="shop-filter-toggle__icon" aria-hidden="true"><?php echo manga_shop_icon('filter'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
    <?php esc_html_e('Filters', 'manga-shop'); ?>
    <?php if ($activeCount > 0) : ?><strong><?php echo esc_html((string) $activeCount); ?></strong><?php endif; ?>
</button>
<div class="shop-filter-backdrop" data-shop-filter-close></div>
<aside class="shop-filter-panel" id="shop-filter-panel" aria-label="<?php esc_attr_e('Product filters', 'manga-shop'); ?>">
    <div class="shop-filter-panel__header">
        <div>
            <span><?php esc_html_e('Find your favorite', 'manga-shop'); ?></span>
            <h2><?php esc_html_e('Filter products', 'manga-shop'); ?></h2>
        </div>
        <button type="button" aria-label="<?php esc_attr_e('Close filters', 'manga-shop'); ?>" data-shop-filter-close>×</button>
    </div>

    <form class="shop-filter-form" action="<?php echo esc_url($formAction); ?>" method="get">
        <div class="shop-filter-form__body">
            <?php if (manga_shop_is_product_search()) : ?>
                <input type="hidden" name="s" value="<?php echo esc_attr(get_search_query()); ?>">
                <input type="hidden" name="post_type" value="product">
            <?php endif; ?>
            <?php if (isset($_GET['orderby']) && is_scalar($_GET['orderby'])) : ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr(wc_clean(wp_unslash($_GET['orderby']))); ?>">
            <?php endif; ?>

        <?php if (is_array($categories) && $categories) : ?>
            <details class="shop-filter-group" open>
                <summary><?php esc_html_e('Series', 'manga-shop'); ?></summary>
                <div class="shop-filter-options">
                    <?php foreach ($categories as $category) : ?>
                        <?php if (! $category instanceof WP_Term || $category->slug === 'uncategorized') { continue; } ?>
                        <label>
                            <input type="checkbox" name="manga_cat[]" value="<?php echo esc_attr($category->slug); ?>" <?php checked(in_array($category->slug, $selectedCategories, true)); ?>>
                            <span><?php echo esc_html($category->name); ?></span>
                            <small><?php echo esc_html((string) $category->count); ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <details class="shop-filter-group" open>
            <summary><?php esc_html_e('Price range', 'manga-shop'); ?></summary>
            <div class="shop-price-filter">
                <label><span><?php esc_html_e('Min', 'manga-shop'); ?></span><span class="shop-price-input"><b>$</b><input type="number" min="0" step="1" name="manga_min_price" value="<?php echo esc_attr($state['min_price'] !== null ? (string) $state['min_price'] : ''); ?>" placeholder="<?php echo esc_attr((string) $priceRange['min']); ?>"></span></label>
                <span aria-hidden="true">—</span>
                <label><span><?php esc_html_e('Max', 'manga-shop'); ?></span><span class="shop-price-input"><b>$</b><input type="number" min="0" step="1" name="manga_max_price" value="<?php echo esc_attr($state['max_price'] !== null ? (string) $state['max_price'] : ''); ?>" placeholder="<?php echo esc_attr((string) $priceRange['max']); ?>"></span></label>
            </div>
        </details>

        <details class="shop-filter-group" open>
            <summary><?php esc_html_e('Availability', 'manga-shop'); ?></summary>
            <div class="shop-filter-options">
                <label><input type="checkbox" name="manga_stock" value="1" <?php checked($state['in_stock']); ?>><span><?php esc_html_e('In stock', 'manga-shop'); ?></span></label>
                <label><input type="checkbox" name="manga_sale" value="1" <?php checked($state['on_sale']); ?>><span><?php esc_html_e('On sale', 'manga-shop'); ?></span></label>
            </div>
        </details>

            <?php if (is_array($tags) && $tags) : ?>
                <details class="shop-filter-group">
                    <summary><?php esc_html_e('Tags', 'manga-shop'); ?></summary>
                    <div class="shop-filter-options">
                        <?php foreach ($tags as $tag) : ?>
                            <?php if (! $tag instanceof WP_Term) { continue; } ?>
                            <label>
                                <input type="checkbox" name="manga_tag[]" value="<?php echo esc_attr($tag->slug); ?>" <?php checked(in_array($tag->slug, $selectedTags, true)); ?>>
                                <span><?php echo esc_html($tag->name); ?></span>
                                <small><?php echo esc_html((string) $tag->count); ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>

        <div class="shop-filter-form__actions">
            <a href="<?php echo esc_url($catalogueUrl); ?>"><?php esc_html_e('Reset', 'manga-shop'); ?></a>
            <button type="submit"><?php esc_html_e('View products', 'manga-shop'); ?></button>
        </div>
    </form>
</aside>
