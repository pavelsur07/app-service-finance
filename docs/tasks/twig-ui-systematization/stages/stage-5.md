### Stage 5: крупные шаблоны — остаток JS и разбиение на партиалы — IN PROGRESS

**Статус:** старт по команде Владельца 09.11.2026; A1 — PR на handoff.
**Risk:** подэтап A — MEDIUM (переносится поведение JS), B–F — LOW (только структура шаблонов, HTML 1:1), G — LOW (документы).
**Base:** master после #2933 (cd09f6917).
**Work items:** 5.0–5.7 (ниже), `plan.md` — раздел Stage 5 и чек-лист приёмки задачи.

#### 1. Задача
Довести шаблоны страниц до читаемого размера и закрыть чек-лист приёмки всей задачи:
- ни одна страница не длиннее ~300 строк; части — партиалы с `{% include … only %}`, макросы — в `_macros.html.twig`;
- `<script>` с логикой страниц — только в shell-слоях и письмах (пункт чек-листа задачи, см. «Расхождение с отчётом Stage 2»);
- поведение и разметка не меняются: отрендеренный сервером HTML до и после идентичен.

#### 2. Результат (что будет на выходе)
- 15 страниц > 300 строк → 0; у каждой — партиалы по смыслу (`_filters`, `_table`, `_row`, `_modals`, `_totals`, `_macros`).
- 23 инлайн-скрипта (1 095 строк JS) → модули `assets/pages/*.js`, подключённые в `legacy-app.js` по корневому `data-*`.
- Инструмент сверки отрендеренного HTML «до/после» (скрипт стенда), им подтверждён каждый PR подэтапов B–F.
- Конвенция именования партиалов страницы дописана в `docs/workflow/twig-conventions.md`.
- Закрывающий PR: счётчики снимка «до» из `plan.md` §0 пересняты, чек-лист приёмки задачи пройден.

#### 3. Замер «до» (09.11.2026, master cd09f6917)

Страницы длиннее 300 строк (без писем и партиалов) — 15:

| Строк | Шаблон | JS inline | После A* | Таблиц | Форм | Модалок | Макросов | Тестов на 200 |
|---|---|---|---|---|---|---|---|---|
| 810 | `marketplace/wb_finance_report` | — | 810 | 6 | 1 | 0 | 3 | 1 |
| 574 | `report/cashflow` | 40 | ~532 | 3 | 1 | 0 | 4 | 3 |
| 544 | `marketplace/connections` | 142 | ~400 | 0 | 8 | 5 | 0 | 5 |
| 537 | `finance/report/preview` | 80 | ~455 | 1 | 1 | 0 | 1 | 3 |
| 465 | `admin/ingestion/external_categories/index` | — | 465 | 6 | 7 | 1 | 0 | 3 |
| 463 | `marketplace/pl_preview/index` | 93 | ~368 | 7 | 1 | 0 | 1 | 2 |
| 456 | `marketplace/inventory/index` | 68 | ~386 | 2 | 5 | 3 | 0 | 3 |
| 412 | `report/cashflow_ops_check` | — | 412 | 2 | 1 | 0 | 0 | 1 |
| 394 | `transaction/index` | — | 394 | 1 | 2 | 3 | 0 | 2 |
| 385 | `marketplace/pl_mappings` | — | 385 | 1 | 4 | 2 | 0 | 3 |
| 368 | `pl_category/import` | — | 368 | 3 | 6 | 0 | 0 | 3 |
| 348 | `marketplace/month_close/index` | — | 348 | 1 | 4 | 0 | 0 | 2 |
| 342 | `marketplace/index` | — | 342 | 2 | 3 | 2 | 0 | 2 |
| 332 | `budget/plan_fact` | — | 332 | 1 | 3 | 0 | 7 | 1 |
| 324 | `marketplace/listings/index` | — | 324 | 1 | 2 | 1 | 0 | 5 |

\* «После A» — оценка: строки страницы минус её инлайн-скрипт. «Тестов на 200» — файлов в `tests/`, запрашивающих маршрут (grep по URL);
у всех ≥ 1, пункт чек-листа задачи «у каждого тронутого шаблона — функциональный тест на 200» для этих страниц уже выполняется.

Инлайн-скрипты `<script>` (без `type="application/json"`, shell-слоёв и писем) — 23 файла, 1 095 строк, Twig-выражений внутри — 1
(`admin/tools/messenger_ping`): `connections` 142, `marketplace_ads/admin_debug` 110, `marketplace/unmapped/index` 107, `pl_preview/index` 93,
`finance/report/preview` 80, `pl_category/_form` 70, `payment_calendar/index` 68, `marketplace/inventory/index` 68, `transaction/splits` 67,
`document/index` 46, `report/cashflow` 40, `pl_category/index` 35, `cashflow_category/index` 35, `telegram/integration/index` 25,
`marketplace/ozon_reconciliation/index` 18, `company/company_member/index` 15, `catalog/product/show` 14, `billing/client/tariff` 14,
`admin/tools/messenger_ping` 13, `cash/file_import_preview` 12, `admin/dashboard/index` 10, `integrations/marketplace` 8, `admin/users/index` 5.

#### Расхождение с отчётом Stage 2
`stages/stage-2.md` («Итог этапа»): «0 `<script>` с логикой страниц вне исключений конвенции». Факт на 09.11: 23 файла с логикой.
Stage 2 убрал Twig-выражения из JS (их осталось 1) и перенёс часть скриптов, но не все; итоговая фраза отчёта неверна. Пункт чек-листа
задачи «`<script>` только в shell-слоях и e-mail» не выполнен. Подэтап A закрывает этот долг; в `stage-2.md` дописывается поправка.

#### 4. Технический метод

##### 4.1 Сверка отрендеренного HTML (5.0, первым PR — вместе с A1)
- Скрипт стенда `site/bin/html-snapshot.sh` (или node-скрипт в контейнере `zenika/alpine-chrome:with-puppeteer`, как скриншоты Stage 4):
  логин в основной firewall и в админский (`/admin/login`, очистка предзаполненного `_username`), затем для каждого URL — **сырой ответ
  сервера** (`fetch(url, {credentials:'include'}).then(r => r.text())` из контекста страницы), не DOM после JS.
- Нормализация перед `diff`: значения CSRF (`name="_token" value=…`, `data-*-token`, `csrf`), `sf-toolbar`/`sfwdt`-блоки и их id,
  пробелы между тегами (`>\s+<` → `><`, серии пробелов → один) вне `<pre>`/`<textarea>`.
- Критерий: нормализованный `diff` пуст. Различия только в пробелах допустимы и перечисляются в отчёте PR как «whitespace-only»;
  любое другое различие — дефект, PR не уходит.
- Списки URL — в `scratchpad`/`docs/…/stages/stage-5-urls.txt`; страницы с параметрами (`?grouping=week`, `?layout=projects`, вкладки
  внешних категорий) — отдельными URL, чтобы проверить все ветки шаблона.
- Скриншоты не нужны: при идентичном HTML и тех же бандлах пиксели идентичны. Исключение — подэтап A (переносится JS): там проверка
  поведения, см. 4.3.

##### 4.2 Правила разбиения (B–F)
- Резать по смыслу, не по счёту строк: фильтры → `_filters`, таблица (шапка + тело) → `_table`, строка длиннее ~40 строк или с рекурсией →
  `_row`, модалки → `_modals` (одна модалка > ~60 строк — свой `_modal_<name>`), итоги → `_totals`, макросы → `_macros`.
- Имена: партиалы одной страницы — в каталоге страницы: `<module>/<page>/_<part>.html.twig` (как уже сделано у
  `marketplace/pl_mappings/_ozon_status`); у страницы `index` — каталог самой страницы (`marketplace/listings/_table.html.twig`).
  Дополнить `docs/workflow/twig-conventions.md` этой строкой (сейчас там только `<module>/_<name>`).
- Каждый include — `{% include '…' with {…} only %}`; параметры перечислены комментарием в начале партиала (образец —
  `partials/_pagination.html.twig`). `app`, глобальные переменные и функции Twig доступны и при `only`.
- Макросы: `{% import _self as x %}` → `{% import '<module>/<page>/_macros.html.twig' as x %}` в странице **и в каждом партиале**, где
  макрос вызывается (импорт не наследуется include'ом). Макрос, читающий переменную контекста неявно, получает её аргументом.
- Не переносятся в партиал: `{% block %}` внутри `{% embed %}` (`_page_header`, `_empty`) и сами блоки страницы (`tab_content`, `modals`,
  `admin_modals`) — партиал включается внутри блока.
- Отступы внутри партиала сохраняются как были (сдвиг влево допустим — whitespace-only), текст и атрибуты не правятся. Попутных правок
  разметки нет: любое улучшение — отдельным PR после этапа.
- `id`, `data-*`, `name` не переименовываются: на них завязаны модули `assets/pages/*.js` и функциональные тесты.

##### 4.3 Перенос инлайн-JS (A)
- Образец — модули Stage 2 (`assets/pages/listings_index.js`, `month_close.js`): модуль экспортирует `init…()`, ищет корень
  `[data-<page>]`, без корня — молча выходит; подключение — импорт в `assets/legacy-app.js` (админские страницы — `admin_shell_runtime.js`).
- Логика переносится как есть (тот же порядок обработчиков, те же селекторы); стиль кода — ESLint проекта; `var` → `const/let`
  допустимо, иное не меняется. Единственное Twig-выражение (`messenger_ping`) → `data-*` атрибут.
- Корневой `data-<page>` добавляется на существующий контейнер страницы — это единственное изменение HTML в подэтапе A
  (в отчёте PR перечислить).
- Проверка поведения: для каждой страницы — сценарий из 2–5 шагов (открыть модалку, переключить тип, отправить форму, раскрыть дерево)
  прогоном puppeteer на стенде до и после; результат — одинаковые состояния DOM/URL после каждого шага. Функциональные тесты страницы —
  зелёные. Сценарии — в разделе PR отчёта.

##### 4.4 Гейты каждого PR
`npm run lint`, `npm run build` (A), `make site-cs-twig` (twigcs 0, `lint:twig` OK), тесты модуля, `make site-test` (полный, в фоне),
внешнее ревью Codex против master, сверка HTML (B–F) или сценарии (A). Деплой — отдельно на каждый PR, после деплоя — маркер
«Деплой завершён без даунтайма», `codex-docker-ps` 12 healthy, GlitchTip без новых событий по маршрутам PR.

#### 5. Подэтапы и PR

| Подэтап | Содержание | Шаблоны | Риск |
|---|---|---|---|
| **A1** | 5.0 инструмент сверки HTML + перенос JS из 5 крупных страниц | `connections` (142), `pl_preview/index` (93), `finance/report/preview` (80), `marketplace/inventory/index` (68), `report/cashflow` (40) | MEDIUM |
| **A2** | Перенос JS из остальных 18 файлов (652 строки) | список в §3, кроме пяти из A1 | MEDIUM |
| **B** | Финансовые отчёты | `report/cashflow`, `finance/report/preview`, `report/cashflow_ops_check` | LOW |
| **C** | Отчёты маркетплейса | `marketplace/wb_finance_report`, `marketplace/pl_preview/index` | LOW |
| **D** | Страницы с модалками, часть 1 | `marketplace/connections`, `marketplace/inventory/index`, `marketplace/pl_mappings` | LOW |
| **E** | Страницы с модалками, часть 2 | `transaction/index`, `marketplace/index`, `marketplace/listings/index` | LOW |
| **F** | Формы и админка | `admin/ingestion/external_categories/index`, `pl_category/import`, `marketplace/month_close/index`, `budget/plan_fact` | LOW |
| **G** | Закрытие этапа и задачи | документы, счётчики, чек-лист приёмки | LOW |

A идёт первым: после выноса JS пять страниц уменьшаются на 40–142 строки, и B–D режут уже чистую разметку.
Если Владелец решит не трогать JS в рамках задачи — A1 сводится к инструменту сверки, A2 снимается, пункт чек-листа про `<script>`
переносится в follow-up с записью причины.

##### Предлагаемая разбивка шаблонов (уточняется при выполнении, итог — в отчёте PR)
- `marketplace/wb_finance_report` (810): `_macros` (money/amount/percent), `_filters` (форма 46–183), по партиалу на секцию —
  `_articles` («Расшифровка по статьям WB»), `_products` («Товары и себестоимость», самая длинная: 317–645), `_reports`
  («Сводка по reportId»), `_quality` («Качество данных»), `_operations` («Операции WB»).
- `report/cashflow` (574): `_filters` (форма `cashflow-filter-form`), `_macros` (4 макроса, рекурсивный `render_category`),
  `_table` (основная `cf-table`), `_projects` (две вспомогательные таблицы 495–530).
- `finance/report/preview` (537): `_macros` (`cell`), `_filters` (форма `pl-preview-filter-form`), `_table` + `_row`.
- `report/cashflow_ops_check` (412): `_help` («Что это за отчёт», блоки 1–2), `_kpi` (карточки флагов 140–260), `_filters`,
  `_transactions`, `_reconciliation`.
- `marketplace/pl_preview/index` (463): `_macros` (`cell`), `_filters` (форма + `_period_presets`), `_table` (основная),
  `_diagnostics` (6 таблиц «Не попадёт в ОПиУ» … «Реклама: учёт и статистика», по партиалу на таблицу либо один с циклом — по факту).
- `marketplace/connections` (~400 после A): `_list` (карточки подключений), `_modal_add`, `_modal_sync_period`, `_modal_sync_realization`,
  `_modal_reprocess`, `_modal_delete`.
- `marketplace/inventory/index` (~386): `_filters`, `_jobs` (журнал синхронизаций с модалками деталей), `_table`, `_modal_set_cost`,
  `_modal_import_cost`.
- `marketplace/pl_mappings` (385): `_filters`, `_table` (+ модалка редактирования в строке → `_modal_edit`), `_modal_add`.
- `transaction/index` (394): `_table` (+ `_row`), `_modals` (bulk delete, автоправило, «создать из предупреждения»).
- `marketplace/index` (342): `_summary` (первая таблица), `_documents` (фильтр + таблица документов), `_modals`.
- `marketplace/listings/index` (324): `_toolbar` (синхронизация + массовый тег), `_filters`, `_table`, `_modal_map`.
- `admin/ingestion/external_categories/index` (465): `_ozon_new_types`, `_ozon_disputes`, `_import_result`, `_operations`
  (seed/discover/импорт/refresh), `_refresh_status`, `_dictionary_statuses`, `_latest_categories`, `_modal_mapping`.
- `pl_category/import` (368): `_base_template` («Базовый шаблон ОПиУ»), `_marketplace` («Статьи маркетплейса»), `_from_file`,
  `_diff` («Существующие статьи отличаются от источника»).
- `marketplace/month_close/index` (348): `_filters`, `_stages` (три POST-формы этапов), `_table`.
- `budget/plan_fact` (332): `_macros` (7 макросов, включая `picker` с формой), `_toolbar` (табы + две формы-фильтра), `_table`.

#### 6. Чек-лист приёмки

##### Подэтап A (A1, A2)
- [ ] `grep -rl '<script>' templates` → только `_layout/*`, `admin/base`, `notifications/email`; `<script type="application/json">` — как были
- [ ] Twig-выражений внутри JS — 0 (`messenger_ping` переведён на `data-*`)
- [ ] Каждый модуль: `init…()` по корню `data-<page>`, без корня — выход; импорт в `legacy-app.js` / `admin_shell_runtime.js`
- [ ] Изменения HTML подэтапа — только добавленные корневые `data-*`; перечислены в отчёте PR
- [ ] Сценарии поведения до/после на стенде совпали по каждой странице; функциональные тесты страниц зелёные
- [ ] `npm run lint` 0, `npm run build` OK
- [x] Поправка к итогу `stage-2.md` внесена (A1; внесена ещё планом #2943)
- [x] 5.0: скрипт сверки HTML в репозитории, проверен на одной странице «сам с собой» (пустой diff) и на заведомом изменении (diff не пуст)

##### Подэтапы B–F (каждый PR)
- [ ] Все шаблоны PR ≤ 300 строк (`wc -l`), таблица «строк до → после, партиалы» в отчёте
- [ ] Нормализованный diff сырого HTML «до/после» пуст по каждому URL PR (включая варианты параметров); whitespace-only различия перечислены
- [ ] Каждый include партиала — с `only`, параметры перечислены комментарием в начале партиала
- [ ] Макросы — в `_macros`, импортированы в каждом шаблоне, где вызываются; `import _self` в странице не осталось
- [ ] `id` / `data-*` / `name` / тексты не изменены (следует из пустого diff)
- [ ] Каждая страница PR имеет функциональный тест на 200; при отсутствии — добавлен в этом PR

##### Каждый PR этапа
- [ ] `make site-cs-twig` (twigcs 0, `lint:twig` OK), тесты модуля, `make site-test` — зелёные
- [ ] Внешнее ревью Codex против master — без замечаний по коду (или замечания исправлены и перечислены)
- [ ] Коммит по pathspec, без untracked-файлов Владельца
- [ ] Деплой отдельным PR: маркер «Деплой завершён без даунтайма», 12 healthy, GlitchTip без новых событий по маршрутам PR
- [ ] Отчёт PR дописан в этот файл, `checkpoint.md` обновлён

##### Подэтап G — приёмка Stage 5 и всей задачи (чек-лист `plan.md`)
- [ ] Страниц > 300 строк: 15 → 0 (команда подсчёта и вывод — в отчёте)
- [ ] `<script>` только в shell-слоях и письмах; 0 Twig-выражений в JS; 0 `confirm(`
- [ ] flash — 3 партиала (по одному на shell); `_empty`, `_page_header` — по одному; 0 словарей подписей в Twig
  (если 8 оставшихся словарей Stage 3 не переведены — записать решение «оставлены» с причиной, как в отчёте Stage 3)
- [ ] `<style>` только в письмах; `style=` вне писем ≤ 20 (Stage 4: 20)
- [ ] `composer cs:twig` 0 нарушений, в CI
- [ ] Счётчики снимка «до» из `plan.md` §0 пересняты, таблица «до → после» в отчёте задачи
- [ ] `docs/workflow/twig-conventions.md` — строка о партиалах страницы; ссылка из `AGENTS.md` и `CLAUDE.frontend.md` на месте
- [ ] Каждый PR задачи задеплоен отдельно, откатов не было (или записаны с причиной)
- [ ] `stages/stage-5.md` — DONE, `checkpoint.md` — задача закрыта; память обновлена

#### 7. Оценка
A1 — 1 PR, A2 — 1–2 PR, B–F — по 1 PR, G — 1 PR: всего 8–9 PR, каждый со своим деплоем. По темпу Stage 3–4 (PR за 1–2 часа
вместе с полным прогоном тестов и ревью) — около двух рабочих дней, без учёта ожидания решений «merge and deploy».

#### 8. Что выполнено
- 09.11.2026 — план (этот файл): замер «до», метод, подэтапы A–G, чек-лист. Работы не начаты.

#### A1 — инструмент сверки HTML + JS пяти крупных страниц (09.11.2026, ветка `refactor/twig-ui-stage-5a1`)

**5.0 — `site/bin/html-snapshot.sh <urls.txt> <out-dir>`.** curl одной сессией логинится в `/login` и `/admin/login`
(браузер не нужен: сверяется сырой ответ сервера), по каждому URL пишет `HTTP <код> <url>` + нормализованный HTML;
сверка — `diff -ru before after`. Нормализация: значения `value`/`content` у `<input>`/`<meta>` с token/csrf в теге,
`data-*token*`, `nonce`, вырезана отладочная панель Symfony (её токен и id меняются на каждый запрос), пробелы между тегами
и серии пробелов вне `<pre>`/`<textarea>`, перевод строки между тегами (читаемый diff). Проверки: 7 URL дважды
подряд на master — diff пуст; ветка A1 против master — diff не пуст и содержит ровно ожидаемое (ниже).
Ловушка: CSS-бандл `legacy_app` меняет хеш при изменении графа JS-модулей — в B–F бандл не пересобирается,
в A сравнивать с учётом хешей.

**Перенос JS (5 страниц, 423 строки инлайна → 4 модуля, 336 строк с doc-комментариями):**

| Шаблон | Строк до → после | Модуль | Корень (единственная правка HTML) |
|---|---|---|---|
| `marketplace/connections` | 544 → 397 | `pages/marketplace_connections.js` | `data-marketplace-connections` на `div.p-3` вкладки |
| `marketplace/pl_preview/index` | 463 → 369 | `pages/pl_tree.js` | `data-pl-tree` на таблице дерева |
| `finance/report/preview` | 537 → 456 | `pages/pl_tree.js` | `data-pl-tree` на таблице дерева |
| `marketplace/inventory/index` | 456 → 387 | `pages/marketplace_inventory.js` | `data-marketplace-inventory` на `div.p-3` вкладки |
| `report/cashflow` | 574 → 533 | `pages/cashflow_report.js` | `data-cashflow-report` на `table.cf-table` |

- Два почти одинаковых скрипта дерева ОПиУ (отчёт ОПиУ и предпросмотр маркетплейса) — один модуль `pl_tree.js`
  по `[data-pl-tree]`; кнопки «Развернуть все / Свернуть» — если есть на странице (есть только в предпросмотре).
  Слушатель клика отчёта ОПиУ перенесён с `document` на таблицу — переключатели только внутри неё.
  Переключатель «Колонки» предпросмотра — там же, по существующему `[data-pl-columns-mode]`.
- Намеренные отличия от «как есть»: (1) опрос статуса подключений выводит `lastSyncAt`/`lastSyncError` через
  `textContent`, а не `innerHTML` — текст ошибки приходит от маркетплейса, это XSS; (2) в остатках модал
  «Себестоимость» и модал импорта инициализируются независимо (раньше отсутствие первого бросало исключение и
  отключало второй; на странице оба есть всегда, поведение не меняется); (3) «Обработка…» закрывает модал через
  `bootstrapModal()` (учитывает и `window.tabler.bootstrap`).
- Тест `InventoryImportControllerTest::testInventoryPageContainsSupplierSkuImportGuidance` искал тексты подсказок
  в HTML (они жили в инлайн-скрипте) — теперь проверяет корень модуля на странице и тексты в модуле.

**Проверки A1:**
- Сценарии поведения на стенде (`zenika/alpine-chrome:with-puppeteer`, 27 шагов по 5 страницам: дерево — клики по
  уровням, «Развернуть/Свернуть», «Колонки»; ДДС — раскрытие и сворачивание ветки; остатки — модал себестоимости,
  импорт для Ozon/WB/без маркетплейса и всех типов идентификатора; подключения — Ozon/Performance/WB, четыре модала,
  блокировка кнопок на submit, опрос статуса). Данных на стенде мало, поэтому строки дерева, ветки ДДС и узлы опроса
  подставлялись в ответ сервера перехватом запроса — одинаково для «до» и «после»; триггеры модалов — синтетические
  кнопки с теми же `data-*`. «До» — шаблоны и бандл master, «после» — ветка. Совпали все шаги, кроме намеренного (1):
  `Ошибка: ключ <b>отклонён</b>` теперь выводится текстом. Ошибок JS в консоли — 0.
- HTML-сверка 7 URL (`/marketplace/connections`, `/marketplace/pl-preview` и `?columns=tags`, `/finance/report/preview`,
  `/marketplace/inventory`, `/finance/reports/cashflow` и `?grouping=week`): отличия — хеши бандла `legacy_app`,
  пять корневых `data-*`, удалённые `<script>`.
- `npm run lint` 0; `vite build` OK; `composer cs:twig` — No violation; `lint:twig` — 323 файла OK;
  функциональные тесты страниц (117 тестов: предпросмотр ОПиУ, остатки, подключения, отчёт ОПиУ, ДДС) — OK после
  правки одного теста.
- `make site-test` (полный, своя БД `app_test_a1`) — OK (8571 tests, 48241 assertions).
- Внешнее ревью Codex против master f00d9e26f — `REVIEW_GREEN` с первого раунда (дифф 1062 строки).
