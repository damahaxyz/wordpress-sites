<?php
/**
 * Expandable child-category index for parent product-category archives.
 *
 * @package Aromamatrix
 *
 * @var array{term: WP_Term, children: WP_Term[]} $args
 */

$term = $args['term'];
$children = $args['children'];
$description = $term->description
    ? $term->description
    : __('Browse our fragrance catalogue by brand. Expand a collection to preview products, or view the complete range.', 'aromamatrix');
?>
<div class="woocommerce aromamatrix-category-index">
    <?php woocommerce_breadcrumb(); ?>

    <header class="woocommerce-products-header aromamatrix-category-index__header">
        <h1 class="woocommerce-products-header__title"><?php echo esc_html($term->name); ?></h1>
        <div class="term-description aromamatrix-category-index__description"><?php echo wp_kses_post(wpautop($description)); ?></div>
    </header>

    <div class="aromamatrix-category-index__list" data-category-accordion>
        <?php foreach ($children as $index => $child) : ?>
            <?php
            $panel_id = 'product-category-preview-' . $child->term_id;
            $is_open = false;
            $thumbnail_id = (int) get_term_meta($child->term_id, 'thumbnail_id', true);
            $thumbnail = $thumbnail_id
                ? wp_get_attachment_image($thumbnail_id, 'thumbnail', false, ['class' => 'aromamatrix-category-row__image'])
                : '';
            $products = wc_get_products([
                'status'     => 'publish',
                'visibility' => 'catalog',
                'category'   => [$child->slug],
                'limit'      => 8,
                'orderby'    => 'date',
                'order'      => 'DESC',
                'return'     => 'ids',
            ]);
            $child_link = get_term_link($child);
            ?>
            <section class="aromamatrix-category-row<?php echo $is_open ? ' is-open' : ''; ?>">
                <button class="aromamatrix-category-row__toggle" type="button" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($panel_id); ?>">
                    <span class="aromamatrix-category-row__icon" aria-hidden="true"></span>
                    <span class="aromamatrix-category-row__thumbnail">
                        <?php if ($thumbnail) : ?>
                            <?php echo wp_kses_post($thumbnail); ?>
                        <?php else : ?>
                            <span><?php echo esc_html(strtoupper(substr($child->name, 0, 1))); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="aromamatrix-category-row__copy">
                        <span class="aromamatrix-category-row__title">
                            <?php echo esc_html($child->name); ?>
                            <small><?php echo esc_html(sprintf(_n('%s product', '%s products', $child->count, 'aromamatrix'), number_format_i18n($child->count))); ?></small>
                        </span>
                        <?php if ($child->description) : ?>
                            <span class="aromamatrix-category-row__description"><?php echo esc_html(wp_strip_all_tags($child->description)); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="aromamatrix-category-row__action">
                        <span class="aromamatrix-category-row__expand"><?php esc_html_e('Expand', 'aromamatrix'); ?></span>
                        <span class="aromamatrix-category-row__collapse"><?php esc_html_e('Collapse', 'aromamatrix'); ?></span>
                    </span>
                </button>

                <div id="<?php echo esc_attr($panel_id); ?>" class="aromamatrix-category-row__panel aromamatrix-product-cards"<?php echo $is_open ? '' : ' hidden'; ?>>
                    <?php if ($products) : ?>
                        <?php wc_set_loop_prop('columns', 4); ?>
                        <?php wc_set_loop_prop('aromamatrix_category_preview', true); ?>
                        <?php woocommerce_product_loop_start(); ?>
                        <?php foreach ($products as $product_id) : ?>
                            <?php
                            $post = get_post($product_id); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                            if (! $post instanceof WP_Post) {
                                continue;
                            }

                            setup_postdata($post);
                            wc_setup_product_data($post);
                            wc_get_template_part('content', 'product');
                            ?>
                        <?php endforeach; ?>
                        <?php wp_reset_postdata(); ?>
                        <?php woocommerce_product_loop_end(); ?>
                        <?php wc_set_loop_prop('aromamatrix_category_preview', false); ?>
                    <?php else : ?>
                        <p class="aromamatrix-category-row__empty"><?php esc_html_e('Products for this collection are being prepared.', 'aromamatrix'); ?></p>
                    <?php endif; ?>

                    <?php if ($child->count > 8 && ! is_wp_error($child_link)) : ?>
                        <a class="aromamatrix-category-row__all" href="<?php echo esc_url($child_link); ?>">
                            <?php
                            printf(
                                esc_html(_n('View all %s product', 'View all %s products', $child->count, 'aromamatrix')),
                                esc_html(number_format_i18n($child->count))
                            );
                            ?>
                            <span aria-hidden="true">→</span>
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
