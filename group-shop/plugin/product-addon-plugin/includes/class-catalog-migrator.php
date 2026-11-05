<?php
/**
 * Idempotent Viorine-to-WooCommerce catalogue migration.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

namespace Trovesia\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Catalog_Migrator
{
    public static function register(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('trovesia migrate-catalog', [self::class, 'run']);
        }
    }

    /**
     * Import or update the public Viorine catalogue.
     *
     * ## OPTIONS
     *
     * [--skip-images]
     * : Update products without downloading source media.
     *
     * ## EXAMPLES
     *
     *     wp trovesia migrate-catalog
     *     wp trovesia migrate-catalog --skip-images
     *
     * @param array<int, string>   $args Positional arguments.
     * @param array<string, mixed> $assoc_args Named arguments.
     */
    public static function run(array $args, array $assoc_args): void
    {
        unset($args);

        if (! class_exists('WooCommerce')) {
            \WP_CLI::error('WooCommerce must be active before importing the catalogue.');
        }

        $catalog_file = TROVESIA_PLUGIN_DIR . 'data/catalog.php';
        if (! is_file($catalog_file)) {
            \WP_CLI::error('Catalogue data file is missing.');
        }

        /** @var array<int, array<string, mixed>> $catalog */
        $catalog = require $catalog_file;
        $skip_images = array_key_exists('skip-images', $assoc_args);
        $category_id = self::ensure_category();

        foreach ($catalog as $item) {
            $product_id = self::upsert_product($item, $category_id);

            if (! $skip_images) {
                self::sync_images($product_id, (array) ($item['images'] ?? []), (string) $item['name']);
            }

            \WP_CLI::success(sprintf('Synced %s (product #%d).', $item['name'], $product_id));
        }

        self::sync_addon_rules($catalog);

        \WP_CLI::success(sprintf('Trovesia catalogue migration complete: %d products.', count($catalog)));
    }

    /**
     * @param array<string, mixed> $item Product migration record.
     */
    private static function upsert_product(array $item, int $category_id): int
    {
        $existing_id = self::find_product((string) $item['source_handle'], (string) $item['sku']);
        $type = (string) $item['type'];

        if ($type === 'variable') {
            $product = $existing_id ? wc_get_product($existing_id) : new \WC_Product_Variable();
            if (! $product instanceof \WC_Product_Variable) {
                wp_delete_post($existing_id, true);
                $product = new \WC_Product_Variable();
            }
        } else {
            $product = $existing_id ? wc_get_product($existing_id) : new \WC_Product_Simple();
            if (! $product instanceof \WC_Product_Simple) {
                wp_delete_post($existing_id, true);
                $product = new \WC_Product_Simple();
            }
        }

        $product->set_name((string) $item['name']);
        $product->set_slug((string) $item['source_handle']);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_description((string) $item['description']);
        $product->set_short_description((string) $item['short_description']);
        $product->set_sku((string) $item['sku']);
        $product->set_category_ids([$category_id]);
        $product->set_manage_stock(false);

        if ($product instanceof \WC_Product_Simple) {
            $product->set_regular_price((string) $item['regular_price']);
            $product->set_sale_price((string) $item['sale_price']);
        } else {
            $bundle_attribute = new \WC_Product_Attribute();
            $bundle_attribute->set_id(0);
            $bundle_attribute->set_name('Bundle');
            $bundle_attribute->set_options(array_column((array) $item['variations'], 'name'));
            $bundle_attribute->set_position(0);
            $bundle_attribute->set_visible(true);
            $bundle_attribute->set_variation(true);

            $product->set_attributes([$bundle_attribute]);
            $product->set_default_attributes([
                'bundle' => (string) $item['variations'][0]['name'],
            ]);
        }

        $product_id = $product->save();

        update_post_meta($product_id, '_trovesia_source_handle', (string) $item['source_handle']);
        update_post_meta($product_id, '_trovesia_source_url', (string) $item['source_url']);
        update_post_meta($product_id, '_trovesia_eyebrow', (string) $item['eyebrow']);
        update_post_meta($product_id, '_trovesia_benefits', implode("\n", (array) $item['benefits']));
        update_post_meta($product_id, '_trovesia_guarantee', (string) $item['guarantee']);
        update_post_meta($product_id, '_trovesia_shipping', (string) $item['shipping']);

        if ($product instanceof \WC_Product_Variable) {
            self::sync_variations($product_id, (array) $item['variations']);
            \WC_Product_Variable::sync($product_id);
        }

        return $product_id;
    }

    /**
     * @param array<int, array<string, string>> $variations Variation migration records.
     */
    private static function sync_variations(int $product_id, array $variations): void
    {
        $expected_skus = [];
        foreach ($variations as $position => $record) {
            $sku = (string) $record['sku'];
            $expected_skus[] = $sku;
            $existing_id = wc_get_product_id_by_sku($sku);
            $variation = $existing_id ? wc_get_product($existing_id) : new \WC_Product_Variation();

            if (! $variation instanceof \WC_Product_Variation) {
                continue;
            }

            $variation->set_parent_id($product_id);
            $variation->set_status('publish');
            $variation->set_sku($sku);
            $variation->set_regular_price((string) $record['regular_price']);
            $variation->set_sale_price((string) $record['sale_price']);
            $variation->set_attributes(['bundle' => (string) $record['name']]);
            $variation->set_menu_order($position);
            $variation->set_manage_stock(false);
            $variation->save();
        }

        $product = wc_get_product($product_id);
        if (! $product instanceof \WC_Product_Variable) {
            return;
        }

        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (! $variation instanceof \WC_Product_Variation) {
                continue;
            }

            $attributes = $variation->get_attributes();
            if (
                ! in_array($variation->get_sku(), $expected_skus, true)
                && (str_ends_with($variation->get_sku(), '-DR') || isset($attributes['derma-roller']))
            ) {
                wp_trash_post($variation_id);
                \WP_CLI::log(sprintf('Moved obsolete add-on variation %s to Trash.', $variation->get_sku()));
            }
        }
    }

    /**
     * Seed reusable add-on rules after every linked product exists.
     * Existing administrator configuration is never overwritten.
     *
     * @param array<int, array<string, mixed>> $catalog Catalogue records.
     */
    private static function sync_addon_rules(array $catalog): void
    {
        foreach ($catalog as $item) {
            $definitions = (array) ($item['addon_rules'] ?? []);
            if ($definitions === []) {
                continue;
            }

            $product_id = self::find_product((string) $item['source_handle'], (string) $item['sku']);
            if ($product_id < 1 || metadata_exists('post', $product_id, '_trovesia_addon_rules')) {
                continue;
            }

            $rules = [];
            foreach ($definitions as $definition) {
                $legacy_linked_id = (int) get_post_meta($product_id, '_trovesia_addon_product_id', true);
                $linked_id = $legacy_linked_id > 0
                    ? $legacy_linked_id
                    : wc_get_product_id_by_sku((string) ($definition['product_sku'] ?? ''));
                if ($linked_id < 1) {
                    continue;
                }

                $legacy_mode = (string) get_post_meta($product_id, '_trovesia_addon_pricing_mode', true);
                $pricing_mode = in_array($legacy_mode, ['follow', 'fixed', 'discount'], true)
                    ? $legacy_mode
                    : sanitize_key((string) ($definition['pricing_mode'] ?? 'follow'));
                $legacy_fixed = get_post_meta($product_id, '_trovesia_addon_fixed_price', true);
                $legacy_discount = get_post_meta($product_id, '_trovesia_addon_discount_percent', true);

                $rules[] = [
                    'id'               => sanitize_key((string) ($definition['id'] ?? '')) ?: wp_generate_uuid4(),
                    'product_id'       => $linked_id,
                    'title'            => sanitize_text_field((string) ($definition['title'] ?? '')),
                    'pricing_mode'     => $pricing_mode,
                    'fixed_price'      => max(0, (float) ($legacy_fixed !== '' ? $legacy_fixed : ($definition['fixed_price'] ?? 0))),
                    'compare_at_price' => max(0, (float) ($definition['compare_at_price'] ?? 0)),
                    'discount_percent' => min(100, max(0, (float) ($legacy_discount !== '' ? $legacy_discount : ($definition['discount_percent'] ?? 0)))),
                    'quantity'         => max(1, (int) ($definition['quantity'] ?? 1)),
                    'default_selected' => ! empty($definition['default_selected']),
                    'allowed_values'   => array_values((array) ($definition['allowed_values'] ?? [])),
                ];
            }

            update_post_meta($product_id, '_trovesia_addon_rules', $rules);
            wc_delete_product_transients($product_id);
        }
    }

    /**
     * @param array<int, string> $urls Source image URLs.
     */
    private static function sync_images(int $product_id, array $urls, string $name): void
    {
        if ($urls === []) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = [];

        foreach ($urls as $index => $url) {
            $source_url = esc_url_raw((string) $url);
            $existing = get_posts([
                'fields'         => 'ids',
                'meta_key'       => '_trovesia_source_image_url',
                'meta_value'     => $source_url,
                'post_type'      => 'attachment',
                'posts_per_page' => 1,
            ]);

            if ($existing !== []) {
                $attachment_ids[] = (int) $existing[0];
                continue;
            }

            $temporary_file = download_url($source_url, 300);
            if (is_wp_error($temporary_file)) {
                \WP_CLI::warning(sprintf('Image skipped for %s: %s', $name, $temporary_file->get_error_message()));
                continue;
            }

            $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
            $filename = sanitize_file_name((string) wp_basename($path));
            if ($filename === '') {
                $filename = sprintf('trovesia-product-%d.jpg', $index + 1);
            }

            $attachment_id = media_handle_sideload(
                [
                    'name'     => $filename,
                    'tmp_name' => $temporary_file,
                ],
                $product_id,
                sprintf('%s — %d', $name, $index + 1)
            );

            if (is_wp_error($attachment_id)) {
                @unlink($temporary_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                \WP_CLI::warning(sprintf('Image skipped for %s: %s', $name, $attachment_id->get_error_message()));
                continue;
            }

            update_post_meta((int) $attachment_id, '_trovesia_source_image_url', $source_url);
            $attachment_ids[] = (int) $attachment_id;
        }

        if ($attachment_ids === []) {
            return;
        }

        set_post_thumbnail($product_id, array_shift($attachment_ids));
        update_post_meta($product_id, '_product_image_gallery', implode(',', $attachment_ids));
    }

    private static function find_product(string $source_handle, string $sku): int
    {
        $matches = get_posts([
            'fields'         => 'ids',
            'meta_key'       => '_trovesia_source_handle',
            'meta_value'     => $source_handle,
            'post_type'      => 'product',
            'posts_per_page' => 1,
        ]);

        if ($matches !== []) {
            return (int) $matches[0];
        }

        return (int) wc_get_product_id_by_sku($sku);
    }

    private static function ensure_category(): int
    {
        $term = term_exists('Hair Care', 'product_cat');
        if (is_array($term)) {
            return (int) $term['term_id'];
        }
        if (is_int($term)) {
            return $term;
        }

        $created = wp_insert_term('Hair Care', 'product_cat', [
            'description' => 'Botanical treatments, cleansing essentials, and tools for a considered hair-care ritual.',
            'slug'        => 'hair-care',
        ]);

        if (is_wp_error($created)) {
            \WP_CLI::error($created->get_error_message());
        }

        return (int) $created['term_id'];
    }
}
