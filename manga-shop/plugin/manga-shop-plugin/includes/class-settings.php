<?php
/**
 * Site-specific settings.
 *
 * @package MangaShopPlugin
 */

declare(strict_types=1);

namespace MangaShop\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION_NAME = 'manga_shop_settings';

    public static function defaults(): array
    {
        return [
            'source_url'               => 'https://www.mangaproduct.com',
            'organization_name'        => 'Manga Product',
            'organization_description' => 'Anime-inspired accessories, collectibles and gifts for fans around the world.',
            'support_email'            => 'support@mangaproduct.com',
            'announcement_primary'     => 'Free shipping on every order',
            'announcement_secondary'   => '90-day after-sale service',
            'returns_guarantee_note'   => 'If you are not satisfied within 30 days, contact us for return instructions and a full refund. Return shipping is covered.',
            'shipping_note'            => 'Orders are processed within 24–48 hours and delivered in 5–12 days. Tracking is emailed when the parcel ships.',
        ];
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('woocommerce_single_product_summary', [$this, 'render_product_notes'], 37);
        add_filter('manga_shop_announcement_messages', [$this, 'announcement_messages']);
    }

    public function admin_menu(): void
    {
        add_options_page(
            __('Manga Shop', 'manga-shop-plugin'),
            __('Manga Shop', 'manga-shop-plugin'),
            'manage_options',
            'manga-shop-settings',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'manga_shop',
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'default'           => self::defaults(),
                'sanitize_callback' => [$this, 'sanitize'],
            ]
        );

        add_settings_section(
            'manga_shop_identity',
            __('SEO and entity identity', 'manga-shop-plugin'),
            static function (): void {
                echo '<p>' . esc_html__('These facts are used in structured data and AI-readable catalog summaries.', 'manga-shop-plugin') . '</p>';
            },
            'manga-shop-settings'
        );

        foreach ([
            'organization_name'        => __('Organization name', 'manga-shop-plugin'),
            'organization_description' => __('Organization description', 'manga-shop-plugin'),
            'support_email'            => __('Support email', 'manga-shop-plugin'),
        ] as $key => $label) {
            add_settings_field(
                'manga_shop_' . $key,
                $label,
                [$this, 'identity_field'],
                'manga-shop-settings',
                'manga_shop_identity',
                ['key' => $key]
            );
        }

        add_settings_section(
            'manga_shop_announcement',
            __('Announcement bar', 'manga-shop-plugin'),
            static function (): void {
                echo '<p>' . esc_html__('The primary message appears on every screen. The secondary message is shown on larger screens only. Leave either field empty to hide it.', 'manga-shop-plugin') . '</p>';
            },
            'manga-shop-settings'
        );

        foreach ([
            'announcement_primary'   => __('Primary announcement', 'manga-shop-plugin'),
            'announcement_secondary' => __('Secondary announcement', 'manga-shop-plugin'),
        ] as $key => $label) {
            add_settings_field(
                'manga_shop_' . $key,
                $label,
                [$this, 'announcement_field'],
                'manga-shop-settings',
                'manga_shop_announcement',
                ['key' => $key]
            );
        }

        add_settings_section(
            'manga_shop_migration',
            __('Migration source', 'manga-shop-plugin'),
            static function (): void {
                echo '<p>' . esc_html__('The legacy origin is retained for migration auditing and URL normalization.', 'manga-shop-plugin') . '</p>';
            },
            'manga-shop-settings'
        );

        add_settings_field(
            'manga_shop_source_url',
            __('Source site URL', 'manga-shop-plugin'),
            [$this, 'source_url_field'],
            'manga-shop-settings',
            'manga_shop_migration'
        );

        add_settings_section(
            'manga_shop_product_notes',
            __('Product detail notes', 'manga-shop-plugin'),
            static function (): void {
                echo '<p>' . esc_html__('These notes appear on every product detail page. Leave a field empty to hide that note.', 'manga-shop-plugin') . '</p>';
            },
            'manga-shop-settings'
        );

        foreach ([
            'returns_guarantee_note' => __('Returns and guarantee note', 'manga-shop-plugin'),
            'shipping_note'          => __('Shipping note', 'manga-shop-plugin'),
        ] as $key => $label) {
            add_settings_field(
                'manga_shop_' . $key,
                $label,
                [$this, 'product_note_field'],
                'manga-shop-settings',
                'manga_shop_product_notes',
                ['key' => $key]
            );
        }
    }

    public function sanitize(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $defaults = self::defaults();
        $sourceUrl = esc_url_raw(trim((string) ($value['source_url'] ?? '')));
        $name = sanitize_text_field((string) ($value['organization_name'] ?? ''));
        $description = sanitize_textarea_field((string) ($value['organization_description'] ?? ''));
        $email = sanitize_email((string) ($value['support_email'] ?? ''));
        $announcementPrimary = array_key_exists('announcement_primary', $value)
            ? sanitize_text_field((string) $value['announcement_primary'])
            : $defaults['announcement_primary'];
        $announcementSecondary = array_key_exists('announcement_secondary', $value)
            ? sanitize_text_field((string) $value['announcement_secondary'])
            : $defaults['announcement_secondary'];
        $returnsGuaranteeNote = array_key_exists('returns_guarantee_note', $value)
            ? sanitize_textarea_field((string) $value['returns_guarantee_note'])
            : $defaults['returns_guarantee_note'];
        $shippingNote = array_key_exists('shipping_note', $value)
            ? sanitize_textarea_field((string) $value['shipping_note'])
            : $defaults['shipping_note'];

        return [
            'source_url'               => $sourceUrl !== '' ? untrailingslashit($sourceUrl) : $defaults['source_url'],
            'organization_name'        => $name !== '' ? $name : $defaults['organization_name'],
            'organization_description' => $description !== '' ? $description : $defaults['organization_description'],
            'support_email'            => $email !== '' ? $email : $defaults['support_email'],
            'announcement_primary'     => $announcementPrimary,
            'announcement_secondary'   => $announcementSecondary,
            'returns_guarantee_note'   => $returnsGuaranteeNote,
            'shipping_note'            => $shippingNote,
        ];
    }

    public function identity_field(array $args): void
    {
        $key = isset($args['key']) ? (string) $args['key'] : '';
        if (! array_key_exists($key, self::defaults())) {
            return;
        }

        $settings = self::get_settings();
        $value = (string) ($settings[$key] ?? '');
        $isDescription = $key === 'organization_description';
        if ($isDescription) {
            ?>
            <textarea class="large-text" rows="3" name="<?php echo esc_attr(self::OPTION_NAME . '[' . $key . ']'); ?>"><?php echo esc_textarea($value); ?></textarea>
            <?php
            return;
        }
        ?>
        <input
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_NAME . '[' . $key . ']'); ?>"
            type="<?php echo $key === 'support_email' ? 'email' : 'text'; ?>"
            value="<?php echo esc_attr($value); ?>"
        >
        <?php
    }

    public function source_url_field(): void
    {
        $settings = self::get_settings();
        $sourceUrl = (string) ($settings['source_url'] ?? '');
        ?>
        <input
            class="regular-text code"
            name="<?php echo esc_attr(self::OPTION_NAME); ?>[source_url]"
            type="url"
            value="<?php echo esc_attr($sourceUrl); ?>"
            placeholder="https://www.mangaproduct.com"
        >
        <?php
    }

    public function announcement_field(array $args): void
    {
        $key = isset($args['key']) ? (string) $args['key'] : '';
        if (! in_array($key, ['announcement_primary', 'announcement_secondary'], true)) {
            return;
        }

        $settings = self::get_settings();
        ?>
        <input
            class="large-text"
            name="<?php echo esc_attr(self::OPTION_NAME . '[' . $key . ']'); ?>"
            type="text"
            value="<?php echo esc_attr((string) ($settings[$key] ?? '')); ?>"
        >
        <?php
    }

    /**
     * @param array<int,string> $messages Theme fallbacks.
     * @return array<int,string>
     */
    public function announcement_messages(array $messages = []): array
    {
        unset($messages);
        $settings = self::get_settings();

        return [
            trim((string) ($settings['announcement_primary'] ?? '')),
            trim((string) ($settings['announcement_secondary'] ?? '')),
        ];
    }

    public function product_note_field(array $args): void
    {
        $key = isset($args['key']) ? (string) $args['key'] : '';
        if (! in_array($key, ['returns_guarantee_note', 'shipping_note'], true)) {
            return;
        }

        $settings = self::get_settings();
        $value = (string) ($settings[$key] ?? '');
        ?>
        <textarea
            class="large-text"
            rows="4"
            name="<?php echo esc_attr(self::OPTION_NAME . '[' . $key . ']'); ?>"
        ><?php echo esc_textarea($value); ?></textarea>
        <?php
    }

    public function render_product_notes(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        $settings = self::get_settings();
        $notes = [
            [
                'title' => __('Returns & guarantee', 'manga-shop-plugin'),
                'text'  => trim((string) ($settings['returns_guarantee_note'] ?? '')),
            ],
            [
                'title' => __('Shipping & delivery', 'manga-shop-plugin'),
                'text'  => trim((string) ($settings['shipping_note'] ?? '')),
            ],
        ];
        $notes = array_values(array_filter($notes, static fn(array $note): bool => $note['text'] !== ''));

        if ($notes === []) {
            return;
        }
        ?>
        <div class="manga-product-notes" aria-label="<?php esc_attr_e('Product policies', 'manga-shop-plugin'); ?>">
            <?php foreach ($notes as $note) : ?>
                <details class="manga-product-note">
                    <summary>
                        <span><?php echo esc_html($note['title']); ?></span>
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 6 5 5 5-5"/></svg>
                    </summary>
                    <div class="manga-product-note__content"><?php echo wp_kses_post(wpautop(esc_html($note['text']))); ?></div>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** @return array<string,string> */
    public static function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME, []);

        return wp_parse_args(is_array($settings) ? $settings : [], self::defaults());
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manga Shop settings', 'manga-shop-plugin'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('manga_shop');
                do_settings_sections('manga-shop-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
