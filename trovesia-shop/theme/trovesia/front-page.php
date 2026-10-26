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
            <div><span aria-hidden="true">01</span><strong><?php esc_html_e('Complete gift set', 'trovesia'); ?></strong><small><?php esc_html_e('Everything shown, packed together', 'trovesia'); ?></small></div>
            <div><span aria-hidden="true">02</span><strong><?php esc_html_e('Five festive colours', 'trovesia'); ?></strong><small><?php esc_html_e('Choose the look for your moment', 'trovesia'); ?></small></div>
            <div><span aria-hidden="true">03</span><strong><?php esc_html_e('8–15 day delivery', 'trovesia'); ?></strong><small><?php esc_html_e('Tracking provided after dispatch', 'trovesia'); ?></small></div>
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

    <section id="how-it-works" class="home-section ritual-section">
        <div class="shop-container">
            <div class="ritual-intro">
                <p class="section-kicker"><?php esc_html_e('Celebrate with intention', 'trovesia'); ?></p>
                <h2><?php esc_html_e('A brighter holiday moment, planned simply.', 'trovesia'); ?></h2>
                <p><?php esc_html_e('Choose your colour, review the supplied safety instructions and prepare an outdoor setting away from flammable materials.', 'trovesia'); ?></p>
            </div>
            <ol class="ritual-steps">
                <li>
                    <span>01</span>
                    <div>
                        <h3><?php esc_html_e('Choose your colour', 'trovesia'); ?></h3>
                        <p><?php esc_html_e('Select Silver, Forest Green, Black, Pink or Yellow.', 'trovesia'); ?></p>
                    </div>
                </li>
                <li>
                    <span>02</span>
                    <div>
                        <h3><?php esc_html_e('Unbox the complete set', 'trovesia'); ?></h3>
                        <p><?php esc_html_e('The gift box includes the wand, material chamber and one small gas canister.', 'trovesia'); ?></p>
                    </div>
                </li>
                <li>
                    <span>03</span>
                    <div>
                        <h3><?php esc_html_e('Celebrate outdoors', 'trovesia'); ?></h3>
                        <p><?php esc_html_e('Follow every safety instruction and confirm local venue rules before use.', 'trovesia'); ?></p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section id="gift-set" class="home-section ingredient-section">
        <div class="shop-container">
            <div class="section-heading">
                <div>
                    <p class="section-kicker"><?php esc_html_e('Inside the gift box', 'trovesia'); ?></p>
                    <h2><?php esc_html_e('Everything in one festive set.', 'trovesia'); ?></h2>
                </div>
                <p><?php esc_html_e('This listing is for the complete presentation set, not a wand sold on its own.', 'trovesia'); ?></p>
            </div>
            <div class="ingredient-grid">
                <article>
                    <span aria-hidden="true">W</span>
                    <h3><?php esc_html_e('Atmosphere wand', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('One handheld spark-effect wand in the colour you select.', 'trovesia'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">G</span>
                    <h3><?php esc_html_e('Presentation gift box', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('A coordinated box that keeps the complete set together.', 'trovesia'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">M</span>
                    <h3><?php esc_html_e('Material chamber', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('One replaceable spark-material chamber is included.', 'trovesia'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">C</span>
                    <h3><?php esc_html_e('Small gas canister', 'trovesia'); ?></h3>
                    <p><?php esc_html_e('One dedicated pressurised canister is included, subject to destination restrictions.', 'trovesia'); ?></p>
                </article>
            </div>
        </div>
    </section>

    <section class="home-section care-story">
        <div class="shop-container care-story__inner">
            <div>
                <p class="section-kicker section-kicker--light"><?php esc_html_e('Celebrate responsibly', 'trovesia'); ?></p>
                <h2><?php esc_html_e('Bright moments begin with clear safety.', 'trovesia'); ?></h2>
            </div>
            <div>
                <p><?php esc_html_e('For responsible adult outdoor use only. Read the supplied instructions, keep clear of people and flammable materials, and confirm local laws and venue rules before use.', 'trovesia'); ?></p>
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
