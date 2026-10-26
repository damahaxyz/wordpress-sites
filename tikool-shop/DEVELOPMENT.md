# Tikool Shop Development

`theme/tikool/` owns the storefront layout, global style, and WooCommerce presentation. `plugin/tikool-plugin/` owns durable business rules such as customer forms, policies, emails, order support, reviews, product fields, and the private pet-photo workflow. `plugin/tikool-ai/` owns shared AI provider settings, prompt templates, and the server-side image-generation client; product customizers own their authenticated browser endpoints and call its PHP API. `plugin/tikool-memory-page/` owns paid-order provisioning, private memorial media, NFC destination records, and atomic GitHub publishing.

The project uses `tikool_` for theme functions, `Tikool\Plugin` for plugin classes, `TIKOOL_` for constants, and `tikool` / `tikool-plugin` as text domains.

For a custom pet product, create the WooCommerce product, then enable **Require pet photo** in its General settings. Its product page will require a pet name and a JPG, PNG, or WebP photo up to 10 MB. The file is stored outside public media paths and authorized staff can download it from the matching WooCommerce order line.

Run local checks before deployment:

```bash
find theme/tikool plugin/tikool-plugin plugin/tikool-ai plugin/tikool-memory-page -type f -name '*.php' -exec php -l {} \;
docker compose --env-file .env.example config --quiet
```

Deploy the AI plugin independently with `./scripts/deploy-component.sh ai`.

Deploy the Memory Page plugin independently with `./scripts/deploy-memory-page.sh`. Add a scene by creating `plugin/tikool-memory-page/templates/scenes/<slug>/scene.json` and `index.html`; the account editor discovers it automatically.

The deploy helpers default to `root@contabo2:/root/wordpress-sites/tikool-shop` but support `DEPLOY_HOST`, `DEPLOY_PATH`, and `DEPLOY_URL` overrides. They back up and replace only the selected custom theme or plugin; WordPress uploads, `.env`, and the database stay untouched.
