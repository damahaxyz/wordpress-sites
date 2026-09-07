<?php
/**
 * Contact Us page with the company's wholesale introduction.
 *
 * @package Aromamatrix
 */

get_header();

$catalogue_url = home_url('/product-category/perfumes/');
$contact = aromamatrix_get_contact_details();
?>

<main id="primary" class="site-main contact-page">
    <section class="contact-page__hero">
        <div class="aroma-container contact-page__hero-inner">
            <div class="contact-page__intro">
                <span class="section-kicker"><?php esc_html_e('About 13799.com', 'aromamatrix'); ?></span>
                <h1><?php esc_html_e('Fragrance wholesale, built for your business.', 'aromamatrix'); ?></h1>
                <p><?php esc_html_e('We help retailers, resellers and fragrance businesses source a dependable assortment of designer fragrances, supported by US warehouse inventory and responsive wholesale support.', 'aromamatrix'); ?></p>
            </div>

            <dl class="contact-page__proof" aria-label="<?php esc_attr_e('Wholesale service highlights', 'aromamatrix'); ?>">
                <div>
                    <dt><?php esc_html_e('Wholesale-ready', 'aromamatrix'); ?></dt>
                    <dd><?php esc_html_e('Curated fragrances for resale', 'aromamatrix'); ?></dd>
                </div>
                <div>
                    <dt><?php esc_html_e('US inventory', 'aromamatrix'); ?></dt>
                    <dd><?php esc_html_e('Domestic fulfilment options', 'aromamatrix'); ?></dd>
                </div>
                <div>
                    <dt><?php esc_html_e('Direct support', 'aromamatrix'); ?></dt>
                    <dd><?php esc_html_e('Clear, practical order help', 'aromamatrix'); ?></dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="contact-page__body aroma-container">
        <article class="contact-page__about">
            <span class="section-kicker"><?php esc_html_e('Our approach', 'aromamatrix'); ?></span>
            <h2><?php esc_html_e('A dependable partner for fragrance buyers.', 'aromamatrix'); ?></h2>
            <p><?php esc_html_e('13799.com focuses on the practical needs of fragrance wholesale: a relevant selection, clear product details and support that helps you buy with confidence. Whether you are restocking proven sellers or building a new assortment, our team is here to make sourcing simpler.', 'aromamatrix'); ?></p>
            <p><?php esc_html_e('Browse the catalogue whenever you are ready, then contact us with the brands, quantities or delivery needs you have in mind.', 'aromamatrix'); ?></p>
            <a class="aroma-button aroma-button--arrow" href="<?php echo esc_url($catalogue_url); ?>">
                <?php esc_html_e('Browse fragrances', 'aromamatrix'); ?>
            </a>
        </article>

        <aside class="contact-page__contact" aria-labelledby="contact-details-title">
            <span class="section-kicker"><?php esc_html_e('Contact us', 'aromamatrix'); ?></span>
            <h2 id="contact-details-title"><?php esc_html_e('Start a wholesale conversation.', 'aromamatrix'); ?></h2>
            <p><?php esc_html_e('For pricing, availability, restocks or a product enquiry, contact our wholesale team directly.', 'aromamatrix'); ?></p>

            <div class="contact-page__methods">
                <a class="contact-page__method" href="mailto:<?php echo esc_attr($contact['email']); ?>">
                    <span class="contact-page__method-label"><?php esc_html_e('Email', 'aromamatrix'); ?></span>
                    <strong><?php echo esc_html($contact['email']); ?></strong>
                    <small><?php esc_html_e('Wholesale accounts, pricing and order support', 'aromamatrix'); ?></small>
                </a>
                <a class="contact-page__method" href="https://wa.me/<?php echo esc_attr($contact['whatsapp_url']); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="contact-page__method-label"><?php esc_html_e('WhatsApp', 'aromamatrix'); ?></span>
                    <strong><?php echo esc_html($contact['whatsapp']); ?></strong>
                    <small><?php esc_html_e('A quick way to check product availability', 'aromamatrix'); ?></small>
                </a>
            </div>
        </aside>
    </section>
</main>

<?php get_footer(); ?>
