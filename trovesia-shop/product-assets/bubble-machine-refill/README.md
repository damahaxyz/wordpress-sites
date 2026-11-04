# Bubble Machine Refill Concentrate Sachets

User-supplied pink sachet reference: `source/bubble-concentrate-sachets.png` (2026-11-02). This is a standalone refill product; no machine, battery, bottle or other machine accessories are included.

| Pack | SKU | USD |
| --- | --- | ---: |
| 10 Sachets | TV-BUBBLE-REFILL-10 | 12.90 |
| 30 Sachets | TV-BUBBLE-REFILL-30 | 19.90 |
| 50 Sachets | TV-BUBBLE-REFILL-50 | 29.90 |

Parent SKU: `TV-BUBBLE-MACHINE-REFILL`. Category Party & Celebration. Delivery follows the related machine listing, 5–15 days. No default pack or artificial compare-at price.

Four English assets were produced with built-in image_gen: hero and one labelled illustration for each pack size. The five-packet reference fan demonstrates the packaging, while the selected pack label specifies delivered quantity. Original Chinese packaging print is retained; English copy outside the packet states concentrate/refill-only/machine-not-included. No unverified dilution ratio, non-toxic claim or performance claim is added. Listing instructions defer to the sachet and machine instructions.

- `retouched/`: PNG masters.
- `ready/`: four 1254×1254 production WebPs, quality 86 / method 6 / sharp YUV.
- `ready/manifest.json`: SHA-256, dimensions, exact before/after sizes.
- `imagegen-prompts.json`: exact built-in image prompts and reference.
- `scripts/prepare-bubble-refill-images.py`: regenerate the compressed files from PNG masters.
- `scripts/import-bubble-machine-refill.php`: create/update a draft and validate prices; `REFILL_STATUS=publish` publishes after validation. Mount this asset folder at `/import` for UID 33 WP-CLI.

Four PNGs total 6,842,399 bytes; WebP total 527,694 bytes, down 92.29%. Individual files are 120,532–146,616 bytes. Only WebP assets are imported; original uploads and PNG masters stay local.
