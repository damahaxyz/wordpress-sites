# No-supplies SKU revision — 2026-11-02

User direction: remove AA batteries and bubble liquid from machine SKU images; refill liquid purchased separately and customers supply their own AA batteries. Follow-up confirmed prices: single $29.90, double $49.90.

Current included contents: one machine + one sticker sheet, or two machines + two sticker sheets. Each machine requires four AA batteries, excluded. No solution bottle or concentrate sachets included. Existing title, slug, hero, gallery order, default choice, delivery settings and other product configuration are preserved by the update script.

Two built-in image_gen edits use the original single/double English SKU PNGs as references. Product details and festive scene retained; loose batteries, solution bottles, concentrate sachets and their included labels removed. Revised copy explicitly says solution sold separately and batteries not included. Exact prompts are in `imagegen-prompts.json`.

`retouched/` holds the PNG masters; `ready/` holds the WebPs and SHA-256 manifest. Both are 1254×1254, cwebp quality 86/method 6/sharp YUV. PNG total 4,590,186 bytes → WebP total 550,678 bytes (88.00% smaller). Individual WebPs 248,878 / 301,800 bytes. Only WebPs are uploaded.

Apply with `scripts/update-bubble-machine-no-supplies.php` through UID 33 WP-CLI and this directory mounted at `/revision`. The historical full-kit importer is blocked for the existing product; do not use it to update the current listing.
