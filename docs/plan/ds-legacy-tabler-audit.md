# Дизайн-система «Ваш Финдир» в легаси-слое (Tabler) — аудит Stage 0

Дата: 2026-10-11 · ветка `feat/ds-legacy-tabler` · база `3782e6f4`
Задача: `docs/tasks/ds-legacy-tabler/plan.md`

Источник истины для легаси-слоя — дизайн-система «Ваш Финдир» (поручение
Владельца от 2026-10-11). `site/ui-kit/` для легаси не ориентир; его вывод —
Stage 6. Листы DS-Tokens/Controls/Table/Money/Tabs/Feedback к поручению **не
приложены**: всё ниже опирается на краткую таблицу из поручения, пробелы
помечены `TODO(ds)` и собраны в §7.

## 1. Охват

| Что | Число | Как считали |
|---|---|---|
| Страниц с корнем `_layout/legacy.html.twig` (транзитивно через `base.html.twig` и промежуточные layout'ы) | 143 | граф `{% extends %}` по `site/templates` |
| Файлов в охвате аудита (страницы + partials/макросы без `extends`, кроме `admin/`, `notifications/email/`, `security/`, `_layout/app`) | 198 | там же; список — в скрипте Stage 0, воспроизводим |
| Страниц на `_layout/app.html.twig` (вне рамок) | 28 | — |
| `admin/base.html.twig` (вне рамок — свой shell на `t-*`) | 10 | — |

Стили легаси: Tabler 1.2.0 с CDN → `design_tokens` → `vf_custom_classes` →
`legacy_app` (`legacy.html.twig:11–17`). Тот же набор подключает
`security/base.html.twig` (страница входа): правка токенов затронет и её —
это желательно, вход в легаси-визуале.

`design-tokens.css` и `vf-custom-classes.css` подключает **только** легаси
(`legacy.html.twig`, `security/base.html.twig`). `_layout/app.html.twig` их не
грузит — смена значений `--vf-*` новый слой не задевает. Исключение одно:
`assets/styles/components/financial-chart.css` (React-остров на легаси-странице
`home/index.html.twig`) читает `--vf-*` — он легаси-страница, правка желательна.

## 2. Компоненты — частоты (вхождения / файлы)

**Кнопки:** `btn-primary` 165/115 · `btn-outline-secondary` 81/52 ·
`btn-outline-primary` 70/43 · `btn-link` 38/28 · `btn-secondary` 36/27 ·
`btn-ghost-*` 28/16 · `btn-danger` 24/22 · `btn-warning` 13 ·
`btn-outline-danger` 10/9 · `btn-success` 5 · `btn-teal` 3 · `btn-sm` 133/51.

`btn-danger`/`btn-outline-danger` по подписям: «Удалить»/🗑 — 15, «Отменить» 2,
«Отозвать» 2, «Архивировать» 1 — деструктивные; **не деструктивные**:
«Обновить ключ», «Перейти к подключениям» — нарушают правило «красный только
для деструктивного» (Stage 2).

**Таблицы:** `<table>` 94/77 · `table-responsive` 82/66 · `table-vcenter`
72/59 · `card-table` 51/43 · `table-striped` 10/7 · `table-hover` 3/2.
Плотность и hover разнобойные; строка итогов и пустое состояние — каждый
шаблон по-своему.

**Пагинация — 6 реализаций:**
- `partials/_pagerfanta.html.twig` — Pagerfanta, окно ±2, «Назад/Вперёд»; 10
  страниц (документы, операции ДДС, товары, остатки, продажи, возвраты, …);
- `partials/_pagination.html.twig` — без окна, «Пред/След»; 1 страница
  (`cash/bank1c_import_preview`);
- самописные `page-item` в `import_log/index`, `inventory/snapshots/index`,
  `marketplace_analytics/cost_mappings/index`;
- подпись «Показано X–Y» есть только в `inventory/stocks/index` и
  `marketplace/listings/index`, в формате «X–Y» без «из N».

**Tabs:** `nav-tabs` в 15 файлах, `nav-pills` 1, `data-bs-toggle="tab"` 3
(клиентские табы). Общие partials: `marketplace_analytics/_tabs`,
`marketplace_ads/_tabs`, `ingestion/verification/_finance_tabs`,
`inventory/layout`, `marketplace/layout`; остальные — инлайн. Счётчиков-пилюль
`.cnt` нет нигде.

**Статусы/бейджи:** `badge` 212/54 · `bg-*-lt` 178/46. Цвета: `bg-secondary`
27, `bg-blue-lt` 27, `bg-secondary-lt` 26, `bg-warning-lt` 19, `bg-azure-lt`
18, `bg-success-lt` 17, `bg-green-lt` 17, `bg-danger` 17, `bg-success` 16,
`bg-red-lt` 16, `bg-yellow-lt` 10, `bg-warning` 10, `bg-info` 9 … — один и тот
же смысл «успех» выражен `bg-success`, `bg-success-lt`, `bg-green-lt`;
«ошибка» — `bg-danger`, `bg-red-lt`, `bg-danger-lt`.

**Алерты:** `alert` 101/41: `warning` 27 · `danger` 23 · `info` 19 · `success` 9.

**Формы:** `form_widget/row/start` 253/44 (Symfony-тема Tabler) ·
`form-control` 139/59 · `form-select` 121/47 · `form-check` 69/14.

**Текст:** `text-muted` 339/86 · `text-secondary` 120/25 · `text-danger`
70/34 · `text-success` 59/26 · `text-warning` 25 · `text-red`/`text-green` 9.

## 3. Деньги и даты

| Способ | Вхождения | Расхождение с DS |
|---|---|---|
| `number_format(2, '.', ' ')` | 87 | точка вместо запятой; обычный пробел вместо U+00A0; копейки |
| `number_format(0, ',', ' ')` | 37 | обычный пробел |
| `number_format(2, ',', ' ')` | 29 | обычный пробел; копейки в аналитике |
| `number_format(0, '.', ' ')` | 12 | обычный пробел |
| `number_format(1, ',', ' ')` | 1 | — |
| `\|format_currency(cur)` (`src/Twig/CurrencyFormatExtension.php`) | 4 | обычный пробел, дефис-минус, символ в каждой ячейке |
| `\|format_minor_currency` | — | то же |

- Во всех 166 `number_format` минус — дефис (PHP), U+2212 нет нигде.
- `₽` литералом — 56/21; в ячейках `<td>` — 12 файлов (`marketplace/sales` 5,
  `marketplace_analytics/snapshots/index` 7, `marketplace/returns` 4, …).
- Единого фильтра денег для легаси нет. Есть `.money` в админке
  (`marketplace/admin/mapping_error_list`) с **U+2009** (тонкий пробел) и
  U+2212 — расходится с DS (U+00A0); админка вне рамок, отметить в Stage 6.
- Тесты, завязанные на текущий формат (меняются вместе с шаблонами в Stage 3):
  `BalanceLedgerUiTest` («100 000,00»), `CashTransferControllerTest`
  («9 500,25 RUB»), `WbRawFinancialReportControllerTest` («1 308,04 RUB» ×4),
  `ProductShowPurchasePriceBlockTest`/`ProductPurchasePriceCreateTest`
  («199 900.00»), `ProductPurchasePriceHistoryPageTest` («12000.00 RUB»),
  `StockReportControllerTest` («10.000» — количество, не деньги).
- Даты: `|date(` 222/65, формат в основном `d.m.Y`. Пресеты периода — три
  разных набора: `transaction/_filters` (Сегодня, Вчера, Текущий месяц, …),
  `report/cashflow` и `finance/report/preview` (Текущий месяц, Квартал, …).
  Целевой набор DS: Вчера, Эта неделя, Прошлая неделя, Этот месяц, Прошлый
  месяц, Квартал. Пресеты считает JS (`data-period`) — правка затрагивает JS.

## 4. Хардкод цветов и инлайн-стили

- `style="` 61/30; `<style>`-блоки в 14 шаблонах (`home/index`,
  `transaction/index`, `report/cashflow`, `finance/report/preview`, `raw`,
  `pl_category/index`, `payment_calendar/index`, `integrations/marketplace`,
  `cash/*_import_preview`, `cashflow_category/index`,
  `cash_transaction_auto_rule/index`, `profile/money_account/new`,
  `marketplace_ads/admin_debug`).
- Hex в шаблонах: 13 файлов, лидер `finance/report/_filter_controls_styles`
  (28), `home/index` (10). Частые значения — старая палитра Tabler: `#206bc4`
  7, `#d63939` 4, `#2fb344` 4. (`#039`/`#add` — ложные срабатывания на
  HTML-сущности.)
- `rgb(a)(` 16/8. `legacy-app.css`: `#d9e2ef` 5, `#656d77` 3, `#206bc4` 2,
  `#0a1548` 1 и др.
- `--tblr-table-bg` переопределяется инлайн в
  `cash/bank1c_import_preview`, `cash/file_import_preview`.

## 5. Токены: DS → текущие переменные

| DS | Значение | `--vf-*` сейчас | `--tblr-*` (Tabler 1.2.0) | Решение Stage 1 |
|---|---|---|---|---|
| Navy 900 | `#0b1020` | `--vf-text` `#121212`, `--vf-text-dashboard` `#1E293B`, `--vf-navy-900` `#050B2B` | `--tblr-body-color` `#1f2937` | все → `#0b1020` |
| (бренд-navy сайдбара) | — | `--vf-brand-navy`/`--vf-navy` `#0A1548`, `--vf-brand` (legacy-app.css) | — | → Navy 900, `TODO(ds)`: отдельного «brand navy» в DS нет |
| Muted | `#525b78` | `--vf-text-muted` `#4B5563`, `--vf-text-secondary` `#6B7280` | `--tblr-muted`, `--tblr-secondary`, `--tblr-secondary-color` | все → `#525b78` |
| Border | `#dde1ec` | `--vf-border` `#E5E7EB` | `--tblr-border-color` `#e5e7eb` | → `#dde1ec` |
| Border strong | `#c3c8d6` | `--vf-border-strong` `#D1D5DB` | `--tblr-border-active-color` `#a9adb5` | → `#c3c8d6` |
| Фон страницы | `#f7f8fc` | `--vf-bg` `#F5F7FB` | `--tblr-body-bg` `#f9fafb` | → `#f7f8fc` |
| Action blue | `#1d4ed8` | `--vf-primary` `#206BC4` | `--tblr-primary` `#066fd1`, `--tblr-link-color` | → `#1d4ed8`; `-lt`, `darken` Tabler выводит через `color-mix` сам |
| Красный бренда | `#b00020` | `--vf-brand-red` `#FF1F1F`, `--vf-red` `#D63939` | `--tblr-danger` `#d63939` | `--vf-brand-red`, `--tblr-danger` → `#b00020` (btn-danger = деструктив) |
| Danger-текст | `#9a001b` | `--vf-red` | `.text-danger` читает `--tblr-danger` | новый `--vf-danger-text`; `--vf-red` → `#9a001b`; `.text-danger` — Stage 2 |
| OK | `#1d7a30` / `#e4f5e7` | `--vf-green` `#2FB344`, `--vf-green-lt` `#EAF7EC`, `--vf-green-bg` `#F0FDF4` | `--tblr-success`, `--tblr-success-lt` | → DS |
| Warn | `#9a4a00` / `#fff4e5` | `--vf-yellow` `#F59F00`, `--vf-yellow-lt` `#FDF4E3` | `--tblr-warning`, `--tblr-warning-lt` | → DS |
| Шрифт | Inter | `--vf-font-sans` Inter | `--tblr-font-sans-serif` «Inter Var», Inter | без изменений |

**Опасные переменные:**
- `[data-bs-theme=light]` стоит на сайдбаре (`legacy.html.twig:26`), а Tabler
  объявляет палитру на `:root,[data-bs-theme=light]` — переопределение только
  на `:root` не дойдёт до сайдбара. Переопределять на том же селекторе.
- `--tblr-*-rgb` используются в `rgba()` тенях фокуса и `.text-*` —
  менять в паре с hex.
- `--vf-chart-1..6`, `--vf-cyan`, `--vf-indigo`, `--vf-purple`, `--vf-ai-*` —
  палитра графиков, в DS её нет. Не трогаем, `TODO(ds)`.
- `--tblr-danger` одновременно и кнопка удаления, и `alert-danger`, и
  `.text-danger` (70 вхождений, в т. ч. отрицательные суммы) — после замены на
  `#b00020` всё красное станет брендовым, пока Stage 2 не разведёт текст
  ошибок на `--vf-danger-text`.

## 6. Мёртвый код

`vf-custom-classes.css`: из 72 классов в шаблонах и JS не используется **ни один**
(`grep -w` по `templates/`, `assets/`; `.vf-sidebar*` живут в
`legacy-app.css`). `.vf-scope` не используется — ограничивающей области нет,
стили легаси глобальные. Около 40 из них — пустой блок no-op «migration hooks».
Удаление — отдельным шагом Stage 2 (правило Stage 1: не удалять).

## 7. Вопросы к листам DS (`TODO(ds)`)

1. Hover/pressed для Action blue и ссылок; цвет фокус-кольца.
2. Фон danger-алерта и danger-бейджа (дан только danger-текст).
3. Info-статус: в DS нет; сейчас `alert-info` 19, `bg-info` 9, `bg-azure-lt` 18.
4. Радиусы (Tabler: 4/6/8 px) и плотность таблиц.
5. Палитра графиков (`--vf-chart-*`).
6. Отдельный «brand navy» для сайдбара или только Navy 900.
7. **Контраст границы поля:** `#c3c8d6` на белом — 1,67:1, ниже 3:1 WCAG 1.4.11
   для границ контролов. Не регресс (у Tabler `#dadfe5` ещё светлее), но
   вопрос к DS-Controls.
8. Цвет `disabled`-текста (`--vf-text-disabled`).

## 8. Контраст (WCAG AA, текст ≥ 4,5:1)

| Текст / фон | Отношение | AA |
|---|---|---|
| `#0b1020` / `#ffffff` | 18,93 | ✅ |
| `#0b1020` / `#f7f8fc` | 17,84 | ✅ |
| `#525b78` / `#ffffff` | 6,72 | ✅ |
| `#525b78` / `#f7f8fc` | 6,33 | ✅ |
| `#1d4ed8` / `#ffffff` | 6,70 | ✅ |
| `#1d4ed8` / `#f7f8fc` | 6,31 | ✅ |
| `#ffffff` / `#1d4ed8` (кнопка) | 6,70 | ✅ |
| `#ffffff` / `#b00020` (кнопка) | 7,33 | ✅ |
| `#1d7a30` / `#e4f5e7` | 4,77 | ✅ (запас мал) |
| `#1d7a30` / `#ffffff` | 5,41 | ✅ |
| `#9a4a00` / `#fff4e5` | 5,76 | ✅ |
| `#9a001b` / `#ffffff` | 8,79 | ✅ |

## 9. Сложные страницы (топ по сложности)

Трафика по роутам в репозитории и в read-only доступе нет (`perf-report`
только про маркетплейсы), поэтому ранжирование по сложности: строки + таблицы +
формы + `number_format` + инлайн-стили.

| # | Шаблон | Строк | Таблиц | `number_format` | `style=` |
|---|---|---|---|---|---|
| 1 | `marketplace/wb_finance_report` | 811 | 6 | 31 | 0 |
| 2 | `transaction/index` (операции ДДС) | 694 | 1 | 1 | 2 + `<style>` |
| 3 | `report/cashflow` | 618 | 3 | 4 | 3 + `<style>` |
| 4 | `marketplace/connections` | 539 | 0 | 0 | 7 |
| 5 | `marketplace_ads/index` | 608 | 2 | 0 | 2 |
| 6 | `finance/report/preview` (ОПиУ) | 609 | 1 | 0 | 2 + `<style>` |
| 7 | `marketplace/month_close/index` | 496 | 1 | 4 | 1 |
| 8 | `home/index` (дашборд) | 470 | 0 | 6 | 4 + `<style>` |
| 9 | `marketplace/listings/index` | 518 | 1 | 0 | 6 |
| 10 | `marketplace/cost_pl_mapping/index` | 537 | 1 | 0 | 0 |

## 10. Порядок внедрения и пилот

Порядок (как в поручении): **токены → кнопки/поля/статусы/алерты →
деньги/даты → таблицы+пагинация+Tabs → пилот → раскатка по модулям**.

Пилот:
- таблица+фильтры+пагинация — `transaction/index` (операции ДДС: `_filters`,
  `_pagerfanta`, `nav-tabs`, `<style>`);
- форма — `transaction/edit` / `_form` (форма операции);
- дашборд — `home/index` (KPI, график, `<style>`, 10 hex).

## 11. Ограничения проверки

- Baseline фронта (2026-10-11, до изменений): `npm run lint` ✅, `npm run build`
  ✅, `npm run check:ui-kit` ❌ 9081 нарушение (легаси-классы Tabler; в CI не
  входит — `frontend-lint.yml` гоняет только eslint), `npm run
  check:uikit-react-mapping` ❌ (pre-existing: `@react TreePicker` без
  компонента). Красные гейты — не регресс задачи; сравнивать счётчики.
- Тест-раннера и `typecheck` на фронте нет.
