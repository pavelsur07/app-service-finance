### Stage 4: CSS из шаблонов — DONE (PR A–D #2929–#2932 на проде 08.11.2026; закрывающий PR — токены + отчёт)

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
- [x] 4.2 — `<style>` вне писем → 0; `grep -rho 'style="' templates | wc -l` ≤ 20, каждое оставшееся — динамическое значение
  - [x] часть 1 (PR B): 8 блоков `<style>` → страничные CSS, `grep -rl '<style' templates | grep -v notifications/email` → 0
  - [x] часть 2 (PR C): `style=` в шаблонах admin-shell 94 → 2 (оба — CSS-переменные из данных: `--cov-days`, `--cov-span`); всего в templates 182 → 90
  - [x] часть 3 (PR D): `style=` вне писем 65 → 20, все 20 — динамические значения или состояние, которым управляет JS (список ниже);
    письма (`notifications/email`, 23) — inline по природе e-mail, в лимит не входят
- [x] Значения в новых CSS — токены `--vf-*`; остатки hex/px — с `TODO(ds)`, их число в отчёте (закрывающий PR: 1 hex, 106 px, см. «Токены»)
- [x] Каждый PR: `npm run build`, `make site-cs-twig`, `make site-test`, внешнее ревью; скриншоты до/после для 4.1 (PR A–D — см. разделы)

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
  `screens/stage-4/pr-b/`. Две админ-страницы живут за отдельным firewall `^/admin` (`/admin/login`): первый прогон
  снял там страницу входа; переснято с входом в админку (master ↔ ветка) — identical, PNG заменены. Оговорка: автоправила, платёжный календарь, предпросмотр 1С и неизвестные затраты на стенде
  без данных — строки таблиц (sticky-шапка, подсветка строк) не отрисованы; для них гарантия — сверка деклараций 1:1.

##### Files changed (PR B)
- `site/assets/styles/pages/{auto-rules,report-raw,marketplace-ads-debug,bank1c-import-preview,payment-calendar,money-account-new,service-gateway-coverage,mapping-errors}.css` (new),
  `site/assets/legacy-app.js`, `site/assets/styles/pages/admin-shell.css`, 8 шаблонов, `screens/stage-4/pr-b/*.png` (8)

##### Проверки (PR B)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (323); `npm run lint` — 0; `npm run build` — OK; PHP не менялся
- `make site-test` — OK — 8554 тестов, 48 146 assertions
- Внешнее ревью (codex): REVIEW_GREEN (effort medium, дифф 422 строк, замечаний нет)

#### PR C (4.2, часть 2) — `style="…"` админки → классы

##### Что сделано
- 7 шаблонов, extends `admin/base`: `billing/admin/company` 36, `admin/ai/company` 22, `admin/ingestion/external_categories/index` 16,
  `admin/users/index` 13, `admin/users/update_roles` 3, `telegram/admin/bot/index` 2, `admin/service_gateway/coverage` 2 — всего 94.
  Осталось 2 (coverage: `--cov-days`, `--cov-span` — значения из данных, по плану остаются). Факт: план считал 86 — `templates/admin/` без
  `billing/admin` и `telegram/admin`, которые тоже на admin-shell.
- Значения 1:1 (цель — пиксельная идентичность; унификация под DS — отдельный проход):
  - утилиты `u-*` в `admin-shell.css` (entry `admin_shell`, только админка): `u-tnum`, `u-fw-600`, `u-fs-11/12/15/16`, `u-mono`,
    `u-text-faint`, `u-muted`, `u-m-0`, `u-mt-1/4/5`, `u-mb-1/3/4`, `u-p-4`, `u-flex`, `u-inline`, `u-gap-1/2/3`, `u-w-120/160`,
    `u-max-w-520`, `u-min-w-300`, `u-break-all`, `u-btn-reset`. Порядок в файле: `u-m-0` раньше `u-mb-N`/`u-mt-N`
    (комбинация `u-m-0 u-mb-3` = `margin:0 0 12px`).
  - общие элементы админ-экранов там же: `.wz-subtitle` (подзаголовок под `.wz-title`), `.wz-stat-row`, `.wz-card-grid`,
    `.wz-card-intro` (шапка карточки с таблицей), `.wz-card-title`.
  - страничные: `admin-users.css` (`.admin-users-filter`, `.admin-cabinet-row` + `:not(:last-child)` вместо
    `{% if not loop.last %}margin-bottom{% endif %}`), `admin-external-categories.css` (`.ext-cat-confirm-form`, `.ext-cat-stack`,
    `.mdl--ext-cat`, `.ext-cat-mdl-body`) — подключены `@import` в `admin-shell.css`.
- Ловушка кита: `.card > h2:not([class])` (card.css) давал прямым детям карточки line-height 24px и weight 600; `<h2 style=…>` без
  класса под него попадал, `<h2 class=…>` — уже нет. Пять таких заголовков (billing ×2, ai ×3) получили `.wz-card-title`, где эти два
  значения повторены явно; h2 внутри `.wz-card-intro` под правило кита не попадали и остаются `u-fs-15 u-m-0` (UA bold). Найдено
  пиксельным сравнением, не ревью.
- Замечено, не трогали: классы `u-text-muted` (billing) и `u-p-3` (users) в шаблонах есть, но нигде не определены (no-op). Определять —
  изменит вид; решение — в DS-проход (`[[twig-ui-ds-pass]]`).

##### Проверка визуала
- Админ-страницы живут за отдельным firewall `^/admin` с собственной формой `/admin/login`: в скрипт скриншотов добавлен вход в
  админку (с очисткой поля `_username`, которое предзаполняется `last_username` общей сессии; иначе логин «склеивается» и вход падает).
  Роль `owner@app.ru` на стенде временно `ROLE_SUPER_ADMIN`, возвращена в `["ROLE_ADMIN"]`. Страница ролей — для `manager@app.ru`
  (на свою — 403).
- 7 страниц, 1366×900 fullPage, до (HEAD = PR B) и после — все identical (Pillow); первый прогон поймал расхождение по h2 (см. выше).
  Скриншоты «до» — `screens/stage-4/pr-c/`. Оговорка: на стенде таблицы оплат/запусков/внешних категорий пусты — ячейки с `u-tnum`,
  `u-break-all` отрисованы только в таблице тарифов и списке пользователей; остальное — сверка значений 1:1.

##### Files changed (PR C)
- `site/assets/styles/pages/admin-shell.css`, `site/assets/styles/pages/{admin-users,admin-external-categories}.css` (new), 7 шаблонов,
  `screens/stage-4/pr-c/*.png` (7)

##### Проверки (PR C)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (323); `npm run build` — OK; PHP и JS не менялись
- `make site-test` — OK — 8554 тестов, 48 146 assertions
- Внешнее ревью (codex): REVIEW_GREEN (effort medium, дифф 737 строк, замечаний нет)

#### PR D (4.2, часть 3) — `style="…"` легаси и кита → классы

##### Что сделано
- 21 шаблон, 47 статических `style=` → классы. Где Tabler даёт точно то же значение — его утилиты: `d-inline` (формы-кнопки ×9),
  `text-nowrap`, `cursor-pointer`, `mw-100`. Остальное — утилиты 1:1 в `vf-custom-classes.css` (entry `vf_custom_classes`, грузится на
  всех легаси-страницах): `u-tnum`, `u-w-50/60/82/118/120/140/160/200/260` (ширины колонок таблиц), `u-max-w-180/220/320/420`,
  `u-min-w-280`, `u-min-w-16r`, `vf-spinner-xs`. Имена и значения те же, что у `u-*` админки (другой бандл) — унификация в DS-проход.
- Страничные/слоевые: `pages/marketplace-ads.css` (`.ad-progress`; стартовая ширина прогресс-бара через CSS без `!important` — JS потом
  пишет inline width поверх), `pages/app-shell.css` (новый слой «общее для shell кита», импорт в `app.js` после `app.css`:
  `.billing-expiry-notice`), `u-max-w-640` в `admin-shell.css` (форма Telegram-бота в админке).
- `style="z-index: 1090"` у `.toast-container` снят без замены: Tabler 1.2.0 задаёт `.toast-container { z-index: var(--tblr-toast-zindex) }`
  с тем же 1090 (проверено по CSS CDN).
- Оставлено 20 `style=` вне писем — все обоснованы:
  - 14 значений из данных: отступы уровня дерева (`padding-left: {{ level*20 }}px` ×5, `--lvl` ×2, `--vf-pick-level`), ширины
    колонок из числа периодов (`min-width: calc(…)` ×2, `width: {{ table_width }}px` ×2, `--pl-col-period`), `--cov-days`/`--cov-label-w`;
  - 2 в админке (`--cov-days`, `--cov-span`, PR C);
  - 4 — состояние, которым управляет JS через `el.style.display = ''|'none'`: три блока формы подключения
    (`marketplace/connections`) и свёрнутые строки дерева в `report/cashflow`. Класс `d-none` сломал бы показ (JS пишет `''`); перевод
    на атрибут `hidden` — правка JS, вне 1:1.
- Проверено до правок: JS подключений и отчёта ДДС действительно пишет `style.display`; `marketplace_ads.js` пишет `style.width`
  прогресс-бара (поэтому в CSS без `!important`, а Tabler `w-0` с `!important` не подходит).

##### Проверка визуала
- 20 страниц (все, где менялась статика; партиалы — через страницы, где они включены: `_table_compact` → платёжный календарь,
  `pl_category/_form` → новая статья, `telegram/admin/bot/_form` → новый бот в админке), до (HEAD = PR C) и после — все identical.
  Не сняты: `partials/_flash` (тост появляется только после действия; замена — удаление избыточного атрибута) и
  `billing/_partials/expiry_notice` (показывается при истекающем доступе; на стенде нет) — для них сверка значений 1:1.
  Скриншоты «до» — `screens/stage-4/pr-d/`.

##### Files changed (PR D)
- `site/assets/styles/vf-custom-classes.css`, `site/assets/styles/pages/{marketplace-ads,app-shell}.css` (new), `site/assets/styles/pages/admin-shell.css`,
  `site/assets/legacy-app.js`, `site/assets/app.js`, 21 шаблон, `screens/stage-4/pr-d/*.png` (20)

##### Проверки (PR D)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (323); `npm run lint` — 0; `npm run build` — OK; PHP не менялся
- `make site-test` — OK — 8554 тестов, 48 146 assertions
- Внешнее ревью (codex): REVIEW_GREEN (effort medium, дифф 550 строк, замечаний нет)

#### Токены (закрывающий PR)
- Перенесённый CSS уже был на токенах Tabler/DS (`--tblr-*`, `--vf-*`, `--s-*`): hex в 20 новых файлах — один (`#333` в
  `marketplace-ads-debug.css`, border-top блока отладки; точного токена нет).
- Заменено на токены с тем же значением — 4 радиуса в `pl-filter-controls.css`: `6px` → `var(--vf-radius-md)` ×2,
  `4px` → `var(--vf-radius-sm)`, `8px` → `var(--vf-radius-lg)`; других точных совпадений (цвет, радиус) нет. Пиксельная проверка отчёта ДДС (использует эти контролы) — identical.
- Остаток без токена — px-размеры: 106 значений в 11 файлах (ширины колонок через CSS-переменные `--pl-col-*`/`--cf-col-*`/
  `--mapping-errors-col-*`, отступы 10/12/14/16/20px, шрифты 12/12.5/13px). Токены отступов и шрифтов DS заданы в rem
  (`--vf-space-N`, `--vf-size-N`); замена px → rem меняет вычисление при другом root font-size и не является переносом 1:1,
  поэтому отмечена `TODO(ds)` комментарием в конце каждого файла с числом значений — для DS-прохода.
  Не считаются «магическими»: `0`, `1px` (рамки), брейкпоинты `@media`.

#### Деплой (Stage 4)
- PR A #2929 — 08.11 06:34Z; PR B #2930 — 08.11; PR C #2931 — 08.11; PR D #2932 — 08.11 07:37Z. Каждый — с маркером
  «Деплой завершён без даунтайма», 12 контейнеров healthy, GlitchTip без новых событий по затронутым маршрутам.

#### Итог этапа
- `<style>` вне писем: 15 → 0. `style="…"` в templates: 182 → 43, из них 23 — письма (inline по природе e-mail) и 20 — динамические
  значения либо состояние под управлением JS (список — PR D). 20 новых файлов `assets/styles/pages/`, `{% block body_class %}` в
  `_layout/legacy`, слой `pages/app-shell.css` для shell кита, утилиты `u-*` в двух бандлах (admin-shell, vf-custom-classes) с
  одинаковыми именами и значениями.
- Уроки: (1) кит `.card > h2:not([class])` — класс на h2 снимает правило; (2) админ-страницы стенда за отдельным `/admin/login`,
  скриншоты без входа в админку сравнивают экраны входа; (3) `*/` внутри CSS-комментария (`u-mb-*/u-mt-*`) рвёт комментарий — postcss
  падает на сборке; (4) `d-none`/`w-0` Tabler с `!important` ломают состояние, которое JS пишет в `style`.
- Следующий этап — `stages/stage-5.md` (разбиение 15 страниц > 300 строк).
- Закрывающий PR: `make site-test` — OK (8554); внешнее ревью — два прогона, по коду и отчёту замечаний нет (одна находка — чужой
  untracked-файл вне PR).
