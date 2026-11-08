### Stage 4: CSS из шаблонов — IN PROGRESS (PR A на проде, PR B на handoff)

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
- [x] 4.0 — скриншоты «до» 7 страниц 4.1 в `docs/tasks/twig-ui-systematization/screens/stage-4/` (PR A, 1366×900 fullPage)
- [x] 4.1 — 7 блоков перенесены, `grep -rl '<style' templates | grep -v notifications/email` → 8 (PR A)
- [~] 4.2 — `<style>` вне писем → 0; `grep -rho 'style="' templates | wc -l` ≤ 20, каждое оставшееся — динамическое значение
  - [x] часть 1 (PR B): 8 блоков `<style>` → страничные CSS, `grep -rl '<style' templates | grep -v notifications/email` → 0
- [ ] Значения в новых CSS — токены `--vf-*`; остатки hex/px — с `TODO(ds)`, их число в отчёте
- [ ] Каждый PR: `npm run build`, `make site-cs-twig`, `make site-test`, внешнее ревью; скриншоты до/после для 4.1

#### Reviewer focus
- Специфичность: правило из `<style>` побеждало всё на странице; в общем файле — обернуть корневым селектором страницы,
  иначе утечёт на другие экраны (особенно `.card`, `.table`, `td`).
- Порядок загрузки проверен: `_layout/legacy` подключает Tabler CDN, затем `design_tokens`, `vf_custom_classes`,
  `legacy_app` — бандл идёт после Tabler, как и прежние `<style>`; каскад не меняется. Специфичность внутри страничного
  файла сохранять как в блоке (не «упрощать» селекторы).

#### PR A (4.0 + 4.1) — семь блоков `<style>` → `assets/styles/pages/*.css`

##### Что сделано
- Новые файлы в `assets/styles/pages/`: `cashflow-categories.css`, `pl-categories.css`, `pl-report.css` (`.table.pl-table`),
  `pl-preview.css` (`.table.pl-preview-table`), `cashflow-report.css` (`.table.cf-table`), `dashboard.css`, `integrations.css`
  и общий `pl-filter-controls.css` (бывший партиал `finance/report/_filter_controls_styles.html.twig`, включался в три блока —
  удалён). Все подключены в `assets/legacy-app.js` (entry `legacy_app`); порядок каскада прежний — бандл грузится после Tabler.
- Правила перенесены 1:1 (dedent, без правки значений). Пять файлов с уникальными префиксами (`cashflow-category-*`,
  `pl-category-*`, `pl-table`, `pl-preview-table`, `cf-table`) — без обёртки. Два блока содержали общие селекторы
  (`body`, `.row-cards`, `.card-title`, `.card-body-p-0`, `.section-title`, `#modal-setup`) и в общем бандле утекли бы на все
  страницы — обёрнуты классом `<body>`: `_layout/legacy` получил `{% block body_class %}`, страницы задают
  `page-dashboard` / `page-integrations`.
- `{% block stylesheets %}` удалён там, где остался пустым; `home/index` сохраняет свой (entry `finance_balance_dynamics`).
- Токены: значения не менялись (переносы 1:1); hex/px без токена остались как были — замена на `--vf-*` и `TODO(ds)` — отдельным
  проходом после 4.2, когда весь CSS будет в файлах.

##### Проверка визуала
- Стенд (память `local-visual-check`): `npm run build`, логин, 7 страниц, 1366×900 fullPage, до и после.
  Пиксельное сравнение (Pillow `ImageChops.difference`): все 7 — identical. Скриншоты «до» — `screens/stage-4/`.

##### Files changed (PR A)
- `site/assets/styles/pages/{cashflow-categories,pl-categories,pl-report,pl-preview,cashflow-report,dashboard,integrations,pl-filter-controls}.css` (new),
  `site/assets/legacy-app.js`, `site/templates/_layout/legacy.html.twig`, 7 шаблонов, партиал `_filter_controls_styles` удалён,
  `docs/tasks/twig-ui-systematization/screens/stage-4/*.png` (7), `tests/Functional/Finance/PlReportPreviewControllerTest.php` (assert на CSS-файл)

##### Проверки (PR A)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (323); `npm run lint` — 0; `npm run build` — OK; CS fixer и PHPStan точечно по изменённому тесту — чисто
- `make site-test` — OK — 8554 тестов, 48 145 assertions (один тест `PlReportPreviewControllerTest::testGroupingUsesReadableDataColumnWidths` искал переменные ширин колонок в HTML страницы, где был `<style>`; теперь проверяет `col.pl-col-total` в HTML и переменные в `pl-report.css`; класс перепрогнан зелёным, 16/16)
- Внешнее ревью (codex): REVIEW_GREEN (effort medium, дифф 1773 строк, замечаний нет)

#### PR B (4.2, часть 1) — оставшиеся 8 блоков `<style>` → страничные CSS

##### Что сделано
- Легаси (entry `legacy_app`, импорт в `assets/legacy-app.js`): `auto-rules.css` (`.auto-rule-list`), `report-raw.css`,
  `marketplace-ads-debug.css` (`.debug-pre`), `bank1c-import-preview.css` (`.preview-row-*`), `payment-calendar.css`
  (`.table-sticky-head`, используется в `index` и `_table_compact` одной страницы), `money-account-new.css`
  (`#money-account-create-page …`).
- Админка (entry `admin_shell`): `service-gateway-coverage.css` (`.sgw-cov-*`) и `mapping-errors.css`
  (`[data-mapping-errors-table]`) подключены `@import` в начале `admin-shell.css` — до остальных правил, как требует CSS;
  блоки `{% block admin_styles %}` у двух страниц удалены. Селекторы уникальны, утечки на другие админ-экраны нет.
- `finance/report/raw` держал общие имена `.muted/.warn/.ok/.mono` (`.warn`/`.ok` есть у документов и маркетплейса,
  `.mono` — у Telegram-бота): правила привязаны к `body.page-report-raw` через `{% block body_class %}` из PR A.
- Правила перенесены 1:1: сверка множеств деклараций «удалено из шаблонов» ↔ «новые файлы» — совпадают (58 деклараций).
- Пустые `{% block stylesheets %}` сняты. Вне писем `<style>` в шаблонах больше нет (0).

##### Проверка визуала
- Стенд, 8 страниц (все GET), 1366×900 fullPage, до и после — все identical (Pillow). Скриншоты «до» —
  `screens/stage-4/pr-b/`. Оговорка: автоправила, платёжный календарь, предпросмотр 1С и неизвестные затраты на стенде
  без данных — строки таблиц (sticky-шапка, подсветка строк) не отрисованы; для них гарантия — сверка деклараций 1:1.

##### Files changed (PR B)
- `site/assets/styles/pages/{auto-rules,report-raw,marketplace-ads-debug,bank1c-import-preview,payment-calendar,money-account-new,service-gateway-coverage,mapping-errors}.css` (new),
  `site/assets/legacy-app.js`, `site/assets/styles/pages/admin-shell.css`, 8 шаблонов, `screens/stage-4/pr-b/*.png` (8)

##### Проверки (PR B)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (323); `npm run lint` — 0; `npm run build` — OK; PHP не менялся
- `make site-test` — OK — 8554 тестов, 48 146 assertions
- Внешнее ревью (codex): REVIEW_GREEN (effort medium, дифф 422 строк, замечаний нет)
