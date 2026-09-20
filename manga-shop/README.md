# Manga Shop

`manga-shop` is the production WordPress + WooCommerce environment that replaced
the legacy Manga Product shop at `https://www.mangaproduct.com`.
It uses a custom theme instead of Elementor and a
separate plugin for migration tooling and site-specific business behavior.

Custom code lives in:

```text
theme/manga-shop/                 storefront templates and presentation
plugin/manga-shop-plugin/         business logic and migration tooling
```

Both directories are bind-mounted into WordPress, so PHP, CSS and JavaScript
changes are visible without rebuilding an image.

## Default ports

| Purpose | Address |
|---|---|
| WordPress HTTP (for host reverse proxy) | `127.0.0.1:8084` |
| MariaDB (for SSH Tunnel) | `127.0.0.1:3310` |

These ports follow the existing shops and do not overlap their `8080`–`8083` and
`3306`–`3309` listeners.

## Start

```bash
cp .env.example .env
chmod 600 .env
```

Replace both database password placeholders in `.env`, then run:

```bash
docker compose config --quiet
docker compose pull
docker compose up -d --wait --wait-timeout 180
docker compose ps
```

For local development without a hosts-file entry or HTTPS proxy, change both
`WORDPRESS_HOME` and `WORDPRESS_SITEURL` in `.env` to
`http://127.0.0.1:8084` before the first start.

After WordPress installation, install WooCommerce and activate:

```text
Appearance → Themes → Manga Shop
Plugins → Installed Plugins → Manga Shop Plugin
```

## Domain and HTTPS

The canonical production domain is `https://www.mangaproduct.com` and
`https://mangaproduct.com` redirects to it. The host Nginx template is at
`nginx/host/mangaproduct.com.conf` and proxies the canonical domain to `127.0.0.1:8084`.

Install the site's certificate and key on the host at the paths referenced by that
template. Never commit the private key.

## Development

See [`DEVELOPMENT.md`](DEVELOPMENT.md) for code ownership, naming conventions,
validation and the planned Elementor-free migration boundary.

## Back up

```bash
./scripts/backup.sh
```

The backup script exports MariaDB, archives `wp-content` and writes SHA-256
checksums under this site's own `backups/` directory.
