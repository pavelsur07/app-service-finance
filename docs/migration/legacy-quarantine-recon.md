# Legacy Quarantine — Recon Report

**Generated at:** 2026-06-25 (автономная разведка)
**By:** Claude Code (autonomous, legacy-quarantine-recon task)
**Status:** approved by Owner, ready for execute

> Все пути в отчёте относительны корня проекта `site/` (где живут `vite.config.js`, `assets/`, `templates/`).

---

## TL;DR

- Vite entries to redirect: **10** (из 13 total, 3 остаются: app, design_tokens, vf_custom_classes)
- Files to move to `_legacy/`: **95 файлов** (10 top-level .tsx + 85 в 6 директориях, после удаления SnapshotListDemo.tsx)
- Imports that will break and need fixing: **1** (`ingestion-verification/types.ts:1`)
- `assets/api/` stays in place: **yes**
- `assets/controllers/` stays in place: **yes** (Stimulus не импортирует из `react/shared/`)
- `assets/react/shared/` recommendation: **move → Variant A** (locked)
- Estimated execution time for migration PR: **~2–3 часа** (mv + 1 fix + build verify)
- Risk level: 🟡 MEDIUM (single atomic PR, reversible by `git revert`)

---

## 1. Current state

### Vite config

- Config file: `vite.config.js` (не `.ts`)
- Build output: `public/build/`
- Manifest: enabled (vite-plugin-symfony)
- Alias в `vite.config.js`: `@` → `assets/` (вся папка assets, не только react)
- Alias в `tsconfig.json` paths: `@/*` → `assets/*` (идентично)
- Total entries: **13**

### Directory inventory

- `assets/react/` — top-level .tsx файлов: **10**, subdirs: **6** (Dashboard, ingestion-verification, marketplace-ads, marketplace-analytics, reconciliation, shared)
- `assets/api/` — существует (`client.ts`, `schema.d.ts`, `README.md`), will stay
- `assets/controllers/` — существует (2 Stimulus файла: `csrf_protection_controller.js`, `hello_controller.js`), will stay
- `assets/react/_legacy/` — **не существует** (будет создана в миграционном PR)
- `assets/react/modules/` — **не существует**
- `assets/react/ui-kit/` — **не существует**

---

## 2. Vite entries

| Entry name | Current path | Category | Will move | New path |
|---|---|---|---|---|
| `app` | `assets/app.js` | other | ❌ | — |
| `design_tokens` | `assets/styles/design-tokens.css` | stylesheet | ❌ | — |
| `vf_custom_classes` | `assets/styles/vf-custom-classes.css` | stylesheet | ❌ | — |
| `dashboard` | `assets/react/dashboard_started.tsx` | react-page | ✅ | `assets/react/_legacy/dashboard_started.tsx` |
| `marketplace_analytics_kpi` | `assets/react/marketplace_analytics_kpi.tsx` | react-widget | ✅ | `assets/react/_legacy/marketplace_analytics_kpi.tsx` |
| `marketplace_analytics_page` | `assets/react/marketplace-analytics-page.tsx` | react-page | ✅ | `assets/react/_legacy/marketplace-analytics-page.tsx` |
| `reconciliation_page` | `assets/react/reconciliation-page.tsx` | react-page | ✅ | `assets/react/_legacy/reconciliation-page.tsx` |
| `unit_extended_page` | `assets/react/unit-extended-page.tsx` | react-page | ✅ | `assets/react/_legacy/unit-extended-page.tsx` |
| `ad_efficiency_page` | `assets/react/ad-efficiency-page.tsx` | react-page | ✅ | `assets/react/_legacy/ad-efficiency-page.tsx` |
| `ingestion_verification_coverage_page` | `assets/react/ingestion-verification-coverage-page.tsx` | react-page | ✅ | `assets/react/_legacy/ingestion-verification-coverage-page.tsx` |
| `ingestion_verification_reconciliation_page` | `assets/react/ingestion-verification-reconciliation-page.tsx` | react-page | ✅ | `assets/react/_legacy/ingestion-verification-reconciliation-page.tsx` |
| `ingestion_verification_issues_page` | `assets/react/ingestion-verification-issues-page.tsx` | react-page | ✅ | `assets/react/_legacy/ingestion-verification-issues-page.tsx` |
| `ingestion_verification_financial_summary_page` | `assets/react/ingestion-verification-financial-summary-page.tsx` | react-page | ✅ | `assets/react/_legacy/ingestion-verification-financial-summary-page.tsx` |

**Итого:** 10 entries перемещаются, 3 остаются.

---

## 3. Twig usage of entries

| Entry | Twig file | Line | Helper |
|---|---|---|---|
| `design_tokens` | `templates/base.html.twig` | 14 | `vite_entry_link_tags` |
| `vf_custom_classes` | `templates/base.html.twig` | 15 | `vite_entry_link_tags` |
| `app` | `templates/base.html.twig` | 16, 19, 62 | `vite_entry_link_tags` / `vite_entry_script_tags` |
| `app` | `templates/security/base.html.twig` | 12, 14, 33 | `vite_entry_link_tags` / `vite_entry_script_tags` |
| `dashboard` | `templates/home/index.html.twig` | 10 | `vite_entry_script_tags` |
| `marketplace_analytics_page` | `templates/marketplace_analytics/index.html.twig` | 24 | `vite_entry_script_tags` |
| `marketplace_analytics_kpi` | `templates/marketplace/analytics/_kpi_cards.html.twig` | 4 | `vite_entry_script_tags` |
| `unit_extended_page` | `templates/marketplace_analytics/unit_extended/index.html.twig` | 24 | `vite_entry_script_tags` |
| `reconciliation_page` | `templates/marketplace/reconciliation.html.twig` | 13 | `vite_entry_script_tags` |
| `ad_efficiency_page` | `templates/marketplace_ads/efficiency/index.html.twig` | 30 | `vite_entry_script_tags` |
| `ingestion_verification_coverage_page` | `templates/ingestion/verification/coverage.html.twig` | 23 | `vite_entry_script_tags` |
| `ingestion_verification_reconciliation_page` | `templates/ingestion/verification/reconciliation.html.twig` | 16 | `vite_entry_script_tags` |
| `ingestion_verification_issues_page` | `templates/ingestion/verification/issues.html.twig` | 16 | `vite_entry_script_tags` |
| `ingestion_verification_financial_summary_page` | `templates/ingestion/verification/financial-summary.html.twig` | 16 | `vite_entry_script_tags` |

**Vital:** Entry names (ключи `rollupOptions.input`) сохраняются — Twig-файлы в миграционном PR **не трогать**.

---

## 4. Imports analysis

### Ключевые факты об alias `@/`

`@/` → `assets/` (не `assets/react/`).
Значит `@/api/schema` → `assets/api/schema`, `@/api/client` → `assets/api/client`.
Alias разрешается Vite/TS относительно `site/`, **не зависит от физического положения файла**.

### A. Файлы с риском `will-survive`

После переноса как блок в `_legacy/` они не ломаются — все импорты либо external, либо alias `@/`, либо relative внутри своей подпапки.

- `dashboard_started.tsx`, `marketplace_analytics_kpi.tsx`, `marketplace-analytics-page.tsx`, `reconciliation-page.tsx`, `unit-extended-page.tsx`, `ad-efficiency-page.tsx`
- `ingestion-verification-*-page.tsx` (4 файла)
- `Dashboard/**` (10 файлов)
- `marketplace-analytics/hooks/*`, `marketplace-analytics/unit-extended/**` (включая widgets)
- `marketplace-ads/**` (5 файлов)
- `reconciliation/**` (8 файлов)
- `shared/**` (9 файлов)

### B. Файлы с риском `will-break`

| Файл | Проблемный импорт |
|---|---|
| `ingestion-verification/types.ts` | L1: `import type { operations } from '../../api/schema'` |

### C. Итог

| Метрика | Значение |
|---|---|
| Total files | 95 |
| Will survive | 94 |
| Will break | **1** |

---

## 5. Dependencies decisions

### Stay in place

| Что | Причина |
|---|---|
| `assets/api/` | Общий API-клиент, нужен новым `modules/`; импортируется через `@/api/...` |
| `assets/controllers/` | Stimulus; не импортирует ничего из `assets/react/shared/` |
| `vite.config.js`, `tsconfig.json`, `package.json` | Корневые конфиги |

### Move to `_legacy/`

**Директории (6):**

| Откуда | Куда |
|---|---|
| `assets/react/Dashboard/` | `assets/react/_legacy/Dashboard/` |
| `assets/react/ingestion-verification/` | `assets/react/_legacy/ingestion-verification/` |
| `assets/react/marketplace-ads/` | `assets/react/_legacy/marketplace-ads/` |
| `assets/react/marketplace-analytics/` | `assets/react/_legacy/marketplace-analytics/` |
| `assets/react/reconciliation/` | `assets/react/_legacy/reconciliation/` |
| `assets/react/shared/` | `assets/react/_legacy/shared/` (Variant A, locked) |

**Top-level файлы (10):**

| Откуда | Куда |
|---|---|
| `dashboard_started.tsx` | `_legacy/dashboard_started.tsx` |
| `marketplace_analytics_kpi.tsx` | `_legacy/marketplace_analytics_kpi.tsx` |
| `marketplace-analytics-page.tsx` | `_legacy/marketplace-analytics-page.tsx` |
| `reconciliation-page.tsx` | `_legacy/reconciliation-page.tsx` |
| `unit-extended-page.tsx` | `_legacy/unit-extended-page.tsx` |
| `ad-efficiency-page.tsx` | `_legacy/ad-efficiency-page.tsx` |
| `ingestion-verification-coverage-page.tsx` | `_legacy/ingestion-verification-coverage-page.tsx` |
| `ingestion-verification-reconciliation-page.tsx` | `_legacy/ingestion-verification-reconciliation-page.tsx` |
| `ingestion-verification-issues-page.tsx` | `_legacy/ingestion-verification-issues-page.tsx` |
| `ingestion-verification-financial-summary-page.tsx` | `_legacy/ingestion-verification-financial-summary-page.tsx` |

### Variant A — shared/ → _legacy/shared/ (locked by Owner)

- Все `../../shared/...` пути из legacy-модулей продолжают работать без изменений.
- `assets/react/shared/` освобождается для новых `modules/`.
- Количество дополнительных правок импортов при Варианте A: **0**.

---

## 6. Broken imports + fix plan

**Единственный breaking import:**

```
File: assets/react/ingestion-verification/types.ts
After move: assets/react/_legacy/ingestion-verification/types.ts

L1: import type { operations } from '../../api/schema';

После переноса этот относительный путь укажет на assets/react/api/schema (файла нет).

Fix (locked by Owner) — alias:
  L1: import type { operations } from '@/api/schema';
```

Все остальные `../../shared/...` и `../../../shared/...` импорты — NOT breaking при Variant A. Математика сохраняется: оба модуля сдвинулись на один уровень глубже вместе.

---

## 7. Migration execution plan

### Step 1 — Create directory

```bash
mkdir -p assets/react/_legacy
```

### Step 2 — git mv (16 операций)

```bash
# Директории (6)
git mv assets/react/Dashboard                assets/react/_legacy/Dashboard
git mv assets/react/ingestion-verification   assets/react/_legacy/ingestion-verification
git mv assets/react/marketplace-ads          assets/react/_legacy/marketplace-ads
git mv assets/react/marketplace-analytics    assets/react/_legacy/marketplace-analytics
git mv assets/react/reconciliation           assets/react/_legacy/reconciliation
git mv assets/react/shared                   assets/react/_legacy/shared

# Top-level файлы (10)
git mv assets/react/dashboard_started.tsx                              assets/react/_legacy/dashboard_started.tsx
git mv assets/react/marketplace_analytics_kpi.tsx                      assets/react/_legacy/marketplace_analytics_kpi.tsx
git mv assets/react/marketplace-analytics-page.tsx                     assets/react/_legacy/marketplace-analytics-page.tsx
git mv assets/react/reconciliation-page.tsx                            assets/react/_legacy/reconciliation-page.tsx
git mv assets/react/unit-extended-page.tsx                             assets/react/_legacy/unit-extended-page.tsx
git mv assets/react/ad-efficiency-page.tsx                             assets/react/_legacy/ad-efficiency-page.tsx
git mv assets/react/ingestion-verification-coverage-page.tsx           assets/react/_legacy/ingestion-verification-coverage-page.tsx
git mv assets/react/ingestion-verification-reconciliation-page.tsx     assets/react/_legacy/ingestion-verification-reconciliation-page.tsx
git mv assets/react/ingestion-verification-issues-page.tsx             assets/react/_legacy/ingestion-verification-issues-page.tsx
git mv assets/react/ingestion-verification-financial-summary-page.tsx  assets/react/_legacy/ingestion-verification-financial-summary-page.tsx
```

### Step 3 — Fix broken import

Файл: `assets/react/_legacy/ingestion-verification/types.ts`

```diff
- import type { operations } from '../../api/schema';
+ import type { operations } from '@/api/schema';
```

### Step 4 — Update vite.config.js

Менять только пути, не имена. 10 строк.

```js
// BEFORE → AFTER:
dashboard: "./assets/react/dashboard_started.tsx"
  → "./assets/react/_legacy/dashboard_started.tsx"
marketplace_analytics_kpi: "./assets/react/marketplace_analytics_kpi.tsx"
  → "./assets/react/_legacy/marketplace_analytics_kpi.tsx"
marketplace_analytics_page: "./assets/react/marketplace-analytics-page.tsx"
  → "./assets/react/_legacy/marketplace-analytics-page.tsx"
reconciliation_page: "./assets/react/reconciliation-page.tsx"
  → "./assets/react/_legacy/reconciliation-page.tsx"
unit_extended_page: "./assets/react/unit-extended-page.tsx"
  → "./assets/react/_legacy/unit-extended-page.tsx"
ad_efficiency_page: "./assets/react/ad-efficiency-page.tsx"
  → "./assets/react/_legacy/ad-efficiency-page.tsx"
ingestion_verification_coverage_page: "./assets/react/ingestion-verification-coverage-page.tsx"
  → "./assets/react/_legacy/ingestion-verification-coverage-page.tsx"
ingestion_verification_reconciliation_page: "./assets/react/ingestion-verification-reconciliation-page.tsx"
  → "./assets/react/_legacy/ingestion-verification-reconciliation-page.tsx"
ingestion_verification_issues_page: "./assets/react/ingestion-verification-issues-page.tsx"
  → "./assets/react/_legacy/ingestion-verification-issues-page.tsx"
ingestion_verification_financial_summary_page: "./assets/react/ingestion-verification-financial-summary-page.tsx"
  → "./assets/react/_legacy/ingestion-verification-financial-summary-page.tsx"
```

Три entry **не трогать**: `app`, `design_tokens`, `vf_custom_classes`.

### Step 5 — Verify

```bash
cd ~/projects/app-service-finance/site
npm run build                                # ожидаемо: 0 ошибок
node tools/check-ui-kit-classes.mjs          # ожидаемо: 0 нарушений
node tools/check-uikit-react-mapping.mjs     # ожидаемо: 0 нарушений
```

---

## 8. Smoke checklist

| URL | Entry | Expected behaviour | Critical? |
|---|---|---|---|
| `/dashboard` | `dashboard` | Дашборд рендерится, KPI видны | ✅ |
| `/marketplace-analytics` | `marketplace_analytics_page` + `marketplace_analytics_kpi` | Таблица снапшотов + KPI-карточки | ✅ |
| `/marketplace-analytics/unit-extended` | `unit_extended_page` | Unit-extended таблица, фильтры | ✅ |
| `/marketplace/reconciliation` | `reconciliation_page` | Виджет загрузки и история | ✅ |
| `/marketplace-ads/efficiency` | `ad_efficiency_page` | Таблица эффективности, пагинация | ✅ |
| `/ingestion/verification/coverage` | `ingestion_verification_coverage_page` | Heatmap ковержа | ✅ |
| `/ingestion/verification/reconciliation` | `ingestion_verification_reconciliation_page` | Таблица сводки | ✅ |
| `/ingestion/verification/issues` | `ingestion_verification_issues_page` | Список issues, пагинация | ✅ |
| `/ingestion/verification/financial-summary` | `ingestion_verification_financial_summary_page` | Financial summary таблица | ✅ |

Для каждой страницы: DevTools Console — без ошибок, Network — все API возвращают 200.

---

## 9. Rollback plan

```bash
git revert <commit-sha-of-quarantine>
```

Атомарный PR → один `revert` возвращает в исходное состояние.

---

## 10. Risks and unknowns

- **R1 (CLOSED):** `SnapshotListDemo.tsx` — удалён отдельным коммитом до карантина как мёртвый код.
- **R2 (LOW):** `vite.config.js` — не `.ts`. Не время для конвертации, оставить как есть.
- **R3 (CLOSED):** Symfony `services.yaml` — нет ссылок на entry-имена.
- **R4 (CLOSED):** Stimulus не импортирует из `assets/react/shared/`. Перенос безопасен.
- **R5 (LOW):** `Dashboard/` — сосуществуют `DashboardGrid.js` + `DashboardGrid.tsx` (re-export). Переедут вместе, проблем нет.

---

## 11. Owner decisions (locked)

1. **`assets/react/shared/`:** Variant A — перенести в `_legacy/shared/`.
2. **`ingestion-verification/types.ts:1`:** alias `'@/api/schema'`.
3. **Active branches blocking quarantine:** none.
4. **Smoke checklist:** 9 URL (раздел 8), полнота подтверждена через `debug:router` и grep по Twig.
5. **SnapshotListDemo.tsx:** удалён до карантина.

---

## 12. Next step

Отдельная задача «Legacy Quarantine Execute» (`legacy-quarantine-execute.md`) выполняет план из раздела 7 и проходит smoke из раздела 8 в ветке `chore/legacy-quarantine`.

---

🛑 **STOP. Recon report saved. Ready for execute task.**