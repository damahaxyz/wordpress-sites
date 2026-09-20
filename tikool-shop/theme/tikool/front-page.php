<?php
/**
 * Tikool storefront landing page.
 *
 * @package TikoolShop
 */

get_header();

$woocommerce_ready = class_exists('WooCommerce');
$home_banner = tikool_get_home_banner_data(get_queried_object_id());
$home_products = tikool_get_home_products_data(get_queried_object_id());
$overlay_rgb = tikool_home_banner_hex_to_rgb((string) $home_banner['overlay_color']);
$text_rgb    = tikool_home_banner_hex_to_rgb((string) $home_banner['text_color']);
$overlay     = min(0.95, max(0, ((int) $home_banner['overlay_opacity']) / 100));
$hero_style  = sprintf(
    '--tikool-hero-desktop:url("%1$s");--tikool-hero-mobile:url("%2$s");--tikool-hero-desktop-position:%3$s;--tikool-hero-mobile-position:%4$s;--tikool-hero-overlay:rgba(%5$d,%6$d,%7$d,%8$s);--tikool-hero-overlay-soft:rgba(%5$d,%6$d,%7$d,%9$s);--tikool-hero-text:%10$s;--tikool-hero-text-muted:rgba(%11$d,%12$d,%13$d,.82);--tikool-hero-text-note:rgba(%11$d,%12$d,%13$d,.72);',
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

    <section class="trust-ribbon" aria-label="<?php esc_attr_e('Why shop with Tikool', 'tikool'); ?>">
        <div class="trust-ribbon__inner shop-container">
            <div><span aria-hidden="true">01</span><strong><?php esc_html_e('Made from your photo', 'tikool'); ?></strong><small><?php esc_html_e('Your pet, beautifully captured', 'tikool'); ?></small></div>
            <div><span aria-hidden="true">02</span><strong><?php esc_html_e('Made to order', 'tikool'); ?></strong><small><?php esc_html_e('Prepared with care for every pet', 'tikool'); ?></small></div>
            <div><span aria-hidden="true">03</span><strong><?php esc_html_e('Tracked delivery', 'tikool'); ?></strong><small><?php esc_html_e('Follow every parcel online', 'tikool'); ?></small></div>
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
                $product_ids = $home_products['product_ids'] ?: tikool_home_products_default_ids();
                $shortcode   = sprintf(
                    '[products ids="%s" columns="%d" orderby="post__in" order="ASC"]',
                    implode(',', array_map('absint', $product_ids)),
                    (int) $home_products['columns']
                );
                echo do_shortcode($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            <?php else : ?>
                <div class="empty-state">
                    <h3><?php esc_html_e('The collection is being prepared.', 'tikool'); ?></h3>
                    <p><?php esc_html_e('Activate WooCommerce to start adding your custom pet collection.', 'tikool'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section id="how-it-works" class="home-section ritual-section">
        <div class="shop-container">
            <div class="ritual-intro">
                <p class="section-kicker"><?php esc_html_e('Made in three easy steps', 'tikool'); ?></p>
                <h2><?php esc_html_e('Your pet, made personal.', 'tikool'); ?></h2>
                <p><?php esc_html_e('Choose a keepsake, send one favorite photo, and we will prepare it especially for you.', 'tikool'); ?></p>
            </div>
            <ol class="ritual-steps">
                <li>
                    <span>01</span>
                    <div>
                        <h3><?php esc_html_e('Choose your keepsake', 'tikool'); ?></h3>
                        <p><?php esc_html_e('Find the portrait, blanket, canvas, or gift that feels right for your home.', 'tikool'); ?></p>
                    </div>
                </li>
                <li>
                    <span>02</span>
                    <div>
                        <h3><?php esc_html_e('Upload a favorite photo', 'tikool'); ?></h3>
                        <p><?php esc_html_e('Add your pet’s name and one clear photo at checkout. We keep it with your order.', 'tikool'); ?></p>
                    </div>
                </li>
                <li>
                    <span>03</span>
                    <div>
                        <h3><?php esc_html_e('Keep them close', 'tikool'); ?></h3>
                        <p><?php esc_html_e('Your custom piece is made to order and shipped with tracking to your door.', 'tikool'); ?></p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section id="memorial" class="home-section ingredient-section">
        <div class="shop-container">
            <div class="section-heading">
                <div>
                    <p class="section-kicker"><?php esc_html_e('A place for every memory', 'tikool'); ?></p>
                    <h2><?php esc_html_e('Keepsakes for every kind of bond.', 'tikool'); ?></h2>
                </div>
                <p><?php esc_html_e('From everyday joy to a quiet remembrance, choose something that honors the place they hold in your life.', 'tikool'); ?></p>
            </div>
            <div class="ingredient-grid">
                <article>
                    <span aria-hidden="true">P</span>
                    <h3><?php esc_html_e('Portraits', 'tikool'); ?></h3>
                    <p><?php esc_html_e('A favorite face, reimagined as art for the wall.', 'tikool'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">H</span>
                    <h3><?php esc_html_e('Home gifts', 'tikool'); ?></h3>
                    <p><?php esc_html_e('Blankets, pillows, and mugs made for daily smiles.', 'tikool'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">W</span>
                    <h3><?php esc_html_e('Wearable keepsakes', 'tikool'); ?></h3>
                    <p><?php esc_html_e('Custom tees and sweatshirts that carry them with you.', 'tikool'); ?></p>
                </article>
                <article>
                    <span aria-hidden="true">M</span>
                    <h3><?php esc_html_e('Memorial gifts', 'tikool'); ?></h3>
                    <p><?php esc_html_e('Thoughtful pieces for remembering a companion who is deeply missed.', 'tikool'); ?></p>
                </article>
            </div>
        </div>
    </section>

    <section class="home-section care-story">
        <div class="shop-container care-story__inner">
            <div>
                <p class="section-kicker section-kicker--light"><?php esc_html_e('Designed around real memories', 'tikool'); ?></p>
                <h2><?php esc_html_e('More than a photo. A small piece of home.', 'tikool'); ?></h2>
            </div>
            <div>
                <p><?php esc_html_e('Every pet has a look, a story, and a place in the family. Tikool turns the details you treasure into keepsakes you can live with and give with love.', 'tikool'); ?></p>
                <a class="text-link text-link--light" href="<?php echo esc_url(home_url('/contact-us/')); ?>"><?php esc_html_e('Ask a product question', 'tikool'); ?> <span aria-hidden="true">↗</span></a>
            </div>
        </div>
    </section>

    <section class="home-section tracking-section">
        <div class="shop-container tracking-section__inner">
            <div>
                <p class="section-kicker"><?php esc_html_e('Already ordered?', 'tikool'); ?></p>
                <h2><?php esc_html_e('Your parcel, in view.', 'tikool'); ?></h2>
                <p><?php esc_html_e('Use the tracking number from your dispatch email to follow the journey.', 'tikool'); ?></p>
            </div>
            <div>
                <?php
                if (shortcode_exists('tikool_tracking_form')) {
                    echo do_shortcode('[tikool_tracking_form]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                } else {
                    printf(
                        '<a class="shop-button shop-button--dark" href="%s">%s</a>',
                        esc_url(home_url('/track-order/')),
                        esc_html__('Track an order', 'tikool')
                    );
                }
                ?>
            </div>
        </div>
    </section>
</main>
<?php
get_footer();
