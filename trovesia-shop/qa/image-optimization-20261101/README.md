# Christmas image optimization — 2026-11-01

Optimized 13 existing media attachments for the Christmas gift set, its two add-on images, and the desktop/mobile storefront hero. Pixel dimensions and attachment IDs are preserved; original PNGs and their thumbnails remain available for rollback. No theme or plugin deployment was required.

| Asset group | PNG bytes | WebP bytes | Reduction |
| --- | ---: | ---: | ---: |
| Product gallery (9) | 12,609,524 | 926,358 | 92.65% |
| Desktop hero | 2,169,092 | 208,176 | 90.40% |
| Mobile hero | 2,203,297 | 213,808 | 90.30% |
| Add-on images (2) | 2,947,834 | 173,412 | 94.12% |
| Total | 19,929,747 | 1,521,754 | 92.36% |

The byte totals cover full-size files; responsive thumbnails are additional and were also regenerated as WebP. Encoding uses cwebp quality 84, method 6 and sharp YUV; diagram/feature images d2/d3 use quality 90. Visual inspection confirmed readable English labels and retained product detail. The manifest in `../../product-assets/focus-spark-atmosphere-wand/optimized/manifest.json` records dimensions, exact sizes and source/output SHA-256 values.

## Verification

- WordPress retained attachment IDs 95, 107–113, 115–117 and 125–126, existing import keys and alt text. The apply script validates all source hashes before copying; all previous registered thumbnail sizes are required before references change.
- An initial prepare-only attempt encountered a Redis read timeout. No attachment references had changed. Redis PING and all four container health checks passed; rerunning with CLI-only `WP_REDIS_READ_TIMEOUT=10` completed successfully. Website Redis settings were untouched.
- Public anonymous product page: gallery sources, thumbnails, variation card images and add-on images use WebP; all inspected product images had `complete=true` and positive natural width.
- Each color was selected in the actual UI. `color-switching.json` records the selected color and corresponding displayed 600px WebP, with all five loaded successfully. Selection restored to the initial Yellow value; no cart submission occurred.
- Desktop homepage background uses `christmas-storefront-desktop.webp`; at 390×844 it uses `christmas-storefront-mobile.webp`. Both rendered successfully. Temporary viewport override reset.
- Public Cloudflare responses for desktop/mobile heroes: HTTP 200, Content-Type `image/webp`, Content-Length 208176/213808, CF-Cache-Status HIT.
- Trovesia page cache purged successfully. Pre/post security baselines passed, including unchanged code manifest and real PHP homepage health.
- PHP syntax, Python compilation and Git whitespace checks passed.

Screenshots: `homepage-desktop.png`, `product-desktop.png`. Five-color evidence: `color-switching.json`.

Private server rollback backup: `/root/trovesia-backups/20261101T150246Z`.

Security reports:

- Before: `/root/wordpress-sites/trovesia-shop/security-reports/check-20261101T150040Z-1783831.log`
- After: `/root/wordpress-sites/trovesia-shop/security-reports/check-20261101T150858Z-1791611.log`
