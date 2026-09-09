<?php
/**
 * Reusable linked-product add-ons for WooCommerce products.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

namespace Trovesia\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Addon_Pricing
{
    private const RULES_META = '_trovesia_addon_rules';

    private static ?self $instance = null;

    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_action('add_meta_boxes_product', [$this, 'add_meta_box']);
        add_action('save_post_product', [$this, 'save_settings'], 20);
        add_action('woocommerce_update_product', [$this, 'clear_linked_product_caches']);

        add_filter('woocommerce_add_cart_item_data', [$this, 'capture_selected_addons'], 10, 4);
        add_action('woocommerce_add_to_cart', [$this, 'add_selected_addons'], 20, 6);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_cart_addon_prices'], 20);
        add_action('woocommerce_after_cart_item_quantity_update', [$this, 'sync_addon_quantities'], 20, 3);
        add_action('woocommerce_remove_cart_item', [$this, 'remove_child_addons'], 20, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 20, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_data'], 20, 4);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_internal_order_meta']);
    }

    public function add_meta_box(): void
    {
        add_meta_box(
            'trovesia-addon-pricing',
            __('Trovesia product add-ons', 'trovesia-plugin'),
            [$this, 'render_meta_box'],
            'product',
            'normal',
            'default'
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        $rules = self::get_rules($post->ID);
        $products = wc_get_products([
            'exclude' => [$post->ID],
            'limit'   => -1,
            'orderby' => 'name',
            'order'   => 'ASC',
            'status'  => ['publish', 'draft', 'private'],
            'type'    => 'simple',
        ]);

        wp_nonce_field('trovesia_addon_rules', 'trovesia_addon_rules_nonce');
        ?>
        <p><?php esc_html_e('Attach any simple product as an optional purchase. The linked product remains a separate cart and order line, so its stock, tax and shipping settings continue to work normally.', 'trovesia-plugin'); ?></p>
        <div class="trovesia-addon-rules" data-next-index="<?php echo esc_attr((string) count($rules)); ?>">
            <?php foreach ($rules as $index => $rule) : ?>
                <?php $this->render_rule_row((string) $index, $rule, $products); ?>
            <?php endforeach; ?>
        </div>
        <p><button class="button trovesia-add-addon" type="button"><?php esc_html_e('Add linked product', 'trovesia-plugin'); ?></button></p>
        <template id="trovesia-addon-rule-template">
            <?php $this->render_rule_row('__INDEX__', self::empty_rule(), $products); ?>
        </template>
        <style>
            .trovesia-addon-rule { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; padding:16px; margin:0 0 12px; border:1px solid #dcdcde; border-radius:8px; background:#fff; }
            .trovesia-addon-rule p { margin:0; }
            .trovesia-addon-rule label { display:block; margin-bottom:5px; font-weight:600; }
            .trovesia-addon-rule .widefat { width:100%; }
            .trovesia-addon-rule__wide { grid-column:span 2; }
            .trovesia-addon-rule__actions { display:flex; align-items:flex-end; justify-content:flex-end; }
            @media(max-width:1100px){.trovesia-addon-rule{grid-template-columns:repeat(2,minmax(0,1fr));}}
        </style>
        <script>
            (() => {
                const root = document.querySelector('.trovesia-addon-rules');
                const add = document.querySelector('.trovesia-add-addon');
                const template = document.querySelector('#trovesia-addon-rule-template');
                if (!root || !add || !template) return;
                add.addEventListener('click', () => {
                    const index = Number(root.dataset.nextIndex || 0);
                    root.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
                    root.dataset.nextIndex = String(index + 1);
                });
                root.addEventListener('click', (event) => {
                    const button = event.target.closest('.trovesia-remove-addon');
                    if (button) button.closest('.trovesia-addon-rule')?.remove();
                });
            })();
        </script>
        <?php
    }

    /**
     * @param array<string, mixed> $rule Add-on rule.
     * @param array<int, \WC_Product> $products Selectable products.
     */
    private function render_rule_row(string $index, array $rule, array $products): void
    {
        ?>
        <fieldset class="trovesia-addon-rule">
            <input name="trovesia_addons[<?php echo esc_attr($index); ?>][id]" type="hidden" value="<?php echo esc_attr((string) $rule['id']); ?>">
            <p class="trovesia-addon-rule__wide">
                <label><?php esc_html_e('Linked product', 'trovesia-plugin'); ?></label>
                <select class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][product_id]">
                    <option value="0"><?php esc_html_e('Select a product', 'trovesia-plugin'); ?></option>
                    <?php foreach ($products as $candidate) : ?>
                        <option value="<?php echo esc_attr((string) $candidate->get_id()); ?>" <?php selected((int) $rule['product_id'], $candidate->get_id()); ?>><?php echo esc_html($candidate->get_name()); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="trovesia-addon-rule__wide">
                <label><?php esc_html_e('Display title (optional)', 'trovesia-plugin'); ?></label>
                <input class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][title]" type="text" value="<?php echo esc_attr((string) $rule['title']); ?>">
            </p>
            <p>
                <label><?php esc_html_e('Pricing mode', 'trovesia-plugin'); ?></label>
                <select class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][pricing_mode]">
                    <option value="follow" <?php selected($rule['pricing_mode'], 'follow'); ?>><?php esc_html_e('Follow product price', 'trovesia-plugin'); ?></option>
                    <option value="fixed" <?php selected($rule['pricing_mode'], 'fixed'); ?>><?php esc_html_e('Fixed add-on price', 'trovesia-plugin'); ?></option>
                    <option value="discount" <?php selected($rule['pricing_mode'], 'discount'); ?>><?php esc_html_e('Discount product price', 'trovesia-plugin'); ?></option>
                </select>
            </p>
            <p>
                <label><?php esc_html_e('Fixed price', 'trovesia-plugin'); ?></label>
                <input class="widefat" min="0" name="trovesia_addons[<?php echo esc_attr($index); ?>][fixed_price]" step="0.01" type="number" value="<?php echo esc_attr((string) $rule['fixed_price']); ?>">
            </p>
            <p>
                <label><?php esc_html_e('Original / compare-at price', 'trovesia-plugin'); ?></label>
                <input class="widefat" min="0" name="trovesia_addons[<?php echo esc_attr($index); ?>][compare_at_price]" placeholder="<?php esc_attr_e('Linked product regular price', 'trovesia-plugin'); ?>" step="0.01" type="number" value="<?php echo esc_attr((string) $rule['compare_at_price']); ?>">
            </p>
            <p>
                <label><?php esc_html_e('Discount (%)', 'trovesia-plugin'); ?></label>
                <input class="widefat" max="100" min="0" name="trovesia_addons[<?php echo esc_attr($index); ?>][discount_percent]" step="0.01" type="number" value="<?php echo esc_attr((string) $rule['discount_percent']); ?>">
            </p>
            <p>
                <label><?php esc_html_e('Quantity per main item', 'trovesia-plugin'); ?></label>
                <input class="widefat" min="1" name="trovesia_addons[<?php echo esc_attr($index); ?>][quantity]" step="1" type="number" value="<?php echo esc_attr((string) $rule['quantity']); ?>">
            </p>
            <p class="trovesia-addon-rule__wide">
                <label><?php esc_html_e('Allowed variation values (optional)', 'trovesia-plugin'); ?></label>
                <input class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][allowed_values]" type="text" value="<?php echo esc_attr(implode(', ', (array) $rule['allowed_values'])); ?>" placeholder="Buy 1, Buy 2">
                <small><?php esc_html_e('Comma-separated. Leave blank to show for every variation.', 'trovesia-plugin'); ?></small>
            </p>
            <p>
                <label><input name="trovesia_addons[<?php echo esc_attr($index); ?>][default_selected]" type="checkbox" value="1" <?php checked((bool) $rule['default_selected']); ?>> <?php esc_html_e('Selected by default', 'trovesia-plugin'); ?></label>
            </p>
            <p class="trovesia-addon-rule__actions"><button class="button-link-delete trovesia-remove-addon" type="button"><?php esc_html_e('Remove rule', 'trovesia-plugin'); ?></button></p>
        </fieldset>
        <?php
    }

    public function save_settings(int $post_id): void
    {
        if (
            ! isset($_POST['trovesia_addon_rules_nonce'])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['trovesia_addon_rules_nonce'])), 'trovesia_addon_rules')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || ! current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        $submitted = isset($_POST['trovesia_addons']) && is_array($_POST['trovesia_addons'])
            ? wp_unslash($_POST['trovesia_addons'])
            : [];
        $rules = [];

        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $product_id = absint($row['product_id'] ?? 0);
            $linked = $product_id > 0 && $product_id !== $post_id ? wc_get_product($product_id) : false;
            if (! $linked || ! $linked->is_type('simple')) {
                continue;
            }

            $mode = sanitize_key((string) ($row['pricing_mode'] ?? 'follow'));
            if (! in_array($mode, ['follow', 'fixed', 'discount'], true)) {
                $mode = 'follow';
            }

            $allowed_values = array_values(array_filter(array_map(
                'sanitize_text_field',
                array_map('trim', explode(',', (string) ($row['allowed_values'] ?? '')))
            )));
            $rules[] = [
                'id'               => sanitize_key((string) ($row['id'] ?? '')) ?: wp_generate_uuid4(),
                'product_id'       => $product_id,
                'title'            => sanitize_text_field((string) ($row['title'] ?? '')),
                'pricing_mode'     => $mode,
                'fixed_price'      => max(0, (float) wc_format_decimal((string) ($row['fixed_price'] ?? '0'))),
                'compare_at_price' => max(0, (float) wc_format_decimal((string) ($row['compare_at_price'] ?? '0'))),
                'discount_percent' => min(100, max(0, (float) wc_format_decimal((string) ($row['discount_percent'] ?? '0')))),
                'quantity'         => max(1, absint($row['quantity'] ?? 1)),
                'default_selected' => ! empty($row['default_selected']),
                'allowed_values'   => $allowed_values,
            ];
        }

        update_post_meta($post_id, self::RULES_META, $rules);
        wc_delete_product_transients($post_id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_rules(int $parent_product_id): array
    {
        $rules = get_post_meta($parent_product_id, self::RULES_META, true);

        if (! is_array($rules)) {
            return [];
        }

        return array_values(array_map(
            static fn ($rule): array => is_array($rule) ? array_replace(self::empty_rule(), $rule) : self::empty_rule(),
            $rules
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_rule(int $parent_product_id, string $rule_id): ?array
    {
        foreach (self::get_rules($parent_product_id) as $rule) {
            if ((string) ($rule['id'] ?? '') === $rule_id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule Add-on rule.
     */
    public static function calculate_rule_price(array $rule): float
    {
        $linked = wc_get_product((int) ($rule['product_id'] ?? 0));
        if (! $linked) {
            return 0.0;
        }

        $mode = (string) ($rule['pricing_mode'] ?? 'follow');
        $current = (float) $linked->get_price();

        if ($mode === 'fixed') {
            return max(0, (float) ($rule['fixed_price'] ?? 0));
        }

        if ($mode === 'discount') {
            $discount = min(100, max(0, (float) ($rule['discount_percent'] ?? 0)));

            return round($current * (1 - ($discount / 100)), wc_get_price_decimals());
        }

        return $current;
    }

    /**
     * Resolve the display regular price used for strike-through and savings.
     *
     * @param array<string, mixed> $rule Add-on rule.
     */
    public static function calculate_rule_regular_price(array $rule): float
    {
        $configured = max(0, (float) ($rule['compare_at_price'] ?? 0));
        if ($configured > 0) {
            return $configured;
        }

        $linked = wc_get_product((int) ($rule['product_id'] ?? 0));
        if (! $linked) {
            return 0.0;
        }

        $regular = (float) $linked->get_regular_price();

        return $regular > 0 ? $regular : (float) $linked->get_price();
    }

    /**
     * @param array<string, mixed> $cart_item_data Existing cart data.
     * @return array<string, mixed>
     */
    public function capture_selected_addons(array $cart_item_data, int $product_id, int $variation_id, int $quantity): array
    {
        unset($quantity);

        $requested = isset($_POST['trovesia_addons']) && is_array($_POST['trovesia_addons'])
            ? array_map('sanitize_key', wp_unslash($_POST['trovesia_addons']))
            : [];
        if ($requested === []) {
            return $cart_item_data;
        }

        $selected = [];
        foreach (self::get_rules($product_id) as $rule) {
            $rule_id = (string) ($rule['id'] ?? '');
            if ($rule_id !== '' && in_array($rule_id, $requested, true) && $this->rule_allows_variation($rule, $variation_id)) {
                $selected[] = $rule_id;
            }
        }

        if ($selected !== []) {
            $cart_item_data['_trovesia_selected_addons'] = $selected;
        }

        return $cart_item_data;
    }

    /**
     * @param array<string, mixed> $variation Variation attributes.
     * @param array<string, mixed> $cart_item_data Parent cart data.
     */
    public function add_selected_addons(string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data): void
    {
        unset($variation_id, $variation);

        if (! function_exists('WC') || ! WC()->cart) {
            return;
        }

        $selected = (array) ($cart_item_data['_trovesia_selected_addons'] ?? []);
        foreach ($selected as $rule_id) {
            $rule = self::get_rule($product_id, (string) $rule_id);
            if ($rule === null) {
                continue;
            }

            $linked = wc_get_product((int) $rule['product_id']);
            if (! $linked || ! $linked->is_purchasable() || ! $linked->is_in_stock()) {
                continue;
            }

            WC()->cart->add_to_cart(
                $linked->get_id(),
                max(1, (int) $rule['quantity']) * $quantity,
                0,
                [],
                [
                    '_trovesia_addon_parent_key'        => $cart_item_key,
                    '_trovesia_addon_parent_product_id' => $product_id,
                    '_trovesia_addon_rule_id'           => (string) $rule['id'],
                    '_trovesia_addon_price_snapshot'     => self::calculate_rule_price($rule),
                ]
            );
        }
    }

    public function apply_cart_addon_prices(\WC_Cart $cart): void
    {
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['_trovesia_addon_rule_id']) || empty($cart_item['_trovesia_addon_parent_product_id'])) {
                continue;
            }

            $rule = self::get_rule(
                (int) $cart_item['_trovesia_addon_parent_product_id'],
                (string) $cart_item['_trovesia_addon_rule_id']
            );
            $price = $rule === null
                ? (float) ($cart_item['_trovesia_addon_price_snapshot'] ?? 0)
                : self::calculate_rule_price($rule);
            $regular = $rule === null
                ? $price
                : max($price, self::calculate_rule_regular_price($rule));

            if (isset($cart_item['data']) && $cart_item['data'] instanceof \WC_Product) {
                $cart_item['data']->set_regular_price($regular);
                $cart_item['data']->set_sale_price($price < $regular ? $price : '');
                $cart_item['data']->set_price($price);
            }
        }
    }

    public function sync_addon_quantities(string $cart_item_key, int $quantity, int $old_quantity): void
    {
        unset($old_quantity);

        if (! function_exists('WC') || ! WC()->cart) {
            return;
        }

        foreach (WC()->cart->get_cart() as $child_key => $cart_item) {
            if ((string) ($cart_item['_trovesia_addon_parent_key'] ?? '') !== $cart_item_key) {
                continue;
            }

            $rule = self::get_rule(
                (int) ($cart_item['_trovesia_addon_parent_product_id'] ?? 0),
                (string) ($cart_item['_trovesia_addon_rule_id'] ?? '')
            );
            $multiplier = $rule === null ? 1 : max(1, (int) ($rule['quantity'] ?? 1));
            WC()->cart->set_quantity($child_key, $quantity * $multiplier, false);
        }
    }

    /**
     * @param mixed $cart Cart instance supplied by WooCommerce.
     */
    public function remove_child_addons(string $cart_item_key, $cart): void
    {
        if (! $cart instanceof \WC_Cart) {
            return;
        }

        $children = [];
        foreach ($cart->get_cart() as $child_key => $cart_item) {
            if ((string) ($cart_item['_trovesia_addon_parent_key'] ?? '') === $cart_item_key) {
                $children[] = $child_key;
            }
        }

        foreach ($children as $child_key) {
            $cart->remove_cart_item($child_key);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $item_data Existing display data.
     * @param array<string, mixed> $cart_item Cart line.
     * @return array<int, array<string, mixed>>
     */
    public function display_cart_item_data(array $item_data, array $cart_item): array
    {
        $parent_id = (int) ($cart_item['_trovesia_addon_parent_product_id'] ?? 0);
        if ($parent_id > 0) {
            $parent = wc_get_product($parent_id);
            $item_data[] = [
                'key'   => __('Add-on for', 'trovesia-plugin'),
                'value' => $parent ? $parent->get_name() : __('Main product', 'trovesia-plugin'),
            ];
        }

        return $item_data;
    }

    /**
     * @param array<string, mixed> $values Cart line values.
     */
    public function add_order_item_data(\WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order): void
    {
        unset($cart_item_key, $order);

        $parent_id = (int) ($values['_trovesia_addon_parent_product_id'] ?? 0);
        if ($parent_id < 1) {
            return;
        }

        $parent = wc_get_product($parent_id);
        $item->add_meta_data(
            __('Add-on for', 'trovesia-plugin'),
            $parent ? $parent->get_name() : __('Main product', 'trovesia-plugin'),
            true
        );
        $item->add_meta_data('_trovesia_addon_rule_id', (string) ($values['_trovesia_addon_rule_id'] ?? ''), true);
        $item->add_meta_data('_trovesia_addon_parent_product_id', $parent_id, true);
    }

    /**
     * @param array<int, string> $hidden Existing hidden keys.
     * @return array<int, string>
     */
    public function hide_internal_order_meta(array $hidden): array
    {
        $hidden[] = '_trovesia_addon_rule_id';
        $hidden[] = '_trovesia_addon_parent_product_id';

        return $hidden;
    }

    public function clear_linked_product_caches(int $updated_product_id): void
    {
        $parents = get_posts([
            'fields'         => 'ids',
            'meta_key'       => self::RULES_META,
            'post_type'      => 'product',
            'posts_per_page' => -1,
        ]);

        foreach ($parents as $parent_id) {
            foreach (self::get_rules((int) $parent_id) as $rule) {
                if ((int) ($rule['product_id'] ?? 0) === $updated_product_id) {
                    wc_delete_product_transients((int) $parent_id);
                    break;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $rule Add-on rule.
     */
    private function rule_allows_variation(array $rule, int $variation_id): bool
    {
        $allowed = (array) ($rule['allowed_values'] ?? []);
        if ($allowed === [] || $variation_id < 1) {
            return true;
        }

        $variation = wc_get_product($variation_id);
        if (! $variation instanceof \WC_Product_Variation) {
            return false;
        }

        $selected_values = array_map('sanitize_title', array_values($variation->get_attributes()));
        foreach ($allowed as $value) {
            if (in_array(sanitize_title((string) $value), $selected_values, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_rule(): array
    {
        return [
            'id'               => '',
            'product_id'       => 0,
            'title'            => '',
            'pricing_mode'     => 'follow',
            'fixed_price'      => '',
            'compare_at_price' => '',
            'discount_percent' => '',
            'quantity'         => 1,
            'default_selected' => false,
            'allowed_values'   => [],
        ];
    }
}
