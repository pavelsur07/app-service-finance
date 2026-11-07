# twig-ui-systematization: систематизация и оптимизация Twig UI

Поручение Владельца (07.11.2026, чат): «нужно оптимизировать и систематизировать
код twig UI, подготовь план в порядке приоритета». Класс — Large (кросс-модульно,
фронтовый redesign не входит). Каждый этап — отдельный PR; порядок ниже —
порядок приоритета: сначала то, что защищает от регресса и снимает риск,
потом дедупликация, потом косметика.

Правила: `CLAUDE.frontend.md` (DS, деньги, партиалы), `AGENTS.md` §5
(Work item → Stage → handoff), self-review — `docs/workflow/stage-report-frontend.md`.

## 0. Снимок «до» (07.11.2026, `origin/master` 02930481b)

Замеры снимать повторно после каждого этапа; счётчики ниже — baseline.

| Что | Число | Как считали |
|---|---|---|
| Шаблонов / строк | 319 / 31 761 | `find site/templates -name '*.twig'` |
| Страниц легаси (`base.html.twig` → `_layout/legacy`) | 153 | граф `{% extends %}` |
| Страниц нового слоя (`_layout/app`) / админки (`admin/base`) / e-mail | 36 / 12 / 12 | там же |
| Партиалов без `extends` | 110 | — |
| Шаблонов с `<script>` (кроме e-mail) | 51 | — |
| Строк JS внутри Twig, топ-5 | 460 `document/_page_scripts`, 412 `marketplace/listings/index`, 385 `transaction/index`, 364 `marketplace_ads/index`, 343 `marketplace/cost_pl_mapping/index` | awk по `<script>…</script>` |
| Twig-выражения внутри `<script>` | 12 файлов (лидер `partials/_dds_tree_picker` — 12) | — |
| `fetch(` в шаблонах | 14 файлов | — |
| `confirm(` / `onclick=` | 15 / 5 файлов | при существующем `partials/_confirm_modal` |
| `<style>`-блоков (кроме e-mail) | 15 файлов, 595 строк; топ: `cashflow_category/index` 127, `finance/report/preview` 89, `marketplace/pl_preview/index` 83 | — |
| `style="…"` | 188 вхождений; топ: `billing/admin/company` 36, `admin/ai/company` 21, `admin/ingestion/external_categories/index` 16 | — |
| Hex-цвета в шаблонах | 1 файл (e-mail, допустимо) | — |
| Рендер `app.flashes` | 13 мест, 3 разных разметки и 2 разных маппинга `label → класс` | `partials/_flash` (toast), `admin/_toasts`, `balance/layout` и `settlement/layout` (alert кита), 9 инлайн-копий Tabler-alert |
| Шапка страницы Tabler `page-header` / DS `vf-page-head` | 65 / 9 файлов | — |
| Пустое состояние `class="empty"` — копии | 17 | — |
| Словари подписей `{% set …Labels = {…} %}` в Twig | 13 (`statusLabels` ×4) | при 36 Enum с `label()` в PHP |
| `number_format` | 7 (4 в `admin/ai/company`) | DS-деньги почти закрыты |
| `twigcs` (ruleset Official) | 2286 нарушений: 650 «variable lower case», 1288 пробелы, 258 запятые списков, 74 unused variable, 6 unused import | `composer cs:twig`; **в CI и Makefile не подключён** (строка закомментирована) |
| `lint:twig` | OK, 319 файлов | `bin/console lint:twig templates` |
| Шаблоны > 400 строк | 17 (макс. 817 `marketplace/wb_finance_report`) | — |

Что уже систематизировано — не переделывать: пагинация (`partials/_pagination`,
`app/_ui/_pagination`, 37 include, самописных нет), вкладки (`partials/_tabs`,
`app/_ui/_tabs`), деньги (`vf_money`/`vf_amount`/`vf_number`, 328 вхождений),
`_confirm_modal` + `assets/confirm_modal.js`, Stimulus (`assets/bootstrap.js`,
6 контроллеров) и страничные модули `assets/pages/*.js` с `init*()` из
`legacy-app.js`, страничный CSS `assets/styles/pages/*.css`.

## 1. Приоритеты

| # | Этап | Зачем первым | Риск | Объём |
|---|---|---|---|---|
| 1 | Гейт Twig в CI + конвенции | фиксирует результат остальных этапов, дешёвый | LOW | 1 PR |
| 2 | Вынос JS из шаблонов | XSS-поверхность (Twig внутри JS), код без lint/типов, `confirm()` вместо DS-диалога | MEDIUM | 3 PR |
| 3 | Общие партиалы: flash, пустое состояние, шапка, словари подписей | 13 мест flash с разной логикой, 17 копий empty, 13 словарей в Twig | LOW–MEDIUM | 2 PR |
| 4 | Вынос `<style>` и `style=` в `assets/styles/pages` | 595 строк CSS в шаблонах, 188 инлайн-стилей; DS-правило «только токены» | LOW | 1–2 PR |
| 5 | Разбиение шаблонов > 400 строк | читаемость; делать после 2 и 4 — они сами сократят файлы | LOW | по модулю |
| 6 | Легаси-шапки и фильтры на DS-композицию (`vf-page-head`, `vf-card-bar`) | визуальное изменение 65 страниц — отдельные задачи по модулям, не часть этой | MEDIUM | вне рамок |

## 2. Безопасность: система и деплой

Требование Владельца (07.11): рефакторинг не ломает работу системы и текущий
деплой. Как устроен деплой (`.github/workflows/deploy.yml`): push в `master` →
unit, cs, stan, функциональные тесты → сборка образов (внутри `yarn build`,
`cache:clear` компилирует все Twig-шаблоны) → `schema-ready` → деплой без
даунтайма с healthcheck. Из этого следуют меры:

| Риск | Мера |
|---|---|
| Синтаксическая ошибка Twig | ловится на сборке образа (`cache:clear`), деплой не стартует; локально — `lint:twig` в каждом Work item |
| Неопределённая переменная после переноса в партиал | в prod Twig не строгий — страница отрендерится с пустотой молча. Сетка: `strict_variables: true` в `test`, поэтому **у каждой затрагиваемой страницы до правки должен быть функциональный тест на 200 и ключевые селекторы**. Сейчас без теста ~41 из 191 рендерящихся шаблонов — Work item 0 каждого этапа: дописать smoke-тест страницам, которые этап трогает |
| Сломанный JS-импорт или отсутствующий Vite entry | `yarn build` падает при сборке образа → деплой не стартует, но и релиз встаёт. Локально `npm run build` идентичен Dockerfile — обязателен перед каждым коммитом Stage 2/4; entry в `vite.config.js` только добавлять, не переименовывать и не удалять в том же PR, где удаляется потребитель |
| Поведение страницы изменилось (JS, flash, модалки) | один модуль — один PR, разметка и поведение 1:1; ручной smoke на локальном стенде с перечнем действий в Stage Report; для финансовых форм (`transaction/*`, `document/*`) — тест на выдачу `data-*`-атрибутов |
| Новый гейт блокирует чужой merge/деплой | `cs-twig` — отдельный workflow как `frontend-lint.yml`, **не** в цепочке `deploy.yml`; в required checks — только после нуля нарушений и отдельного «да» Владельца |
| Регресс после деплоя | ни один этап не несёт миграций и данных — откат = `git revert` PR и обычный деплой; образы предыдущих SHA остаются в GHCR. После каждого деплоя — GlitchTip (`gt.sh`) по затронутым маршрутам 30 минут, при новой ошибке — revert, разбор потом |
| Массовые whitespace-правки (Stage 1.3) | `git diff -w --stat` пуст; пачки по модулю; `make site-test` перед handoff |
| Shell-слои (`_layout/*`, `admin/base`) | трогаются только в Stage 3 (flash) одним маленьким PR, с тестом на все три shell'а |
| E-mail шаблоны | не трогать вообще |

Порядок merge: один PR этапа за раз, следующий — после зелёного деплоя и
проверки предыдущего. Между PR разных этапов можно вклинивать чужие релизы —
этапы независимы.

## Stage 1: гейт Twig и конвенции

Risk: LOW (docs + конфиг линта; добавление job в CI — §3.3 «CI/CD behavior»,
назвать в handoff отдельно; job вне цепочки `deploy.yml`)
Definition of Done:
- `docs/workflow/twig-conventions.md`: три shell'а и когда какой (`_layout/legacy`,
  `_layout/app`, `admin/base`); именование партиалов `_name.html.twig` и макросов
  `_macros.html.twig`; блоки `title/body/javascripts/stylesheets`; «JS — только
  `assets/`, данные в JS — через `data-*` с `|e('html_attr')`»; «CSS — только
  `assets/styles/pages/<page>.css`»; «подписи Enum — из PHP `label()`, не словарь
  в Twig»; `{{ include() }}` с `only` для партиалов с параметрами; ссылка из
  `CLAUDE.frontend.md`.
- Решение по ruleset: правило «переменная в lower_case» (650) противоречит
  принятому в проекте camelCase (`currentPage`, `totalItems` в DS-партиалах) —
  отключить правило кастомным Ruleset, не переименовывать. Остальные 1636
  (пробелы, запятые, unused) — чинить.
- `composer cs:twig` и `bin/console lint:twig templates` зелёные; `make site-cs-twig`
  в Makefile; отдельный workflow `twig-lint.yml` по образцу `frontend-lint.yml`
  (paths: `site/templates/**`), не в цепочке `deploy.yml`.
- Удалены 74 неиспользуемые переменные и 6 неиспользуемых импортов макросов.
Work items:
- 1.0 — список рендерящихся шаблонов без функционального теста (41) — в
  `checkpoint.md`; smoke-тесты дописывает тот этап, который меняет поведение
  страницы. Для 1.3 страховка другая: правки только внутри `{{ }}`/`{% %}`,
  доказательство — пустой `git diff -w` по шаблонам (исключения перечислены в
  stage-1.md построчно)
- 1.1 — конвенции `docs/workflow/twig-conventions.md` + строка в `AGENTS.md` таблице документов
- 1.2 — кастомный Ruleset (без lower_case), прогон, фиксация числа
- 1.3 — правка 1636 нарушений пробелов/запятых (механически, пофайлово; `lint:twig` после каждой пачки)
- 1.4 — unused variables / imports
- 1.5 — Makefile target + workflow `twig-lint.yml` (не required до отдельного «да»)
Stage checks:
- `composer cs:twig` → 0; `bin/console lint:twig templates` → OK; `make site-test` (шаблоны меняются массово — функциональные тесты с crawler, 135 файлов)
Reviewer focus:
- правки только whitespace: `git diff -w --stat` должен быть пустым для 1.3
- `twig-cs-fixer` (vincentlanglet) умеет `--fix`, `twigcs` — нет; замена зависимости — решение Владельца, по умолчанию остаёмся на `twigcs`
- факт 07.11: twigcs не допускает перенос строки вокруг операторов и `? :` (только внутри хэша и аргументов функции) — 20 многострочных выражений в 10 файлах сведены в одну строку, логика не менялась; порог `error` скрывает ложные unused-предупреждения (переменные layout'а)

## Stage 2: JS из шаблонов в `assets/`

Risk: MEDIUM (поведение страниц; HIGH-LOCAL для `document/_page_scripts`,
`transaction/index` — финансовые формы)
Куда выносить: страничные модули `assets/pages/<page>.js` с `init*()` (образец
`assets/pages/dds_operation.js`), регистрация в `legacy-app.js` через проверку
наличия корневого элемента; интерактивные виджеты — Stimulus-контроллер
(`assets/controllers/*_controller.js`, образец `balance_lines_controller.js`).
Данные из Twig — только `data-*` с `|json_encode|e('html_attr')`; CSRF — `data-*`
или meta. `fetch` — через `assets/api/client.ts` (`apiFetch`), не raw.
Definition of Done:
- `grep -rl '<script' site/templates` — только `_layout/*`, `admin/base`,
  `notifications/email`, `partials/_flash` (пока не переведён — см. Stage 3).
- 0 Twig-выражений внутри JS; 0 `confirm(`/`onclick=` — всё через `_confirm_modal`
  (data-confirm-*) или Stimulus `data-action`.
- Для каждой затронутой страницы — ручной smoke с перечнем действий в Stage Report;
  для `transaction/index`, `document/_page*`, `marketplace/month_close` —
  функциональный тест на выдачу `data-*` (атрибуты и экранирование).
- `npm run build` зелёный перед каждым коммитом; новые entry только добавляются.
- Старый inline-скрипт удаляется в том же PR, где появляется модуль в `assets/`
  (две реализации запрещены), но PR — один модуль, не больше ~5 страниц.
Work items (три PR, от дешёвого к рискованному):
- 2.0 — smoke-тесты страницам этапа без теста
- 2.1 — `confirm()`/`onclick` → `_confirm_modal` (15 файлов, механически) и
  мелкие `<script>` до 50 строк (≈25 файлов). По правилу «≤5 страниц на PR»
  разделён: 2.1a — формы `onsubmit=confirm` (PR A #2911, на проде 07.11),
  2.1b — мелкие `<script>` и `onchange="this.form.submit()"` (PR B #2912, на проде
  07.11); 2.2 — PR C (#2914, на проде 07.11: 5 файлов) + PR D (три marketplace-страницы
  с Twig в JS: `month_close/index`, `marketplace/index`, `cost_mappings/index`);
  2.3 — крупные скрипты по одной-двум страницам на PR: PR E — `cost_pl_mapping/index` +
  `pl_mappings/_default_mapping_modal`; PR F — `marketplace_ads/index`; PR G — `listings/{index,tags}`; PR H — `transaction/index`; PR I и далее — остальные
- 2.2 — шаблоны с Twig внутри JS (12 файлов): `partials/_dds_tree_picker`,
  `pl_category/edit`, `catalog/product/import_status`, `marketplace_ads/index`,
  `profile/money_account/new`, `marketplace/month_close/index`, …
- 2.3 — пять крупных: `document/_page_scripts` (460), `marketplace/listings/index`
  (412), `transaction/index` (385), `marketplace_ads/index` (364),
  `marketplace/cost_pl_mapping/index` (343); `dashboard/_demo_chart` и
  `_money_balance_chart` — Chart-острова, в `assets/pages/dashboard*.js`
Stage checks:
- `npm run lint && npm run build`; размер `legacy_app` до/после; функциональные тесты модуля
Reviewer focus:
- экранирование `data-*`; CSRF-токен не теряется; обработчики не дублируются
  при `turbo:load` (есть `ux-turbo`); порядок инициализации с `confirm_modal.js`

## Stage 3: общие партиалы и подписи

Risk: LOW–MEDIUM
Definition of Done:
- Flash — ровно три места: `partials/_flash` (легаси, toast), `admin/_toasts` (админка, без изменений) и новый
  `app/_ui/_flash` (кит, `alert alert--*`); маппинг `label → variant` один
  (`error→danger`, `notice→info`, `alert→warning`, иначе по имени) в обоих.
  11 инлайн-копий удалены (`marketplace/layout`, `inventory/layout`,
  `balance/layout`, `settlement/layout`, `catalog/product/import`,
  `company/company_member/index`, `telegram/*`, `marketplace_ads/index`,
  `marketplace_analytics/cost_mappings/index`, `api/settings/permissions`).
  JS из `partials/_flash` — в `assets/legacy-app.js`.
- Пустое состояние — `partials/_empty.html.twig` (icon, title, hint, action) для
  легаси; 17 копий заменены; разметка не меняется.
- Шапка легаси — `{% embed 'partials/_page_header.html.twig' %}` с блоками
  `title`, `pretitle`, `actions`: та же Tabler-разметка, 65 копий → один партиал.
  Переход на DS `vf-page-head` — Stage 6, здесь визуал не меняется.
- Словари подписей: 13 `{% set …Labels %}` заменены на `label()` Enum (там, где
  значение — Enum) или на поле DTO; `settlement/layout` (`bucketLabels`,
  `kindLabels`) и `document/_list_layout` (`typeShortLabels`, `statusLabels`) —
  первыми, они общие для вкладок модуля.
Work items:
- 3.0 — тест: три shell'а рендерят flash каждого label одинаковыми классами
- 3.1 — flash; 3.2 — `_empty`; 3.3 — `_page_header` embed; 3.4 — подписи из Enum
Stage checks:
- `make site-test` (crawler-тесты видят flash и заголовки); `composer cs:twig`
Reviewer focus:
- все 12 мест flash дают одинаковые классы для одинакового label; никаких
  новых CSS-классов (`CLAUDE.frontend.md`: только кит / Tabler + DS-надстройки)

## Stage 4: CSS из шаблонов

Risk: LOW (визуал не меняется — перенос правил 1:1, затем замена hex/px на токены)
Definition of Done:
- 0 `<style>` вне `notifications/email`; 15 блоков перенесены в
  `assets/styles/pages/<page>.css` и подключены через существующие entry
  (`legacy_app`, `app`, `admin_shell`), как `pages/budget.css`.
- `style="…"` ≤ 20 (допустимы динамические значения: ширина прогресса, цвет из
  данных через переменную); остальные 168 → классы Tabler/кита или страничный CSS.
- Значения в перенесённом CSS — через `--vf-*`/semantic-токены; hex и «магические»
  px без токена помечены `TODO(ds)`.
Work items:
- 4.0 — скриншоты «до» страниц этапа на локальном стенде
- 4.1 — `cashflow_category/index`, `pl_category/index`, `finance/report/preview`,
  `report/cashflow`, `marketplace/pl_preview/index`, `home/index`,
  `integrations/marketplace` (7 крупных блоков, 505 строк)
- 4.2 — остальные 8 блоков и `style=` (админка: `billing/admin/company`,
  `admin/ai/company`, `admin/users/*`, `admin/ingestion/external_categories`)
Stage checks:
- `npm run build`; скриншот до/после страниц 4.1 (стенд — память `local-visual-check`)
Reviewer focus:
- специфичность: правило из `<style>` побеждало всё на странице; в общем файле
  обернуть селектором корня страницы, иначе утечёт на другие экраны

## Stage 5: разбиение крупных шаблонов

Risk: LOW
Definition of Done:
- Ни один шаблон страницы не длиннее ~300 строк; части — партиалы модуля
  `_filters`, `_table`, `_row`, `_modals`, `_totals` с `only`-include.
- Поведение и разметка идентичны: `diff` отрендеренного HTML на фикстурных
  данных (функциональный тест или `bin/console debug:twig` + ручная проверка).
Порядок — по размеру после Stage 2/4: `marketplace/wb_finance_report` (817, 6
таблиц), `marketplace/listings/index`, `transaction/index`, `finance/report/preview`,
`report/cashflow`, `marketplace_ads/index`, `marketplace/connections`,
`marketplace/month_close/index`, `admin/ingestion/external_categories/index`.
Stage checks: `composer cs:twig`, `lint:twig`, тесты модуля.

## Вне рамок этой задачи (follow-up)

- Stage 6 — перевод 65 легаси-шапок на `vf-page-head` и фильтров на `vf-card-bar`,
  модульные layout'ы `marketplace/layout`, `inventory/layout` (card-tabs +
  инлайн flash) на DS-композицию «Операции ДДС». Визуальное изменение —
  отдельная задача на модуль с листами DS, как `ds-counterparties`.
- Миграция легаси-страниц на `_layout/app` — не в этой задаче.
- `partials/_sidebar` (410 строк, 49 ссылок, 16 `is_granted`) — конфигурация
  меню в PHP (массив пунктов + voter) вместо Twig; отдельное решение.
- E-mail шаблоны (`notifications/email`, инлайн-стили обязательны) — не трогать.
- Замена `twigcs` на `twig-cs-fixer` — новая зависимость, решение Владельца.

## Вопросы Владельцу (ответ нужен до Stage 1.2 и 1.5, остальное не блокирует)

1. Отключить правило `twigcs` «переменные в lower_case» (650 мест, camelCase принят
   в DS-партиалах) — или переименовывать? Рекомендация: отключить.
2. Job `cs-twig` в CI как обязательная проверка — да? (§3.3 «CI/CD behavior».)

## Чек-лист приёмки задачи

- [ ] каждый PR этапа задеплоен отдельно, после деплоя GlitchTip без новых ошибок по затронутым маршрутам; откатов не было (или записаны с причиной)
- [ ] у каждого шаблона, который трогала задача, есть функциональный тест на 200

- [ ] `composer cs:twig` 0 нарушений, в CI
- [ ] `<script>` только в shell-слоях и e-mail; 0 Twig-выражений внутри JS; 0 `confirm(`
- [ ] flash — 3 партиала (по одному на shell); `_empty`, `_page_header` — по одному; 0 словарей подписей в Twig
- [ ] `<style>` только в e-mail; `style=` ≤ 20
- [ ] шаблонов > 300 строк — 0 (страницы), счётчики снимка «до» пересняты и записаны в handoff
- [ ] `docs/workflow/twig-conventions.md` в таблице `AGENTS.md`, ссылка из `CLAUDE.frontend.md`
