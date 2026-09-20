<?php
/**
 * Trovesia storefront landing page.
 *
 * @package TrovesiaShop
 */

get_header();

$woocommerce_ready = class_exists('WooCommerce');
$home_banner = trovesia_get_home_banner_data(get_queried_object_id());
$home_products = trovesia_get_home_products_data(get_queried_object_id());
$overlay_rgb = trovesia_home_banner_hex_to_rgb((string) $home_banner['overlay_color']);
$text_rgb    = trovesia_home_banner_hex_to_rgb((string) $home_banner['text_color']);
$overlay     = min(0.95, max(0, ((int) $home_banner['overlay_opacity']) / 100));
$hero_style  = sprintf(
    '--trovesia-hero-desktop:url("%1$s");--trovesia-hero-mobile:url("%2$s");--trovesia-hero-desktop-position:%3$s;--trovesia-hero-mobile-position:%4$s;--trovesia-hero-overlay:rgba(%5$d,%6$d,%7$d,%8$s);--trovesia-hero-overlay-soft:rgba(%5$d,%6$d,%7$d,%9$s);--trovesia-hero-text:%10$s;--trovesia-hero-text-muted:rgba(%11$d,%12$d,%13$d,.82);--trovesia-hero-text-note:rgba(%11$d,%12$d,%13$d,.72);',
    esc_url_raw((string) $home_banner['desktop_image_url']),
    esc_url_raw((string) $home_banner['mobile_image_url']),
    (string) $home_banner['desktop_position'],
    (string) $home_banner['mobile_position'],
    $overlay_rgb[0],
    $overlay_rgb[1],
    $overlay_rgb[2],
    number_format($overlay, 2, '.', ''),
    number_format($overlay * 0.7, 2, '.', ''),
    (string) $home_banner['text_color'],
    $text_rgb[0],
    $text_rgb[1],
    $text_rgb[2]
);
?>
<main id="primary" class="site-main site-main--wide">
    <?php if ($home_banner['enabled']) : ?>
    <section class="home-hero home-hero--copy-<?php echo esc_attr((string) $home_banner['text_alignment']); ?>" style="<?php echo esc_attr($hero_style); ?>">
        <div class="home-hero__media" aria-hidden="true"></div>
        <div class="home-hero__veil" aria-hidden="true"></div>
        <div class="home-hero__inner shop-container">
            <div class="home-hero__copy">
                <?php if ((string) $home_banner['kicker'] !== '') : ?>
                    <p class="section-kicker section-kicker--light"><?php echo esc_html((string) $home_banner['kicker']); ?></p>
                <?php endif; ?>
                <?php if ((string) $home_banner['heading'] !== '') : ?>
                    <h1><?php echo esc_html((string) $home_banner['heading']); ?></h1>
                <?php endif; ?>
                <?php if ((string) $home_banner['lead'] !== '') : ?>
                    <p class="home-hero__lead"><?php echo esc_html((string) $home_banner['lead']); ?></p>
                <?php endif; ?>
                <?php if (((string) $home_banner['primary_label'] !== '' && (string) $home_banner['primary_url'] !== '') || ((string) $home_banner['secondary_label'] !== '' && (string) $home_banner['secondary_url'] !== '')) : ?>
                <div class="button-row">
                    <?php if ((string) $home_banner['primary_label'] !== '' && (string) $home_banner['primary_url'] !== '') : ?>
                        <a class="shop-button" href="<?php echo esc_url((string) $home_banner['primary_url']); ?>"><?php echo esc_html((string) $home_banner['primary_label']); ?></a>
                    <?php endif; ?>
                    <?php if ((string) $home_banner['secondary_label'] !== '' && (string) $home_banner['secondary_url'] !== '') : ?>
                        <a class="shop-button shop-button--ghost" href="<?php echo esc_url((string) $home_banner['secondary_url']); ?>"><?php echo esc_html((string) $home_banner['secondary_label']); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ((string) $home_banner['note'] !== '') : ?>
                    <p class="home-hero__note"><span aria-hidden="true">✦</span><?php echo esc_html((string) $home_banner['note']); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="trust-ribbon" aria-label="<?php esc_attr_e('Why shop with Trovesia', 'trovesia'); ?>">
        <div class="trust-ribbon__inner shop-container">
            <div><span aria-hidden="true">01</span><strong><?php esc_html_e('Ingredient-led', 'trovesia'); ?></strong><small><?php esc_html_e('Clear, useful product guidance', 'trovesia'); ?></small></div>
            <div><span aria-hidden="true">02</span><strong><?php esc_html_e('30-day returns', 'trovesia'); ?></strong><small><?php esc_html_e('Straightforward customer care', 'trovesia'); ?></small></div>
            <div><span aria-hidden="true">03</span><strong><?php esc_html_e('Tracked delivery', 'trovesia'); ?></strong><small><?php esc_html_e('Follow every parcel online', 'trovesia'); ?></small></div>
        </div>
    </section>

    <?php if (is_page() && have_posts()) : ?>
        <?php while (have_posts()) : ?>
            <?php the_post(); ?>
            <?php if (trim((string) get_the_content()) !== '') : ?>
                <section class="home-section home-editor-content shop-container">
                    <?php the_content(); ?>
                </section>
            <?php endif; ?>
        <?php endwhile; ?>
    <?php endif; ?>

    <?php if ($home_products['enabled']) : ?>
    <section id="shop" class="home-section product-showcase">
        <div class="shop-container">
            <div class="section-heading section-heading--center">
                <?php if ((string) $home_products['kicker'] !== '') : ?>
                    <p class="section-kicker"><?php echo esc_html((string) $home_products['kicker']); ?></p>
                <?php endif; ?>
                <?php if ((string) $home_products['heading'] !== '') : ?>
                    <h2><?php echo esc_html((string) $home_products['heading']); ?></h2>
                <?php endif; ?>
                <?php if ((string) $home_products['description'] !== '') : ?>
                    <p><?php echo esc_html((string) $home_products['description']); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($woocommerce_ready) : ?>
                <?php
                $product_ids = $home_products['product_ids'] ?: trovesia_home_products_default_ids();
                $shortcode   = sprintf(
                    '[products ids="%s" columns="%d" orderby="post__in" order="ASC"]',
                    implode(',', array_map('absint', $product_ids)),
                    (int) $home_products['columns']
                );
                echo do_shortcode($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            <?php else : ?>
                <div class="empty-state">
                    <h3><?php esc_html_e('The collection is being prepared.', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('Activate WooCommerce to display the migrated Viorine catalogue.', 'trovesia'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section id="ritual" class="home-section ritual-section">
        <div class="shop-container">
            <div class="ritual-intro">
                <p class="section-kicker"><?php esc_html_e('A ritual that stays simple', 'trovesia'); ?></p>
                <h2><?php esc_html_e('Three considered steps. Nothing extra.', 'trovesia'); ?></h2>
                <p><?php esc_html_e('Good routines work because they are easy to repeat. Start with the essentials and adjust the pace to what your hair and scalp need.', 'trovesia'); ?></p>
            </div>
            <ol class="ritual-steps">
                <li>
                    <span>01</span>
                    <div>
                        <h3><?php esc_html_e('Cleanse', 'trovesia'); ?></h3>
                        <p><?php esc_html_e('Wash away buildup while keeping hair comfortable and manageable.', 'trovesia'); ?></p>
                    </div>
                </li>
                <li>
                    <span>02</span>
                    <div>
                        <h3><?php esc_html_e('Nourish', 'trovesia'); ?></h3>
                        <p><?php esc_html_e('Massage a few drops of botanical oil into the scalp and dry-feeling lengths.', 'trovesia'); ?></p>
                    </div>
                </li>
                <li>
                    <span>03</span>
                    <div>
                        <h3><?php esc_html_e('Stay consistent', 'trovesia'); ?></h3>
                        <p><?php esc_html_e('Make the routine yours and give each step time to become second nature.', 'trovesia'); ?></p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section id="ingredients" class="home-section ingredient-section">
        <div class="shop-container">
            <div class="section-heading">
                <div>
                    <p class="section-kicker"><?php esc_html_e('Inside the ritual', 'trovesia'); ?></p>
                    <h2><?php esc_html_e('Botanicals with a purpose.', 'trovesia'); ?></h2>
                </div>
                <p><?php esc_html_e('Familiar ingredients, explained clearly—so you can choose what fits your routine.', 'trovesia'); ?></p>
            </div>
            <div class="ingredient-grid">
                <article>
                    <span aria-hidden="true">B</span>
                    <h3><?php esc_html_e('Batana oil', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('A rich botanical oil used to condition dry-feeling hair and support softness.', 'trovesia'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">R</span>
                    <h3><?php esc_html_e('Rosemary oil', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('An aromatic essential oil used in focused scalp-care routines.', 'trovesia'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">J</span>
                    <h3><?php esc_html_e('Jojoba oil', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('A lightweight emollient that helps smooth and soften without a heavy finish.', 'trovesia'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">E</span>
                    <h3><?php esc_html_e('Vitamin E', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('An antioxidant commonly used to support the feel and stability of oil blends.', 'trovesia'); ?></p>
                </article>
            </div>
        </div>
    </section>

    <section class="home-section care-story">
        <div class="shop-container care-story__inner">
            <div>
                <p class="section-kicker section-kicker--light"><?php esc_html_e('Care, without the noise', 'trovesia'); ?></p>
                <h2><?php esc_html_e('We choose clarity over impossible promises.', 'trovesia'); ?></h2>
            </div>
            <div>
                <p><?php esc_html_e('Hair care is personal. Trovesia focuses on well-presented products, useful directions and responsive support—not fabricated countdowns or pressure-led claims.', 'trovesia'); ?></p>
                <a class="text-link text-link--light" href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Ask a product question', 'trovesia'); ?> <span aria-hidden="true">↗</span></a>
            </div>
        </div>
    </section>

    <section class="home-section tracking-section">
        <div class="shop-container tracking-section__inner">
            <div>
                <p class="section-kicker"><?php esc_html_e('Already ordered?', 'trovesia'); ?></p>
                <h2><?php esc_html_e('Your parcel, in view.', 'trovesia'); ?></h2>
                <p><?php esc_html_e('Use the tracking number from your dispatch email to follow the journey.', 'trovesia'); ?></p>
            </div>
            <div>
                <?php
                if (shortcode_exists('trovesia_tracking_form')) {
                    echo do_shortcode('[trovesia_tracking_form]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    printf(
                        '<a class="shop-button shop-button--dark" href="%s">%s</a>',
                        esc_url(home_url('/track-order/')),
                        esc_html__('Track an order', 'trovesia')
                    );
                }
                ?>
            </div>
        </div>
    </section>
</main>
<?php
get_footer();
