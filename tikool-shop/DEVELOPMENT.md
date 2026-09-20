# Tikool Shop Development

`theme/tikool/` owns the storefront layout, global style, and WooCommerce presentation. `plugin/tikool-plugin/` owns durable business rules such as customer forms, policies, emails, order support, reviews, product fields, and the private pet-photo workflow.

The project uses `tikool_` for theme functions, `Tikool\Plugin` for plugin classes, `TIKOOL_` for constants, and `tikool` / `tikool-plugin` as text domains.

For a custom pet product, create the WooCommerce product, then enable **Require pet photo** in its General settings. Its product page will require a pet name and a JPG, PNG, or WebP photo up to 10 MB. The file is stored outside public media paths and authorized staff can download it from the matching WooCommerce order line.

Run local checks before deployment:

```bash
find theme/tikool plugin/tikool-plugin -type f -name '*.php' -exec php -l {} \;
docker compose --env-file .env.example config --quiet
```

The deploy helpers default to the Tikool project directory but support `DEPLOY_HOST`, `DEPLOY_PATH`, and `DEPLOY_URL` overrides. They back up and replace only the custom theme and plugin; WordPress uploads, `.env`, and the database stay untouched.
