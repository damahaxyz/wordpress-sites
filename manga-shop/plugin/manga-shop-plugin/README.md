# Manga Shop Plugin

Independent business-logic layer for Manga Product. The initial scaffold owns the
migration-source setting and plugin lifecycle. Future catalog/page migrators,
custom product fields, checkout rules, REST endpoints and integrations should be
implemented here rather than in the theme.

The source URL is available through the `manga_shop_source_url` filter:

```php
$source_url = apply_filters('manga_shop_source_url', '');
```

## Nginx page cache

The Docker Nginx service caches successful public HTML responses for ten
minutes. Logged-in users, WooCommerce sessions, carts, checkout, accounts,
APIs, authenticated requests, non-GET requests and URLs with query strings
bypass the cache.

Public content, catalogue, inventory, review, menu, theme and relevant setting
changes automatically start a new cache generation. Administrators can inspect
or clear the cache under **Tools > Manga Product Page Cache** or from the
WordPress admin toolbar.

The shared generation directory is owned by the WordPress runtime user with
`0750` permissions; its generation file uses `0640`. Nginx reads the file as
root during configuration reloads, while unrelated processes cannot modify it.

```bash
docker compose --profile tools run --rm wpcli manga-shop page-cache status
docker compose --profile tools run --rm wpcli manga-shop page-cache purge
```
