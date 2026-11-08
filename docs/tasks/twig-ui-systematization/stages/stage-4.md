### Stage 4: CSS из шаблонов — PLANNED

**Risk:** LOW (перенос правил 1:1 в страничные CSS-файлы, затем токены; визуал не меняется)
**Stage base commit:** master после закрывающего PR Stage 3
**Work items:** 4.0–4.2 (`plan.md`, раздел Stage 4)

#### Задача
Убрать `<style>` и `style="…"` из шаблонов: правила — в `assets/styles/pages/<page>.css`, подключённые через
существующие entry (`legacy_app` — 13 страниц, `admin_shell` — 2), как `pages/budget.css`. Значения — через
`--vf-*`/semantic-токены, hex и «магические» px без токена помечаются `TODO(ds)`.

#### Замер «до» (08.11.2026, master после PR D Stage 3)
- `<style>` вне `notifications/email` — 15 файлов (план: 15). По размеру блока (строк):
  `cashflow_category/index` 127, `finance/report/preview` 89, `marketplace/pl_preview/index` 83,
  `integrations/marketplace` 64, `pl_category/index` 60, `home/index` 43, `report/cashflow` 39 — это 7 блоков 4.1
  (505 строк); остальные 8 — `marketplace/admin/mapping_error_list` 32 (admin), `profile/money_account/new` 14,
  `marketplace_ads/admin_debug` 12, `payment_calendar/index` 8, `admin/service_gateway/coverage` 7 (admin),
  `finance/report/raw` 6, `cash_transaction_auto_rule/index` 6, `cash/bank1c_import_preview` 5.
- `style="…"` — 182 (план: 188). Концентрация в админке: `billing/admin/company` 36, `admin/ai/company` 21,
  `admin/ingestion/external_categories/index` 16, `admin/users/index` 13 (86 из 182); дальше `marketplace/connections` 7,
  `budget/plan_fact` 7, `billing/client/tariff` 6, остальные ≤ 4 на файл.
- Entry по layout: `base`/`marketplace/layout` → `legacy_app` (13 файлов); `admin/base` → `admin_shell` (2 файла).

#### Разбиение на PR
- PR A (4.0 + 4.1): скриншоты «до» 7 страниц на стенде (память `local-visual-check`); 7 блоков → `assets/styles/pages/`
  (один файл на страницу, селекторы обёрнуты корнем страницы — `data-page="…"` или существующий корневой класс);
  `npm run build`; скриншоты «после», побайтное сравнение CSS-правил.
- PR B (4.2, часть 1): 8 оставшихся `<style>` → страничные CSS (`admin_shell` для двух админских); 0 `<style>` вне писем.
- PR C (4.2, часть 2): `style="…"` админки (86) → классы кита/утилиты `u-*` или страничный CSS; динамические значения
  (ширина прогресса, цвет из данных) — через CSS-переменную в `style`, остаются в лимите ≤ 20.
- PR D (4.2, часть 3): `style="…"` легаси (~96) → классы Tabler (`w-1`, `text-end`, `mw-*`) или страничный CSS.

#### Чек-лист приёмки
- [ ] 4.0 — скриншоты «до» 7 страниц 4.1 в `docs/tasks/twig-ui-systematization/screens/stage-4/`
- [ ] 4.1 — 7 блоков перенесены, `grep -rl '<style' templates | grep -v notifications/email` → 8
- [ ] 4.2 — `<style>` вне писем → 0; `grep -rho 'style="' templates | wc -l` ≤ 20, каждое оставшееся — динамическое значение
- [ ] Значения в новых CSS — токены `--vf-*`; остатки hex/px — с `TODO(ds)`, их число в отчёте
- [ ] Каждый PR: `npm run build`, `make site-cs-twig`, `make site-test`, внешнее ревью; скриншоты до/после для 4.1

#### Reviewer focus
- Специфичность: правило из `<style>` побеждало всё на странице; в общем файле — обернуть корневым селектором страницы,
  иначе утечёт на другие экраны (особенно `.card`, `.table`, `td`).
- Порядок загрузки проверен: `_layout/legacy` подключает Tabler CDN, затем `design_tokens`, `vf_custom_classes`,
  `legacy_app` — бандл идёт после Tabler, как и прежние `<style>`; каскад не меняется. Специфичность внутри страничного
  файла сохранять как в блоке (не «упрощать» селекторы).
