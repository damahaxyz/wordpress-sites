# 2026 Firework Bubble Machine

Source: user-provided `/Users/dodo/Downloads/烟花泡泡机/` and the supplier variant screenshot, 2026-11-02. All original files are preserved in `source/`. The user later changed fulfillment: **AA batteries and all bubble solution are excluded; solution is purchased separately and customers supply their own AA batteries.** Supplier bundle pictures are historical visual references, not the current packing list.

| Pack | Machines included | Sticker sheets included | AA batteries to supply | USD |
| --- | ---: | ---: | ---: | ---: |
| Single Machine Set | 1 | 1 | 4 | 29.90 |
| Two-Machine Set | 2 | 2 | 8 | 49.90 |

User-confirmed delivery: 5–15 days. Main requested features: new for 2026, fully automatic electric operation, ambient lights. Source dimensions: approximately 15.5 cm high × 6.7 cm wide. No default pack is selected. Refill solution is sold through the standalone Bubble Machine Refill Concentrate Sachets listing. Current SKU image masters/WebPs/prompts are in `revisions/20261102-no-supplies/`.

## Image workflow

Seven original English assets were produced with built-in `image_gen`: hero, single set, double set, one-touch controls, bubble outlets, refillable reservoir and dimensions. Product appearance, controls and vent patterns were kept as reference constraints. The two original full-kit SKU images are superseded by the no-supplies revision; originals remain archived, while hero and detail images are retained. Original prompts are recorded in `imagegen-prompts.json`; revision prompts are recorded in the revision directory.

The source's unverified bubble-output count, non-toxic claim, battery-life claim and rechargeable alternative are omitted from the listing. The product is presented as a soap-bubble machine with lighting, without pyrotechnic effects.

- `retouched/`: English PNG masters, retained locally.
- `ready/`: original WebP assets, quality 86 / cwebp method 6 / sharp YUV, original pixel dimensions preserved. Original single/double full-kit images are historical and must not be republished.
- `ready/manifest.json`: SHA-256, dimensions, encoding quality and exact before/after sizes.
- Re-encode using `scripts/prepare-2026-bubble-images.py` from the project root.
- `scripts/import-2026-firework-bubble-machine.php` is the historical initial importer, now blocked for an existing machine SKU to prevent overwriting later title/price/fulfillment edits. Current targeted revision uses `scripts/update-bubble-machine-no-supplies.php`, with the revision directory mounted at `/revision`.

Seven full-size PNGs total 15,921,727 bytes; WebP files total 1,596,618 bytes (89.97% smaller). Individual files are 106,910–292,372 bytes. Only WebP images are imported to the media library; WordPress generates responsive WebP thumbnails.

Parent SKU: `TV-2026-FIREWORK-BUBBLE`. Variation SKUs: `TV-BUBBLE-2026-SINGLE`, `TV-BUBBLE-2026-DUO`.
