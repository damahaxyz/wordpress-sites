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
        add_action('woocommerce_update_product_variation', [$this, 'clear_linked_product_caches']);

        add_filter('woocommerce_add_cart_item_data', [$this, 'capture_selected_addons'], 10, 4);
        add_action('woocommerce_add_to_cart', [$this, 'add_selected_addons'], 20, 6);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_cart_addon_prices'], 20);
        add_action('woocommerce_after_cart_item_quantity_update', [$this, 'sync_addon_quantities'], 20, 3);
        add_action('woocommerce_remove_cart_item', [$this, 'remove_child_addons'], 20, 2);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 20, 2);
        add_filter('woocommerce_cart_item_permalink', [$this, 'link_addon_cart_item'], 20, 3);
        add_filter('woocommerce_widget_cart_item_quantity', [$this, 'display_mini_cart_quantity'], 20, 3);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_data'], 20, 4);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hide_internal_order_meta']);
        add_action('wp_ajax_nopriv_trovesia_update_cart_quantity', [$this, 'update_cart_quantity']);
        add_action('wp_ajax_trovesia_update_cart_quantity', [$this, 'update_cart_quantity']);
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
        if(!apply_filters('trovesia_addon_parent_allowed',true,$post->ID)){
            echo '<p>加购规则在各店铺的商品版本中独立设置，不随公共商品继承。请进入对应品牌版本配置。</p>';return;
        }
        do_action('trovesia_addon_controls',$post);
        $rules = self::get_rules($post->ID);
        $parent = wc_get_product($post->ID);
        $variation_values = $parent instanceof \WC_Product_Variable
            ? array_values(array_unique(array_merge(...array_values($parent->get_variation_attributes()))))
            : [];
        $products = wc_get_products(apply_filters('trovesia_addon_product_query_args',[
            'exclude' => [$post->ID],
            'limit'   => -1,
            'orderby' => 'name',
            'order'   => 'ASC',
            'status'  => ['publish', 'draft', 'private'],
            'type'    => 'simple',
        ],$post->ID));
        $variable_products = wc_get_products(apply_filters('trovesia_addon_product_query_args',[
            'exclude' => [$post->ID],
            'limit'   => -1,
            'orderby' => 'name',
            'order'   => 'ASC',
            'status'  => ['publish', 'draft', 'private'],
            'type'    => 'variable',
        ],$post->ID));
        foreach ($variable_products as $variable_product) {
            if (! $variable_product instanceof \WC_Product_Variable) {
                continue;
            }
            foreach ($variable_product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation instanceof \WC_Product_Variation) {
                    $products[] = $variation;
                }
            }
        }
        $products=array_values(array_filter($products,static fn($linked)=>self::valid_link($post->ID,$linked)));
        usort($products, static fn (\WC_Product $left, \WC_Product $right): int => strcasecmp(
            self::linked_product_label($left),
            self::linked_product_label($right)
        ));

        wp_nonce_field('trovesia_addon_rules', 'trovesia_addon_rules_nonce');
        ?>
        <p><?php esc_html_e('Attach a simple product or one specific variation as an optional purchase. To offer several variations, add one linked-product rule for each variation. Every linked item remains a separate cart and order line, so its stock, tax and shipping settings continue to work normally.', 'trovesia-plugin'); ?></p>
        <div class="trovesia-addon-rules" data-next-index="<?php echo esc_attr((string) count($rules)); ?>">
            <?php foreach ($rules as $index => $rule) : ?>
                <?php $this->render_rule_row((string) $index, $rule, $products, $variation_values); ?>
            <?php endforeach; ?>
        </div>
        <p><button class="button trovesia-add-addon" type="button"><?php esc_html_e('Add linked product', 'trovesia-plugin'); ?></button></p>
        <template id="trovesia-addon-rule-template">
            <?php $this->render_rule_row('__INDEX__', self::empty_rule(), $products, $variation_values); ?>
        </template>
        <input type="hidden" name="trovesia_addon_rules_complete" value="1">
        <input type="hidden" name="trovesia_addon_rule_count" value="<?php echo count($rules); ?>">
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
                const count = document.querySelector('[name=trovesia_addon_rule_count]');
                const syncCount = () => { if(count) count.value=String(root.children.length); add.disabled=root.children.length>=20; };
                syncCount();
                add.addEventListener('click', () => {
                    if(root.children.length>=20) return;
                    const index = Number(root.dataset.nextIndex || 0);
                    root.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
                    root.dataset.nextIndex = String(index + 1);
                    syncCount();
                });
                root.addEventListener('click', (event) => {
                    const button = event.target.closest('.trovesia-remove-addon');
                    if (button) button.closest('.trovesia-addon-rule')?.remove();
                    syncCount();
                });
            })();
        </script>
        <?php
    }

    /**
     * @param array<string, mixed> $rule Add-on rule.
     * @param array<int, \WC_Product> $products Selectable products.
     * @param array<int, string> $variation_values Values used by the parent product.
     */
    private function render_rule_row(string $index, array $rule, array $products, array $variation_values): void
    {
        ?>
        <fieldset class="trovesia-addon-rule">
            <input name="trovesia_addons[<?php echo esc_attr($index); ?>][id]" type="hidden" value="<?php echo esc_attr((string) $rule['id']); ?>">
            <p class="trovesia-addon-rule__wide">
                <label><?php esc_html_e('Linked product', 'trovesia-plugin'); ?></label>
                <select class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][product_id]">
                    <option value="0"><?php esc_html_e('Select a product', 'trovesia-plugin'); ?></option>
                    <?php foreach ($products as $candidate) : ?>
                        <option value="<?php echo esc_attr((string) $candidate->get_id()); ?>" <?php selected((int) $rule['product_id'], $candidate->get_id()); ?>><?php echo esc_html(self::linked_product_label($candidate)); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php
                $selected_product = wc_get_product((int) $rule['product_id']);
                if ($selected_product instanceof \WC_Product && ($selected_product->get_status() !== 'publish' || $selected_product->get_price() === '')) :
                    ?>
                    <small style="color:#b32d2e"><?php esc_html_e('This add-on will be hidden until the linked product is published and has a price.', 'trovesia-plugin'); ?></small>
                <?php endif; ?>
            </p>
            <p class="trovesia-addon-rule__wide">
                <label><?php esc_html_e('Display title (optional)', 'trovesia-plugin'); ?></label>
                <input class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][title]" type="text" value="<?php echo esc_attr((string) $rule['title']); ?>">
            </p>
            <p>
                <label><?php esc_html_e('Selection group (optional)', 'trovesia-plugin'); ?></label>
                <input class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][selection_group]" type="text" value="<?php echo esc_attr((string) $rule['selection_group']); ?>" placeholder="refill-chambers">
                <small><?php esc_html_e('Rules with the same group key become a single-choice set.', 'trovesia-plugin'); ?></small>
            </p>
            <p>
                <label><?php esc_html_e('Group heading (optional)', 'trovesia-plugin'); ?></label>
                <input class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][group_title]" type="text" value="<?php echo esc_attr((string) $rule['group_title']); ?>">
            </p>
            <p class="trovesia-addon-rule__wide">
                <label><?php esc_html_e('Group description (optional)', 'trovesia-plugin'); ?></label>
                <input class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][group_description]" type="text" value="<?php echo esc_attr((string) $rule['group_description']); ?>">
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
                <input class="widefat" name="trovesia_addons[<?php echo esc_attr($index); ?>][allowed_values]" type="text" value="<?php echo esc_attr(implode(' | ', (array) $rule['allowed_values'])); ?>" placeholder="<?php echo esc_attr(implode(' | ', array_slice($variation_values, 0, 2))); ?>">
                <small><?php esc_html_e('Leave blank to show for every variation. Separate multiple exact values with |.', 'trovesia-plugin'); ?>
                    <?php if ($variation_values !== []) : ?><?php echo esc_html(sprintf(__('Available: %s', 'trovesia-plugin'), implode(' | ', $variation_values))); ?><?php endif; ?>
                </small>
            </p>
            <p>
                <label><input name="trovesia_addons[<?php echo esc_attr($index); ?>][default_selected]" type="checkbox" value="1" <?php checked((bool) $rule['default_selected']); ?>> <?php esc_html_e('Selected by default', 'trovesia-plugin'); ?></label>
            </p>
            <p class="trovesia-addon-rule__actions"><button class="button-link-delete trovesia-remove-addon" type="button"><?php esc_html_e('Remove rule', 'trovesia-plugin'); ?></button></p>
        </fieldset>
        <?php
    }

    /** Shared by the picker, native form, metadata guard and runtime cart checks. */
    public static function valid_link(int $parent_id, $linked): bool
    {
        $parent=wc_get_product($parent_id);
        $ok=$parent&&$linked instanceof \WC_Product&&$parent_id!==$linked->get_id()
            &&in_array($linked->get_type(),['simple','variation'],true)
            &&(!$linked->is_type('variation')||$linked->get_parent_id()!==$parent_id)
            &&apply_filters('trovesia_addon_parent_allowed',true,$parent_id);
        return (bool)apply_filters('trovesia_addon_link_allowed',(bool)$ok,$parent_id,$linked);
    }

    public static function validate_form(int $id,array $post): ?\WP_Error
    {
        $rows=$post['trovesia_addons']??[];
        if(!apply_filters('trovesia_addon_parent_allowed',true,$id)||($post['trovesia_addon_rules_complete']??'')!=='1'||!is_array($rows)||!isset($post['trovesia_addon_rule_count'])||(int)$post['trovesia_addon_rule_count']!==count($rows)||count($rows)>20){return new \WP_Error('trovesia_addon_form','加购表单不完整，请刷新后重试（最多20条规则）。');}
        $seen=[];
        foreach($rows as $row){
            foreach(['id','product_id','title','selection_group','group_title','group_description','pricing_mode','fixed_price','compare_at_price','discount_percent','quantity','allowed_values'] as $key){if(!isset($row[$key])||!is_string($row[$key])){return new \WP_Error('trovesia_addon_form','加购规则字段不完整。');}}
            if(!self::valid_link($id,wc_get_product(absint($row['product_id'])))){return new \WP_Error('trovesia_addon_link','加购商品必须是本站的其他简单商品或有效变体。');}
            if($row['id']!==''&&isset($seen[$row['id']])){return new \WP_Error('trovesia_addon_duplicate','加购规则编号重复，请刷新重试。');}$seen[$row['id']]=true;
        }return null;
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

        $error=self::validate_form($post_id,$_POST);
        if(is_wp_error($error)){return;}
        $submitted = isset($_POST['trovesia_addons']) && is_array($_POST['trovesia_addons'])
            ? wp_unslash($_POST['trovesia_addons'])
            : [];
        $rules = [];
        $parent = wc_get_product($post_id);
        $variation_values = $parent instanceof \WC_Product_Variable
            ? array_values(array_unique(array_merge(...array_values($parent->get_variation_attributes()))))
            : [];

        foreach ($submitted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $product_id = absint($row['product_id'] ?? 0);
            $linked = $product_id > 0 && $product_id !== $post_id ? wc_get_product($product_id) : false;
            if (
                ! self::valid_link($post_id,$linked)
                || ! in_array($linked->get_type(), ['simple', 'variation'], true)
                || ($linked instanceof \WC_Product_Variation && $linked->get_parent_id() === $post_id)
            ) {
                continue;
            }

            $mode = sanitize_key((string) ($row['pricing_mode'] ?? 'follow'));
            if (! in_array($mode, ['follow', 'fixed', 'discount'], true)) {
                $mode = 'follow';
            }

            $raw_allowed = trim((string) ($row['allowed_values'] ?? ''));
            if ($raw_allowed === '') {
                $allowed_values = [];
            } elseif (in_array($raw_allowed, $variation_values, true)) {
                // A variation name may itself contain a comma (for example, a bundle).
                $allowed_values = [$raw_allowed];
            } else {
                $delimiter = str_contains($raw_allowed, '|') ? '|' : ',';
                $allowed_values = array_values(array_filter(array_map(
                    'sanitize_text_field',
                    array_map('trim', explode($delimiter, $raw_allowed))
                )));
            }
            $rules[] = [
                'id'               => sanitize_key((string) ($row['id'] ?? '')) ?: wp_generate_uuid4(),
                'product_id'       => $product_id,
                'title'            => sanitize_text_field((string) ($row['title'] ?? '')),
                'selection_group'  => sanitize_key((string) ($row['selection_group'] ?? '')),
                'group_title'      => sanitize_text_field((string) ($row['group_title'] ?? '')),
                'group_description' => sanitize_text_field((string) ($row['group_description'] ?? '')),
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
        if(!apply_filters('trovesia_addon_parent_allowed',true,$parent_product_id)){return [];}
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
        if(!empty($cart_item_data['_trovesia_addon_parent_key'])){unset($cart_item_data['_trovesia_selected_addons']);return $cart_item_data;}

        $requested = isset($_POST['trovesia_addons']) && is_array($_POST['trovesia_addons'])
            ? array_map('sanitize_key', wp_unslash($_POST['trovesia_addons']))
            : [];
        if ($requested === []) {
            return $cart_item_data;
        }

        $selected = [];
        $selected_groups = [];
        foreach (self::get_rules($product_id) as $rule) {
            $rule_id = (string) ($rule['id'] ?? '');
            if ($rule_id !== '' && in_array($rule_id, $requested, true) && self::valid_link($product_id,wc_get_product((int)$rule['product_id'])) && $this->rule_allows_variation($rule, $variation_id)) {
                $group = sanitize_key((string) ($rule['selection_group'] ?? ''));
                if ($group !== '' && isset($selected_groups[$group])) {
                    continue;
                }
                $selected[] = $rule_id;
                if ($group !== '') {
                    $selected_groups[$group] = true;
                }
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
        unset($variation);
        if(!empty($cart_item_data['_trovesia_addon_parent_key'])){return;}

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
            if (!self::valid_link($product_id,$linked) || !$this->rule_allows_variation($rule,$variation_id) || $linked->get_status()!=='publish' || ! $linked->is_purchasable() || ! $linked->is_in_stock()) {
                continue;
            }

            $linked_product_id = $linked->get_id();
            $linked_variation_id = 0;
            $linked_variation = [];
            if ($linked instanceof \WC_Product_Variation) {
                $linked_product_id = $linked->get_parent_id();
                $linked_variation_id = $linked->get_id();
                $linked_variation = $linked->get_variation_attributes();
            }

            WC()->cart->add_to_cart(
                $linked_product_id,
                max(1, (int) $rule['quantity']) * $quantity,
                $linked_variation_id,
                $linked_variation,
                [
                    '_trovesia_addon_parent_key'        => $cart_item_key,
                    '_trovesia_addon_parent_product_id' => $product_id,
                    '_trovesia_addon_rule_id'           => (string) $rule['id'],
                    '_trovesia_addon_price_snapshot'     => self::calculate_rule_price($rule),
                ]
            );
        }
    }

    /** Recheck the real parent/child products before applying any rule price. */
    public function cart_rule(array $item, \WC_Cart $cart): ?array
    {
        $parent=$cart->get_cart_item((string)($item['_trovesia_addon_parent_key']??''));
        $parent_id=(int)($item['_trovesia_addon_parent_product_id']??0);
        if(!$parent_id||!$parent||!empty($parent['_trovesia_addon_parent_key'])||(int)$parent['product_id']!==$parent_id){return null;}
        $rule=self::get_rule($parent_id,(string)($item['_trovesia_addon_rule_id']??''));
        $actual=(int)(($item['variation_id']??0)?:($item['product_id']??0));
        $linked=wc_get_product($actual);$main=wc_get_product($parent_id);
        if(!$rule||(int)$rule['product_id']!==$actual||!self::valid_link($parent_id,$linked)||!$main||!$main->is_purchasable()||!$linked->is_purchasable()||$linked->get_status()!=='publish'||!$this->rule_allows_variation($rule,(int)($parent['variation_id']??0))){return null;}
        if($linked->is_type('variation')&&(int)$item['product_id']!==$linked->get_parent_id()){return null;}
        return $rule;
    }

    public function apply_cart_addon_prices(\WC_Cart $cart): void
    {
        foreach($cart->get_cart() as $key=>$item){
            if(empty($item['_trovesia_addon_parent_key'])&&empty($item['_trovesia_addon_rule_id'])){continue;}
            $rule=$this->cart_rule($item,$cart);
            if(!$rule){$cart->remove_cart_item($key);$message=__('An add-on is no longer available. Please choose it again.','trovesia-plugin');if(!wc_has_notice($message,'notice')){wc_add_notice($message,'notice');}continue;}
            $parent=$cart->get_cart_item((string)$item['_trovesia_addon_parent_key']);
            $expected=max(1,(int)$parent['quantity'])*max(1,(int)$rule['quantity']);
            if((int)$item['quantity']!==$expected){$cart->set_quantity($key,$expected,false);}
            $price=self::calculate_rule_price($rule);$regular=max($price,self::calculate_rule_regular_price($rule));
            $cart->cart_contents[$key]['data']=clone $item['data'];
            $product=$cart->cart_contents[$key]['data'];$product->set_regular_price($regular);$product->set_sale_price($price<$regular?$price:'');$product->set_price($price);
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
            $rule=$this->cart_rule($cart_item,WC()->cart);if(!$rule){WC()->cart->remove_cart_item($child_key);continue;}
            $multiplier=max(1,(int)$rule['quantity']);
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
     * Keep hidden add-on products wrapped in the same title link as ordinary
     * mini-cart items so their thumbnail and title share the grid layout.
     *
     * @param string|false $permalink Existing cart item permalink.
     * @param array<string, mixed> $cart_item Cart line data.
     * @return string|false
     */
    public function link_addon_cart_item($permalink, array $cart_item, string $cart_item_key)
    {
        unset($cart_item_key);

        if ($permalink || empty($cart_item['_trovesia_addon_rule_id'])) {
            return $permalink;
        }

        $product = $cart_item['data'] ?? null;

        return $product instanceof \WC_Product ? $product->get_permalink($cart_item) : $permalink;
    }

    /**
     * Keep the drawer's add-on unit price aligned with the configured rule price.
     *
     * @param string              $html          Default quantity and price markup.
     * @param array<string,mixed> $cart_item     Cart line data.
     * @param string              $cart_item_key Cart line key.
     */
    public function display_mini_cart_quantity(string $html, array $cart_item, string $cart_item_key): string
    {
        if (
            empty($cart_item['data'])
            || ! $cart_item['data'] instanceof \WC_Product
            || ! function_exists('WC')
            || ! WC()->cart
        ) {
            return $html;
        }

        $product = clone $cart_item['data'];
        $is_addon = ! empty($cart_item['_trovesia_addon_rule_id']) && ! empty($cart_item['_trovesia_addon_parent_product_id']);
        if ($is_addon) {
            $rule = self::get_rule(
                (int) $cart_item['_trovesia_addon_parent_product_id'],
                (string) $cart_item['_trovesia_addon_rule_id']
            );
            $rule=$this->cart_rule($cart_item,WC()->cart);
            if(!$rule){return '';}
            $price=self::calculate_rule_price($rule);
            $product->set_price($price);
        }

        $display_price = WC()->cart->get_product_price($product);
        $quantity = max(1, (int) ($cart_item['quantity'] ?? 1));

        if ($is_addon) {
            return sprintf(
                '<span class="quantity trovesia-mini-cart__quantity is-linked"><span>%1$s &times; %2$s</span></span>',
                esc_html((string) $quantity),
                wp_kses_post($display_price)
            );
        }

        $max = $product->get_max_purchase_quantity();
        $increase_disabled = $max > 0 && $quantity >= $max;

        return sprintf(
            '<span class="quantity trovesia-mini-cart__quantity" data-cart-item-key="%1$s" data-quantity="%2$d"><button type="button" data-mini-cart-quantity-change="-1" aria-label="%3$s"%4$s>&minus;</button><span class="trovesia-mini-cart__quantity-value" aria-live="polite">%2$d</span><button type="button" data-mini-cart-quantity-change="1" aria-label="%5$s"%6$s>+</button><span class="trovesia-mini-cart__unit-price">&times; %7$s</span></span>',
            esc_attr($cart_item_key),
            $quantity,
            esc_attr__('Decrease quantity', 'trovesia-plugin'),
            $quantity <= 1 ? ' disabled' : '',
            esc_attr__('Increase quantity', 'trovesia-plugin'),
            $increase_disabled ? ' disabled' : '',
            wp_kses_post($display_price)
        );
    }

    public function update_cart_quantity(): void
    {
        if (! check_ajax_referer('trovesia_cart_quantity', 'nonce', false)) {
            wp_send_json_error(['message' => __('The cart request expired. Please refresh the page.', 'trovesia-plugin')], 403);
        }

        if (! function_exists('WC')) {
            wp_send_json_error(['message' => __('The cart is unavailable.', 'trovesia-plugin')], 400);
        }
        if (! WC()->cart && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        $cart_item_key = wc_clean(wp_unslash((string) ($_POST['cart_item_key'] ?? '')));
        $quantity = max(1, absint($_POST['quantity'] ?? 1));
        $cart_item = WC()->cart ? WC()->cart->get_cart_item($cart_item_key) : [];
        if ($cart_item === [] || ! empty($cart_item['_trovesia_addon_parent_key'])) {
            wp_send_json_error(['message' => __('This cart item cannot be updated directly.', 'trovesia-plugin')], 400);
        }

        $product = $cart_item['data'] ?? null;
        if (! $product instanceof \WC_Product) {
            wp_send_json_error(['message' => __('This product is unavailable.', 'trovesia-plugin')], 400);
        }

        $max = $product->get_max_purchase_quantity();
        if ($max > 0) {
            $quantity = min($quantity, $max);
        }

        WC()->cart->set_quantity($cart_item_key, $quantity, true);
        WC()->cart->set_session();
        \WC_AJAX::get_refreshed_fragments();
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

        if(!WC()->cart||!$this->cart_rule($values,WC()->cart)){throw new \Exception(__('The add-on changed. Please refresh your cart.','trovesia-plugin'));}
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
            'selection_group'  => '',
            'group_title'      => '',
            'group_description' => '',
            'pricing_mode'     => 'follow',
            'fixed_price'      => '',
            'compare_at_price' => '',
            'discount_percent' => '',
            'quantity'         => 1,
            'default_selected' => false,
            'allowed_values'   => [],
        ];
    }

    private static function linked_product_label(\WC_Product $product): string
    {
        if (! $product instanceof \WC_Product_Variation) {
            return $product->get_name();
        }

        $parent = wc_get_product($product->get_parent_id());
        $parent_name = $parent ? $parent->get_name() : $product->get_name();
        $variation = wc_get_formatted_variation($product, true, false, true);
        $sku = $product->get_sku();

        return trim($parent_name . ($variation !== '' ? ' — ' . $variation : '') . ($sku !== '' ? ' [' . $sku . ']' : ''));
    }
}
