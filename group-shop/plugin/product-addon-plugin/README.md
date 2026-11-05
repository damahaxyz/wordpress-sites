# product-addon-plugin

Renamed locally on 2026-11-06 from Trovesia Shop Plugin (`trovesia-plugin`). Directory and entry point are `plugin/product-addon-plugin/product-addon-plugin.php`; the text domain is `product-addon-plugin`. Existing `Trovesia\Plugin` classes, `TROVESIA_PLUGIN_*` constants, data keys, hooks and shortcodes are retained for compatibility. The active plugin record was switched without running activation installers or modifying business content. No production deployment. Local channel plugin is disabled and its source is retained.

Local version 3.8.5. muti-storefront-plugin 0.5.10 adds store-scoped add-on configuration and public-product presentation inheritance. Add-ons remain separate native product/cart/order lines and are configured only on brand sale versions; rules are not inherited from public products. Each form supports up to 20 rules. Invalid or rebound cart children are removed with a notice; ordinary mini-cart quantity controls remain available.

Presentation inherits by default. Select “单独设置本店展示信息” to edit the brand copy independently; unchecking restores the current public copy. Automatic order counts remain store-specific. This local revision does not deploy, seed marketing rules, or migrate existing product data.

## Branded WooCommerce emails

All WooCommerce HTML messages share the Trovesia storefront palette, typography,
order cards, call-to-action buttons, reassurance strip and useful footer links.
The implementation extends WooCommerce's supported email CSS and content hooks,
so individual templates do not become stale when WooCommerce is upgraded.

The base colors and font are initially seeded into **WooCommerce > Settings >
Emails** and remain editable there. The Trovesia layout is applied to customer,
account, order, refund, stock and administrator emails.

The site plugin owns persistent storefront behavior that must survive a theme
change. Version 3 adds:

- secure `[trovesia_contact_form]` and `[trovesia_tracking_form]` shortcodes;
- private, admin-only storage for customer inquiries, with email notification as
  a best-effort secondary channel;
- automatic creation of the Contact and Track Order pages on activation;
- WooCommerce product presentation fields for eyebrow copy, concise benefits,
  return notes, and shipping notes;
- truthful percentage sale badges, delivery estimates, and purchase assurances;
- a bundle picker for the Batana treatment plus reusable linked-product add-on
  rules that work independently of variation attribute names;
- multiple add-ons per product with follow-product, fixed-price, percentage-
  discount, configurable compare-at price, quantity, default-selection, and
  variation-value targeting options;
- separate linked-product cart and order lines, preserving the add-on product's
  own inventory, tax, shipping, refund, and reporting behavior;
- a real rolling 24-hour WooCommerce order count with a neutral zero-order state;
- product and block-checkout panels for the 30-day guarantee and tracked 7–12 day
  delivery window;
- an idempotent WP-CLI catalogue migration command for the three public Viorine
  products and their source images;
- an idempotent public-review importer that localizes review photos, preserves
  verified-review status, and adds review-image thumbnails to both WordPress and
  WooCommerce review administration tables;
- editorial featured-review status and editable review images in the review
  editor, plus featured and paginated storefront review cards;
- a photo-enabled modal review form with image validation and normal WordPress
  moderation behavior.
- Nginx FastCGI page-cache invalidation after public content, catalogue, stock,
  navigation, theme, or software changes, plus an administrator toolbar action,
  a Tools status screen, and WP-CLI management commands.

## Nginx page cache

The Docker Nginx service caches successful public HTML responses for ten
minutes. Requests from logged-in users, WooCommerce sessions, carts, checkout,
accounts, APIs, authenticated clients, non-GET methods, and URLs with query
strings bypass the cache.

WordPress writes a new cache generation after public content or catalogue
changes. The Nginx watcher validates and hot-reloads the configuration, making
all older cache entries unreachable without granting PHP access to Nginx cache
files. Administrators can inspect or clear the cache under **Tools > Trovesia
Page Cache** or from the admin toolbar.

```bash
docker compose --profile tools run --rm wpcli trovesia page-cache status
docker compose --profile tools run --rm wpcli trovesia page-cache purge
```

Run the catalogue migration after WooCommerce is active:

```bash
docker compose --profile tools run --rm wpcli trovesia migrate-catalog
```

Use `--skip-images` to update product data without downloading source media.
The importer identifies records by source handle and SKU, so it can be rerun
without creating duplicate products or attachments.

Migrate or refresh the public product reviews with:

```bash
docker compose --profile tools run --rm wpcli trovesia migrate-reviews
```

Use `--skip-media` when only review text and metadata should be synchronized.
The migration stores no customer emails, order records, or other private fields.
