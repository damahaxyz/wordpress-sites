# Machine-only SKU images and revised prices — 2026-11-02

Product 175: https://www.trovesia.com/products/2026-firework-bubble-machine-with-ambient-lights/

User requested removal of batteries and bubble liquid from SKU pictures, with refill purchased separately and AA batteries supplied by the customer. Follow-up explicitly requested single $29.90 / double $49.90. Pre-update live prices were $39.90 / $69.90; only the confirmed new prices were applied.

| Variation | Price | Image ID | Included |
| --- | ---: | ---: | --- |
| 176 / TV-BUBBLE-2026-SINGLE | 29.90 | 188 | 1 machine, 1 sticker sheet |
| 177 / TV-BUBBLE-2026-DUO | 49.90 | 189 | 2 machines, 2 sticker sheets |

Each machine requires four AA batteries, excluded. Neither pack includes a solution bottle or concentrate sachets. New pictures explicitly say bubble solution sold separately and batteries not included. Short description, detailed contents table, benefits, shipping text and variation descriptions updated consistently, with a link to the published refill product.

## Verification

- Two built-in image_gen edits inspected: machine structure/decals retained, loose batteries/bottles/sachets removed, sticker sheets retained, exclusion copy readable. Prompt set and masters archived under `product-assets/2026-firework-bubble-machine/revisions/20261102-no-supplies/`.
- Both 1254×1254 WebPs matched source dimensions and SHA-256 manifest; 248,878 / 301,800 bytes, total 550,678 bytes versus PNG 4,590,186 bytes (88.00% smaller). WordPress responsive images generated.
- Current Christmas product title, slug, publish status, hero, category IDs and empty defaults verified unchanged in the update script. Current video and optional refill add-ons remain visible; add-ons were not selected in testing.
- Gallery replaced only old SKU image IDs 169/170, preserving other image order. New images use versioned URLs to avoid stale cached files. Old media retained for history.
- Public page: correct price range, no old "AA batteries and bubble supplies included" benefit, new SKU images loaded; current variations selected at $29.90 / $49.90 with matching pictures.
- Native variation-price updates were allowed to finish before add-to-cart. Test cart began empty and contained one of each machine pack, line totals $29.90 / $49.90, estimated total $79.80. Evidence: `cart-evidence.json`, `cart-current-prices.png`.
- Both test rows removed; empty cart confirmed. No checkout/payment/order.
- Desktop screenshots: `single-desktop.png`, `double-desktop.png`. 390×844 mobile check: Two-Machine Set $49.90, no horizontal overflow (`double-mobile.png`). Temporary viewport reset.
- PHP syntax and Git whitespace checks passed. Original full-kit importer now refuses to overwrite an existing machine SKU; use the scoped update script after reviewing current settings.
- Page cache purged; pre/post security baseline and dynamic PHP homepage checks passed. No theme/plugin deployment.

Private backup: `/root/trovesia-backups/20261102T101359Z`.

Pre-update report: `security-reports/check-20261102T101042Z-2576961.log`.

Post-update report: `security-reports/check-20261102T103219Z-2591248.log`.
