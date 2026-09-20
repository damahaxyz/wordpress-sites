# Tikool Shop Plugin

The Tikool plugin provides WooCommerce emails, contact and tracking pages, customer inquiries, order support content, linked-product add-ons, customer reviews, and page-cache invalidation.

Frame-specific upload, crop, SKU geometry, and private order-artwork handling now live in the standalone **Tikool Frame Customizer** plugin.

Page-cache controls are available from WordPress Tools or with:

```bash
docker compose --profile tools run --rm wpcli tikool page-cache status
docker compose --profile tools run --rm wpcli tikool page-cache purge
```
