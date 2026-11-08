### Stage 3: общие партиалы и подписи — DONE (PR A–D #2924–#2927 на проде 08.11.2026)

**Risk:** LOW–MEDIUM (разметка 65+17+10 мест меняется на include/embed; визуал не меняется)
**Stage base commit:** master после закрывающего PR Stage 2
**Work items:** 3.0–3.4 (`plan.md`, раздел Stage 3)

#### Задача
Убрать дубли общей разметки легаси-shell'а: flash, пустое состояние, шапка страницы — по одному
партиалу на каждый; словари подписей `{% set …Labels %}` заменить на `label()` Enum или поле DTO.
Поведение и визуал 1:1, новых CSS-классов нет (`CLAUDE.frontend.md`).

#### Результат
- Flash рендерят ровно три места: `partials/_flash` (легаси), `admin/_toasts` (без изменений),
  `app/_ui/_flash` (кит); один маппинг `label → variant`; inline-копии `app.flashes` удалены.
- `partials/_empty.html.twig` (icon, title, hint, action) вместо 17 копий `class="empty"`.
- `{% embed 'partials/_page_header.html.twig' %}` (блоки `title`, `pretitle`, `actions`) вместо 65 копий
  `page-header` — та же Tabler-разметка, переход на DS `vf-page-head` — Stage 6.
- Словари подписей → `label()` Enum / поле DTO; первыми `settlement/layout` и `document/_list_layout`.

#### Замер «до» (07.11.2026, master после PR I)
- inline `app.flashes` вне трёх разрешённых мест — 10 файлов: `catalog/product/import`,
  `company/company_member/index`, `telegram/admin/bot/index`, `telegram/integration/index`,
  `marketplace_ads/index`, `balance/layout`, `inventory/layout`, `marketplace/layout`,
  `marketplace_analytics/cost_mappings/index`, `api/settings/permissions`
  (план называл 11 с `settlement/layout` — там `app.flashes` уже нет).
- `class="empty"` — 17 файлов; `class="page-header` — 65 файлов;
  `{% set …Labels %}` — 16 вхождений в 11 файлах (план: 13; добавились `admin/ai/company`,
  `report/cashflow`, `report/account_balances`, `admin/users/index`, `cash_transaction_auto_rule/*`).

#### Разбиение на PR
- PR A (3.0 + 3.1): тест трёх shell'ов на одинаковые классы flash по label; `partials/_flash` как
  единственный рендер легаси, 10 inline-копий удалены, JS flash — в `assets/legacy-app.js`.
- PR B (3.2): `partials/_empty`, 17 замен.
- PR C (3.3): `partials/_page_header` embed, 65 замен — порциями по модулям, если дифф > 1500 строк.
- PR D (3.4): подписи из Enum/DTO — сначала `settlement/layout`, `document/_list_layout`, затем остальные;
  Enum без `label()` получает его в том же PR с unit-тестом.

#### Чек-лист приёмки
- [x] 3.0 — функциональный тест: три shell'а рендерят flash каждого label одинаковыми классами (PR A, `FlashShellsTest`)
- [x] 3.1 — `grep -rl app.flashes templates` → `partials/_flash`, `admin/_toasts`, `app/_ui/_flash` + два служебных ключа
      (`company_member/index` — `invite_token`, `telegram/admin/bot/index` — `webhook_status`): это данные страницы, не сообщения (PR A)
- [x] 3.2 — `grep -rl 'class="empty"' templates` → только `partials/_empty` (PR B)
- [~] 3.3 — `grep -rl 'class="page-header' templates` → `partials/_page_header` + 15 файлов с другой разметкой
      (без `container-xl`/`row g-2`, вкладки внутри шапки, печатная шапка отчёта, h1) — см. PR C; добивать в Stage 6 при переходе на DS `vf-page-head`
- [~] 3.4 — `grep -rn '{% set [a-zA-Z]*Labels' templates` → 8 из 16 (PR D): все словари, у которых есть Enum, переехали в `label()`;
      остались подписи без Enum (`sourceLabels`, `fieldLabels` ×2, `skipLabels`, `directionLabels`) и две не-словаря
      (`responsibilityCenterLabels` — карта id→имя из данных, `userRoleLabels` — производный список); решение — в PR D
- [ ] Каждый PR: `make site-cs-twig`, `make site-test` (crawler-тесты видят flash и заголовки),
      внешнее ревью, стенд-скриншот не требуется (разметка побайтно та же, кроме include)

#### Reviewer focus
- Одинаковые классы flash для одинакового label во всех 12 местах; никаких новых CSS-классов.
- `embed` шапки: блок `actions` пустой → кнопок нет, лишнего `<div class="col-auto">` не остаётся.

#### PR A (3.0 + 3.1) — flash: один маппинг на три shell'а

##### Факт, найденный при разборе
`_layout/legacy` включает `partials/_flash` до `{% block content %}`, а партиал читал `app.flashes` без ключа —
`FlashBag::all()` опустошал **все** ключи. Поэтому 7 inline-копий на страницах легаси (`inventory/layout`,
`marketplace/layout`, `catalog/product/import`, `marketplace_ads/index`, `cost_mappings/index`,
`telegram/integration/index`) никогда не рендерились — мёртвый код, удаление без визуальных изменений.
Побочный дефект: `company_member/index` ждал `app.flashes('invite_token')`, но токен уходил в тост
`text-bg-invite_token` — карточка «Ссылка для приглашения» не показывалась. Тестов на это не было.

##### Что сделано
- `partials/_flash` читает только известные label (`success/error/danger/warning/alert/notice/info`) через
  `app.flashes(label)`; служебные ключи остаются странице. Inline `<script>` (fallback без Bootstrap, `turbo:load` —
  Turbo в `controllers.json` выключен) удалён; показ тостов — `showFlashToasts()` в `assets/legacy-app.js`
  (`bootstrap.Toast.getOrCreateInstance`, Tabler грузится классическим скриптом до модуля).
- Кит: новый `app/_ui/_flash.html.twig` (DS `alert alert--{variant}` в `wz-row`, без JS), включён в `_layout/app`
  до `expiry_notice`; inline-копии `balance/layout` и `api/settings/permissions` удалены,
  `PermissionsController` пишет `success` вместо ключа `api_permissions_success`.
- `admin/_toasts` не менялся — маппинг уже совпадал.
- Удалено 9 inline-копий `app.flashes` (10 файлов замера минус 2 служебных ключа + `permissions`).

##### Визуальные отличия (намеренные)
- Баланс: flash теперь над вкладками разделов (был под ними); `error/danger` → `alert--danger`
  (был `alert--warning`; контроллеры Баланса пишут только `success`, фактического изменения нет).
- Права API-ключа: «Права сохранены.» над формой в `wz-row`, а не внутри карточки.
- Легаси: порядок тостов — по label в порядке маппинга, не по времени `addFlash` (на странице почти всегда один).

##### Тесты
- `tests/Integration/Shared/FlashShellsTest.php` — три shell'а × 7 label → один variant; известные ключи
  прочитаны, `invite_token` остаётся в FlashBag и не рендерится.
- `CompanyMemberInviteFlowTest::testInviteCreatesCompanyInvite` — после redirect карточка «Ссылка для приглашения»
  с `/invite/`, тоста `text-bg-invite_token` нет (красный на старом `_flash`, зелёный на новом).
- `ApiPermissionsControllerTest` — без правок, `.alert--success` «Права сохранены.» проходит через общий flash.

##### Files changed (PR A)
- `site/templates/partials/_flash.html.twig`, `site/templates/app/_ui/_flash.html.twig` (new), `site/templates/_layout/app.html.twig`
- `site/templates/{balance/layout,api/settings/permissions,inventory/layout,marketplace/layout,catalog/product/import,marketplace_ads/index,marketplace_analytics/cost_mappings/index,telegram/integration/index}.html.twig`
- `site/assets/legacy-app.js`, `site/src/Api/Controller/Settings/PermissionsController.php`
- `site/tests/Integration/Shared/FlashShellsTest.php` (new), `site/tests/Functional/Company/CompanyMemberInviteFlowTest.php`

##### Проверки (PR A)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (322); `npm run lint` — 0; `npm run build` — OK
- `make site-cs-check` — 0 of 3914; `make site-cs-strict-types` — 0; `make site-stan` — OK
- целевые функциональные тесты (flash-ассерты, Balance, Telegram, MarketplaceAds, MarketplaceAnalytics, Catalog) — OK (255 tests, 1507 assertions)
- `make site-test` — OK (8540 tests, 48108 assertions), 8 мин 23 с

##### External review (PR A)
- rounds: 2 (codex, effort medium, база — ветка 2j 048909b5c, дифф 516 строк, `site/var/external-review/048909b5/review.txt`).
  Раунд 1, IMPORTANT: без Tabler JS (CDN) тосты оставались скрытыми — прежний inline-скрипт имел fallback.
  Исправлено: без `Toast` тост получает класс `show` и остаётся видимым (без автоскрытия). Раунд 2 — REVIEW_GREEN.

##### Risks / reviewer focus (PR A)
- Страницы кита без сессии: `app/_ui/_flash` читает `app.flashes` в `_layout/app` — все страницы кита (Баланс, права API,
  `balance_error`) под stateful-firewall; `BalanceLedgerUiTest` покрывает 404/422 HTML.
- Новый ключ `addFlash` вне семи известных молча пропадёт: проверка — `grep -rhoE "addFlash\('[a-z_]+'" src | sort -u`.

#### PR B (3.2) — `partials/_empty`: одно пустое состояние на две разметки

##### Что сделано
- `partials/_empty.html.twig`: параметры `title`, `hint`, `variant` (`legacy` — Tabler `<p class="empty-title">` +
  `<p class="empty-subtitle text-muted">`; `app` — DS `<div class="empty-title">` + `<div class="empty-text">`),
  блоки `icon` (SVG страницы, обёртка `empty-icon` появляется только при непустом блоке), `action`, `attrs`.
  Без иконки и кнопки — `include … only`, иначе `embed`.
- 17 файлов переведены (9 легаси, 8 DS: 7 `admin/base` + `api/settings`). SVG-иконки остались в страницах
  (контент, не разметка); тексты с условиями (`listings/index`, `mapping_error_list`) — тернарники в параметрах;
  `coverage` — `data-coverage-empty` через блок `attrs`, текст с `service` — конкатенация.
- Разметка та же, кроме двух мест DS: `api/settings/index` `<h3 class="empty-title">` → `<div>`, `coverage`
  `<p class="empty-text">` → `<div>` — классы задают размер/вес/отступы, визуально без изменений.
- `onclick="openAddPriceModal()"` в `catalog/product/show` оставлен как был — inline-обработчик страницы вне
  scope PR (в замере Stage 2 эта страница не значилась; кандидат на Stage 4).

##### Тесты
- `tests/Integration/Shared/EmptyStatePartialTest.php` — обе ветки разметки, необязательные блоки, экранирование.
- Существующие: `ApiSettingsControllerTest` (`.empty-title`), `MarketplaceMappingErrorsPageTest` (`.empty`, текст,
  без ссылок), `MarketplaceListingsSearchTest` («Ничего не найдено») — без правок.

##### Files changed (PR B)
- `site/templates/partials/_empty.html.twig` (new), 17 шаблонов из замера «до»
- `site/tests/Integration/Shared/EmptyStatePartialTest.php` (new)

##### Проверки (PR B)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (323); `make site-cs-check` — 0 of 3915; `make site-cs-strict-types` — 0; `make site-stan` — OK
- целевые тесты (партиал, Api settings, mapping errors, listings search) — OK (21 tests, 221 assertions)
- `make site-test` — OK (8543 tests, 48121 assertions), 8 мин 12 с
- Внешнее ревью (codex): REVIEW_GREEN (1 раунд, база — ветка PR A d447ade59, дифф 564 строк, `site/var/external-review/d447ade5/review.txt`)

#### PR C (3.3) — `partials/_page_header`: одна Tabler-шапка на 51 страницу

##### Что сделано
- `partials/_page_header.html.twig`: параметры `title`, `pretitle` (строки; с разметкой — блоки `title`/`pretitle`),
  `class` (доп. классы корня — `mb-3`), `container` (по умолчанию `container-xl`), `print` (по умолчанию `d-print-none`);
  блоки `subtitle` (строка под заголовком со своими классами) и `actions` — колонка `col-auto ms-auto d-print-none`
  рендерится только при непустом блоке, поэтому условные кнопки (`{% if isOwner %}`) живут внутри блока.
- 51 файл переведён: 49 скриптом (`scratchpad/header_rewrite.py` — структурный парсер по вложенности div: корень →
  `container-xl` → `row g-2 align-items-center` → `col` [pretitle] h2 [subtitle] [+ `col-auto…`]), 2 вручную
  (`company_member/index`, `marketplace_ads/index` — колонка кнопок под `{% if %}`).
- Гармонизировано без визуальных изменений: класс колонки кнопок (`col-auto` / `col-auto ms-auto` /
  `… d-print-none` → один вариант: `ms-auto` при `col` рядом не действует, `d-print-none` внутри уже скрытой при
  печати шапки избыточен); `h2.page-title mb-0` → `page-title` (Tabler задаёт `margin: 0`).
- Не переведены 15 файлов с другой структурой — остаются как есть до Stage 6 (DS `vf-page-head`):
  без `container-xl` и/или с `row align-items-center` без `g-2` (`inventory/snapshots/index`, `ingestion/verification/*`,
  `telegram/integration/index`, `marketplace/connection/edit`, `moy_sklad/layout` — h1), без `row` (`billing/client/tariff`,
  `cash/file_import_{preview,result}`, `home/dashboard`, `marketplace_ads/admin_debug`), вкладки внутри шапки
  (`marketplace_ads/_tabs`, `marketplace_analytics/_tabs`, `ingestion/verification/_finance_tabs`), печатная шапка отчёта
  с `align-items-start` (`report/cashflow`, `finance/report/preview`). Перевод с подгонкой структуры изменил бы отступы.

##### Ловушка
`container|default(true)` в Twig возвращает default и для `false` (фильтр проверяет «пусто», не «не определено») —
параметр `container: false` игнорировался; поймано тестом партиала, исправлено на `container is not defined or container`.

##### Тесты
- `tests/Integration/Shared/PageHeaderPartialTest.php` — include/embed, пустые условные actions без колонки,
  `container`/`print`/`class`, экранирование `title`.
- Существующие: `h2.page-title` в тестах Company (ЦФО, шаблоны доступа, участники), MoneyAccount, Cashflow JSON export,
  PlReportPreview — без правок; crawler-тесты Cash/Loan/BankSync — OK.

##### Files changed (PR C)
- `site/templates/partials/_page_header.html.twig` (new), 51 шаблон
- `site/tests/Integration/Shared/PageHeaderPartialTest.php` (new)

##### Проверки (PR C)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (324); `make site-cs-check` — 0 of 3916; `make site-cs-strict-types` — 0; `make site-stan` — OK
- целевые тесты (партиал, Company, Finance, Cash, Loan, BankSync) — OK (386 tests, 2479 assertions после фикса `container`)
- `make site-test` — OK (8547 tests, 48137 assertions), 8 мин 05 с; после правки по ревью — Cash + Integration/Shared OK (241 tests)
- Внешнее ревью (codex): REVIEW_GREEN за 2 раунда (база — ветка PR B b5a541e37, дифф 1836 строк, `site/var/external-review/b5a541e3/review.txt`). Раунд 1, IMPORTANT: в `cash/file_import_mapping` блок subtitle сохранил прежний `<h2 class="page-title">` — дубль заголовка (срез скрипта начинался с начала строки, когда после h2 шёл Twig-тег). Исправлено, остальные 50 файлов проверены grep'ом, добавлен тест-скан шаблонов `testPagesUsingThePartialDoNotRenderTheirOwnPageTitle`

#### PR D (3.4) — подписи из Enum вместо словарей в шаблонах

##### Что сделано
- `label()` добавлен в `DocumentStatus`, `SettlementBucket`, `SettlementDocumentKind`, `MoneyAccountType`,
  `CashCategorySuggestionStatus`; `DocumentType::shortLabel()` (короткие имена списков; `MARKETPLACE_PL` → `label()`,
  как и раньше через `??`). Тексты — в точности из удалённых словарей.
- Шаблоны: `document/{_list_layout,_page,show,index,operations}` (`statusLabels` ×3, `typeShortLabels`; select статуса —
  `enum_cases('…DocumentStatus')`), `settlement/{layout,card,_positions,documents,document}` (`bucketLabels`, `kindLabels`),
  `report/account_balances` (`typeLabels` → `enum('…MoneyAccountType').from(type).label()`),
  `admin/ai/company` (`statusLabels` → `tryFrom(status)`, неизвестный статус показывается как есть, как раньше `?? status`).
- Удалено 8 словарей из 16. Оставлены сознательно:
  `transaction/edit` `sourceLabels` (import_source — строка, Enum нет), `cash_transaction_auto_rule/*` `fieldLabels` ×2
  (поля правила, не Enum; разная длина подписи по контексту), `admin/ai/company` `skipLabels` (причины пропуска — строки
  из `RequestCashAiCategorizationAction`), `document/_list_layout` `directionLabels` (фильтр income/expense поверх `PLFlow`;
  в форме статьи тот же Enum подписан в единственном числе — общий `label()` дал бы разночтение);
  `report/cashflow` `responsibilityCenterLabels` (карта id→имя из данных) и `admin/users` `userRoleLabels` (производный
  список) словарями подписей не являются. Введение Enum для строковых полей — отдельная задача, не рефакторинг шаблонов.

##### Тесты
- `tests/Unit/Shared/EnumLabelsTest.php` — для каждого из 6 Enum: все case'ы, тексты как в удалённых словарях.
- Существующие функциональные тесты Finance (документы ОПиУ, чипы фильтров), Settlement, Admin — без правок.

##### Files changed (PR D)
- `site/src/Finance/Enum/{DocumentStatus,DocumentType}.php`, `site/src/Settlement/Enum/{SettlementBucket,SettlementDocumentKind}.php`,
  `site/src/Cash/Enum/Accounts/MoneyAccountType.php`, `site/src/Cash/Enum/Transaction/CashCategorySuggestionStatus.php`
- 12 шаблонов; `site/tests/Unit/Shared/EnumLabelsTest.php` (new)

##### Проверки (PR D)
- `make site-cs-twig` — twigcs 0, `lint:twig` OK (324); `make site-cs-check` — 0 of 3917 (после cs-fix теста); `make site-cs-strict-types` — 0; `make site-stan` — OK
- целевые тесты (unit Enum, Finance, Settlement, Admin) — OK (270 tests, 3258 assertions)
- `make site-test` — OK (8554 tests, 48144 assertions), 8 мин 18 с
- Внешнее ревью (codex): REVIEW_GREEN (1 раунд, база — ветка PR C 00f3f3434, дифф 535 строк, `site/var/external-review/00f3f343/review.txt`)

#### Деплой (Stage 3)
- Владелец одобрил стек одним сообщением; #2924 влит 05:36Z → деплой 05:49Z, #2925 → 05:53Z, #2927 (голова master,
  включает #2926) → 06:00Z; все с маркером «Деплой завершён без даунтайма», 12 контейнеров healthy, GlitchTip — только
  штатные ночные записи.
- Прогон deploy.yml для #2926 упал в фазе тестов на flaky `SettlementSourceLockOrderTest` (таймаут 90 с после #2922 не
  спас; тот же тест уронил CI PR D, rerun прошёл). Деплой с головы master покрыл все четыре PR, rerun старого прогона
  не делали — он задеплоил бы устаревший sha. Четыре параллельных прогона deploy.yml на общих runner'ах — фактор:
  из четырёх два упали на этом тесте. Предложение Владельцу — правка теста: тайминги фаз (boot → ready → waiting → locked)
  и снимок `pg_stat_activity` дочернего backend'а в сообщении об ошибке, чтобы следующий сбой показал причину.

#### Итог этапа
- Flash: три рендера (`partials/_flash`, `admin/_toasts`, `app/_ui/_flash`) с одним маппингом label → variant; 9 inline-копий
  удалены; побочно закрыт дефект карточки приглашения (`invite_token`).
- `partials/_empty` — 17 страниц; `partials/_page_header` — 51 страница (15 нестандартных шапок до Stage 6);
  подписи 6 Enum вместо 8 словарей (8 словарей без Enum остались с обоснованием).
- Тесты: `FlashShellsTest`, `EmptyStatePartialTest`, `PageHeaderPartialTest` (+ скан шаблонов на дубль заголовка),
  `EnumLabelsTest`, регрессия `CompanyMemberInviteFlowTest`.
- Ловушки этапа: Twig `x|default(true)` возвращает default и для `false`; срез скрипта переписи захватил `<h2>`, когда
  после него шёл Twig-тег; внешнее ревью диффует рабочее дерево с базой — `git fetch origin master:master` перед запуском.

