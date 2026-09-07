<?php
/**
 * Shop filter sidebar.
 *
 * @package Aromamatrix
 */

$shop_url = wc_get_page_permalink('shop');
$state = aromamatrix_get_shop_filter_state();
$category_terms = get_terms([
    'taxonomy'   => 'product_cat',
    'hide_empty' => false,
    'orderby'    => 'menu_order',
    'order'      => 'ASC',
]);
$category_tree = [];
$tags = get_terms([
    'taxonomy'   => 'product_tag',
    'hide_empty' => true,
    'orderby'    => 'name',
    'order'      => 'ASC',
]);
$attributes = aromamatrix_get_enabled_shop_filter_attributes();
$selected_categories = wp_list_pluck($state['categories'], 'slug');
$selected_tags = wp_list_pluck($state['tags'], 'slug');
$selected_attributes = [];
$active_filter_count = count($selected_categories) + count($selected_tags);

foreach ($state['attributes'] as $slug => $attribute) {
    $selected_attributes[$slug] = $attribute['values'];
    $active_filter_count += count($attribute['values']);
}

if (! is_wp_error($category_terms)) {
    $categories_by_parent = [];

    foreach ($category_terms as $category) {
        $categories_by_parent[$category->parent][] = $category;
    }

    foreach ($categories_by_parent[0] ?? [] as $parent) {
        $children = array_values(array_filter(
            $categories_by_parent[$parent->term_id] ?? [],
            static fn (WP_Term $child): bool => $child->count > 0
        ));
        $total_count = $parent->count + array_sum(wp_list_pluck($children, 'count'));

        if ($total_count < 1) {
            continue;
        }

        $category_tree[] = [
            'term'     => $parent,
            'children' => $children,
            'count'    => $total_count,
        ];
    }
}
?>
<button class="shop-filter-toggle" type="button" aria-expanded="false" aria-controls="shop-filter-panel" data-shop-filter-open>
    <?php esc_html_e('Filters', 'aromamatrix'); ?>
    <?php if ($active_filter_count > 0) : ?>
        <span><?php echo esc_html((string) $active_filter_count); ?></span>
    <?php endif; ?>
</button>
<div class="shop-filter-backdrop" data-shop-filter-close></div>
<aside id="shop-filter-panel" class="shop-filter-panel" aria-label="<?php esc_attr_e('Product filters', 'aromamatrix'); ?>">
    <div class="shop-filter-panel__header">
        <h2><?php esc_html_e('Filter products', 'aromamatrix'); ?></h2>
        <button type="button" aria-label="<?php esc_attr_e('Close filters', 'aromamatrix'); ?>" data-shop-filter-close>×</button>
    </div>
    <form class="shop-filter-form" action="<?php echo esc_url($shop_url); ?>" method="get">
        <?php if ('' !== aromamatrix_get_shop_search_term()) : ?>
            <input type="hidden" name="am_search" value="<?php echo esc_attr(aromamatrix_get_shop_search_term()); ?>">
        <?php endif; ?>
        <?php if (isset($_GET['orderby'])) : ?>
            <input type="hidden" name="orderby" value="<?php echo esc_attr(wc_clean(wp_unslash($_GET['orderby']))); ?>">
        <?php endif; ?>

        <?php if ($category_tree) : ?>
            <details class="shop-filter-group" open>
                <summary><?php esc_html_e('Categories', 'aromamatrix'); ?></summary>
                <div class="shop-filter-group__options shop-filter-group__options--categories">
                    <?php foreach ($category_tree as $branch) : ?>
                        <?php $parent = $branch['term']; ?>
                        <details class="shop-filter-category" open>
                            <summary>
                                <label class="shop-filter-category__parent"><input type="checkbox" name="am_category[]" value="<?php echo esc_attr($parent->slug); ?>" <?php checked(in_array($parent->slug, $selected_categories, true)); ?>><span><?php echo esc_html($parent->name); ?></span><small><?php echo esc_html((string) $branch['count']); ?></small></label>
                            </summary>
                            <div class="shop-filter-category__children">
                                <?php foreach ($branch['children'] as $child) : ?>
                                    <label class="shop-filter-category__child"><input type="checkbox" name="am_category[]" value="<?php echo esc_attr($child->slug); ?>" <?php checked(in_array($child->slug, $selected_categories, true)); ?>><span><?php echo esc_html($child->name); ?></span><small><?php echo esc_html((string) $child->count); ?></small></label>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php if (! is_wp_error($tags) && $tags) : ?>
            <details class="shop-filter-group" open>
                <summary><?php esc_html_e('Tags', 'aromamatrix'); ?></summary>
                <div class="shop-filter-group__options">
                    <?php foreach ($tags as $tag) : ?>
                        <label><input type="checkbox" name="am_tag[]" value="<?php echo esc_attr($tag->slug); ?>" <?php checked(in_array($tag->slug, $selected_tags, true)); ?>><span><?php echo esc_html($tag->name); ?></span><small><?php echo esc_html((string) $tag->count); ?></small></label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php foreach ($attributes as $slug => $attribute) : ?>
            <details class="shop-filter-group"<?php echo ! empty($selected_attributes[$slug]) ? ' open' : ''; ?>>
                <summary><?php echo esc_html($attribute['label']); ?></summary>
                <div class="shop-filter-group__options">
                    <?php foreach ($attribute['values'] as $value) : ?>
                        <label><input type="checkbox" name="am_attribute[<?php echo esc_attr($slug); ?>][]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $selected_attributes[$slug] ?? [], true)); ?>><span><?php echo esc_html($value); ?></span></label>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>

        <div class="shop-filter-form__actions">
            <button class="aroma-button" type="submit"><?php esc_html_e('Apply filters', 'aromamatrix'); ?></button>
            <?php if ($active_filter_count > 0) : ?>
                <a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Clear all', 'aromamatrix'); ?></a>
            <?php endif; ?>
        </div>
    </form>
</aside>
