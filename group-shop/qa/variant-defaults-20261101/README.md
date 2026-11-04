# Product variation defaults — 2026-11-01

Product 101 had no saved default attributes, but the custom picker replaced an empty default with its first variation (Yellow) in PHP and JavaScript. Plugin 3.8.3 and theme 2.9.5 remove both fallbacks. Explicit valid defaults and native WooCommerce attribute links remain supported.

Before deployment, checksum comparison found no unrelated theme/plugin content differences. Deployment used `scripts/deploy-component.sh theme plugin`, including component backups, PHP checks, container health checks, permission enforcement and pre/post code-manifest verification. Existing product content, uploaded video, gallery order and product configuration were preserved.

## Checks

- Read-only `tests/variant-defaults-self-test.php`: 4 checks passed. Cloned objects verify no default stays empty, Pink stays Pink, an obsolete default stays empty, and persisted product defaults remain unchanged. All five color cards remain available. No product is saved.
- Anonymous public page without query parameters: empty native Color value and picker default, zero pressed cards, no positive variation ID, purchase button has `disabled wc-variation-selection-needed`. Recorded in `initial-state.json`.
- Actual UI selection of Pink: Color and pressed card become Pink; purchase button becomes enabled. No cart submission.
- `?attribute_color=Pink`: native URL preselection remains Pink.
- Native reset activated by keyboard: Color and pressed cards return to empty; purchase button requires selection again. The native reset link is visually clipped with the original select in this theme, so pointer clicks are not an appropriate way to test that hidden control.
- Product hero stays on the collection image when nothing is selected. Screenshot: `no-default-desktop.png`.
- PHP syntax, JavaScript syntax and Git whitespace checks passed; page cache purged after deployment.

Deployment backup marker: `20261101T154427Z` (server `deploy-backups/trovesia-20261101T154427Z.tar.gz` and `trovesia-plugin-20261101T154427Z.tar.gz`).

Pre-deployment security report: `security-reports/check-20261101T154437Z-1820211.log`.

Post-deployment security report: `security-reports/check-20261101T154650Z-1821995.log`.
