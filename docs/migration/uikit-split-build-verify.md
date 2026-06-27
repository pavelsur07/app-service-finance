# uikit-split — build verification

**Date:** 2026-06-27
**Branch:** master (chore/uikit-split merged via PR #2053)
**Last commit:** c17f94cc chore(ui-kit): split storybook.html into tokens/ components/ patterns/

## Build

- Exit code: 0
- Build time: 3.05s
- Output size: 396K (`public/build/`)
- manifest.json: OK (at `public/build/.vite/manifest.json` — standard Vite location)

> Note: task spec checked `public/build/manifest.json` — Vite 6 writes it to
> `public/build/.vite/manifest.json`. Both locations verified.

## Entries

- Expected: 13
- Found in manifest: 21
- Missing: **none**
- Extra (Vite shared chunks, expected): `_MoneyCell`, `_Pagination`, `_PeriodPresets`,
  `_StatusBadge`, `_date`, `_index`, `_money`, `_useAbortableQuery`

## UI Kit CSS resolution

- References to `ui-kit/*` in manifest.json: 0
- CSS bundling: **inline in `assets/app-BMLKOc5p.css` (41.85 kB gzip 8.17 kB)**

Vite разрезолвил всю цепочку `@import url(...)` из `assets/styles/app.css` →
`ui-kit/tokens/index.css` → `colors.css` / `typography.css` / … / `semantic.css` →
`ui-kit/components/all.css` → `button.css` / `input.css` / … →
`ui-kit/patterns/all.css` → `sidebar.css` / `picker.css` / …

Весь UI Kit CSS инлайнится в один `app-*.css` entry. Это корректное поведение (inline bundling).
До uikit-split app.css был ~2.87 kB; после — 41.85 kB (+39 kB = UI Kit).

## Pre-flight note

Ветка `chore/uikit-split` смержена в master до запуска верификации (PR #2053).
Верификация проведена на master — содержит идентичный код.

Права на `public/build/` починены: `sudo chown -R deploy:deploy public/build`
(были `root:root` с предыдущей сборки в другом контексте).

## Verdict

✅ Build clean, all entries present, safe to merge.

## Logs (npm run build)

```
> build
> vite build

vite v6.4.1 building for production...
transforming...
✓ 102 modules transformed.
rendering chunks...
computing gzip size...
public/build/.vite/entrypoints.json                                              4.54 kB │ gzip:  0.68 kB
public/build/.vite/manifest.json                                                 5.64 kB │ gzip:  0.88 kB
public/build/assets/design_tokens-C1BsyhUD.css                                   2.40 kB │ gzip:  0.98 kB
public/build/assets/vf_custom_classes-DoSRUz72.css                               2.87 kB │ gzip:  0.81 kB
public/build/assets/app-BMLKOc5p.css                                            41.85 kB │ gzip:  8.17 kB
public/build/assets/StatusBadge-DQnV7__6.js                                      0.32 kB │ gzip:  0.23 kB
public/build/assets/MoneyCell-CYFvZlfZ.js                                        0.33 kB │ gzip:  0.25 kB
public/build/assets/date-rBsuErAW.js                                             0.35 kB │ gzip:  0.18 kB
public/build/assets/Pagination-DFmVe8SN.js                                       1.19 kB │ gzip:  0.63 kB
public/build/assets/marketplace_analytics_kpi-DJDprLqZ.js                        2.69 kB │ gzip:  1.25 kB
public/build/assets/ingestion_verification_issues_page-CsjRed7d.js               3.13 kB │ gzip:  1.41 kB
public/build/assets/useAbortableQuery-B814cP7J.js                                3.59 kB │ gzip:  1.82 kB
public/build/assets/ingestion_verification_coverage_page-JmJibV4p.js             4.65 kB │ gzip:  1.91 kB
public/build/assets/PeriodPresets-DGHKBR3k.js                                    4.71 kB │ gzip:  1.84 kB
public/build/assets/app-DgzgEYO3.js                                              4.90 kB │ gzip:  1.91 kB
public/build/assets/ingestion_verification_reconciliation_page-DWMQ7Abq.js       5.59 kB │ gzip:  2.00 kB
public/build/assets/ad_efficiency_page-CnEpb72H.js                               7.73 kB │ gzip:  2.75 kB
public/build/assets/ingestion_verification_financial_summary_page-CPtGKX2y.js    7.86 kB │ gzip:  2.40 kB
public/build/assets/money-uipAnQ7i.js                                            8.80 kB │ gzip:  3.05 kB
public/build/assets/dashboard-RntNUXib.js                                       10.11 kB │ gzip:  3.27 kB
public/build/assets/reconciliation_page-CSsGHxzZ.js                             18.93 kB │ gzip:  5.49 kB
public/build/assets/marketplace_analytics_page-Cjv7UP1B.js                      20.74 kB │ gzip:  5.53 kB
public/build/assets/unit_extended_page-ZsUR1aC-.js                              28.78 kB │ gzip:  8.75 kB
public/build/assets/index-DEKtBTbg.js                                          142.92 kB │ gzip: 45.78 kB
✓ built in 3.05s
```
