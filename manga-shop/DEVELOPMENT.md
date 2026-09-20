# Manga Shop custom development

## Architecture

```text
theme/manga-shop/
├── functions.php
├── header.php / footer.php
├── front-page.php
├── page.php / single.php / index.php
├── woocommerce.php
├── style.css
└── assets/

plugin/manga-shop-plugin/
├── manga-shop-plugin.php
├── includes/class-plugin.php
├── includes/class-settings.php
└── uninstall.php
```

The theme owns markup, visual design and browser behavior. The plugin owns product
data, migration jobs, checkout or order rules, APIs, scheduled work, admin screens
and third-party integrations. This boundary keeps business behavior active if the
theme changes.

## Elementor migration boundary

The new storefront does not install or depend on Elementor. Migrate source pages
into one of these targets:

- first-class PHP templates for stable storefront layouts;
- WordPress block content for editable editorial pages;
- WooCommerce entities for products, categories, customers and orders;
- plugin-owned structured data for custom functionality.

Do not copy Elementor `_elementor_data` metadata into the new database unless a
later audit identifies a page that must temporarily retain Elementor.

## Legacy source host

The current source installation is available over SSH at:

```text
root@contabo2:/root/manga-docker-compose/
```

Its WordPress and MySQL data are bind-mounted under `wordpress/` and `dbdata/`.
Treat the source database, uploads and store configuration as read-only during the
migration. The recovery-only host Nginx configuration is tracked at
`migration/legacy-origin-nginx.conf`; it proxies the legacy origin to port `8080`
and is not part of the new shop deployment.

After cutover, `migration/contabo2-split-nginx.conf` routes
`www.mangaproduct.com` to the new shop on `127.0.0.1:8084` and redirects
`mangaproduct.com` to the canonical `www` domain. The legacy containers are kept
stopped for recovery only.

## Naming

- Theme functions: `manga_shop_`
- Plugin namespace: `MangaShop\Plugin`
- Plugin constants: `MANGA_SHOP_`
- Theme text domain: `manga-shop`
- Plugin text domain: `manga-shop-plugin`

## Validate

```bash
find theme/manga-shop plugin/manga-shop-plugin \
  -type f -name '*.php' -exec php -l {} \;

docker compose --env-file .env.example config --quiet
```

## Deploy custom code

```bash
./scripts/deploy-theme.sh
./scripts/deploy-plugin.sh
./scripts/deploy-all.sh
```

The scripts default to `root@site:/root/wordpress-sites/manga-shop`. Override
`DEPLOY_HOST`, `DEPLOY_PATH` or `DEPLOY_URL` when needed. Deployments validate PHP,
back up an existing component and synchronize only this site's theme/plugin.

## Next migration phase

Before importing, capture a source inventory: WordPress/WooCommerce versions,
active plugins, page routes, menus, product and variation counts, attributes,
taxonomies, media, reviews, users, orders, coupons, redirects, email behavior and
payment/shipping integrations. Build idempotent WP-CLI import commands in the
plugin so every entity can be safely re-run and audited.
