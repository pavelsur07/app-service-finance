# Только UI: взаиморасчёты как «Транзакции ДДС» — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans for inline implementation. Steps use checkbox (`- [ ]`) syntax. AGENTS.md имеет приоритет: Work item → targeted checks/checkpoint; review/commit/push — по Stage, без owner gate между Stage.

**Goal:** Привести13экранов взаиморасчётов к дизайну действующей страницы «Транзакции ДДС», сохранив всю прикладную логику.
**Architecture:** Twig/Symfony Forms на той же base/legacy оболочке и готовых DS-компонентах ДДС. Scope-specific CSS/JS обслуживает только разметку Settlement; существующие picker/widgets переиспользуются без нового React-острова.
**Tech Stack:** Twig, действующие Tabler/Bootstrap+DS, существующие JS/Vite/PHPUnit/Vitest; без новых библиотек.
**Spec:** [design.md](design.md) — требование владельца, визуальный образец, решение об оболочке и список неизменяемых контрактов.
**Status:** План обновлён по уточнению владельца. Реализация не начата. Чекбоксы ниже — будущие работы.
**Audit base:** `3c2cabc399226cceb33a10d41a10b3d5ca6a906d`; planning baseline `git diff --check` exit0. Сейчас меняется только документация, PHP/frontend tests не запускались.

## Global Constraints

- Основной визуальный ориентир: `transaction/index.html.twig` и его `_filter_bar`, `_tabs`; формы/карточки — transaction/new/edit/show, `_dds_op_fields` и DDS-OPERATION-SPEC.
- Сохранить13существующих GET-экранов и все routes/параметры/HTTP-коды/редиректы. Эталонный экран определяет оформление; листы DS — правила controls/денег. Отклонения от листов не копировать молча.
- Только UI: product-изменения ограничены templates/assets. Не менять PHP product files, API, Company scope/права, бизнес-логику, финансовые Actions/Query/DTO/Repository, Money, БД/миграции, CI/CD.
- Все денежные значения/вычисления — через существующий Money. В JS — только значения/ID UI; денег как Number, расчётов, округления или пересчётов нет.
- Hidden CSRF/requestId/version и server-side choices обязательны. Сумма основания Finance не подставляется из ОПиУ; Cash-сумма остаётся read-only; даты/направление автоматически не меняются.
- Не добавлять новые возможности Cash: импорт/экспорт/массовое удаление, поиск q и несуществующие фильтры. Не копировать встроенный JS index.html.twig целиком: он содержит специфичные для ДДС операции.
- Не менять общие design tokens, app kit, picker core/field/pill, API client, UiModeResolver/cookies или другие страницы. Допустимо только необязательное UI-значение URL в общей Twig-теме picker с прежним default.
- Новых React/Vite entries, пакетов, backend endpoint или самостоятельного picker не требуется. Новые styles/JS строго под `.settlement-ui`/`[data-settlement-ui]`.
- Будущая реализация — Large,3Stage, отдельная implementation-ветка/один Draft PR. Сейчас обновляется документационный PR, merge или production-действия не выполняются.

## Review Focus

1. Смена layout: не потерять flash/billing notice, меню/active-state и разрешённые действия; не подключить две оболочки одновременно. Owner:1.1.
2. Параметры фильтра/карточки: DDS dateFrom/dateTo не заменяют from/to; id пути сохраняется в пагинации и не берётся из query; без новых фильтров. Owner:1.2/2.1.
3. Переоформление форм: hidden tokens/nonce/version, reader403/чужой404, точность0.30 и отказ0.001, повтор запроса без второго эффекта. Owner:2.2.
4. Общий Counterparty widget: выбранный/архивный keep_id, ошибка API/no-JS/клавиатура/чужой ID; старые DDS/Finance формы работают как раньше. Owner:1.2/2.2.
5. Mobile/long data: точные деньги без обрезания, перенос названий, доступные dropdown/scroll, без скролла всей страницы и глобальных CSS-регрессий. Owner:2.1/3.1.

---

## Карта файлов

| Действие | Файлы | Назначение |
|---|---|---|
| Modify | `site/templates/settlement/layout.html.twig` | Единая base/legacy оболочка, шапка/вкладки |
| Modify | `index`, `card`, `documents`, `document`, `setup`, `form`, `source`, `positions_form`, `_filter`, `_summary`, `_positions`, `_form_theme` в `site/templates/settlement/*.html.twig` | Все13существующих шаблонов: оформление/раскладка/controls |
| Create | `site/templates/settlement/_tabs.html.twig`, `_status.html.twig`, `_document_fields.html.twig`, `_source_fields.html.twig`, `_event_fields.html.twig` | Локальные композиции над готовыми компонентами, явный порядок полей |
| Modify | `site/templates/form/counterparty_picker_theme.html.twig` | Один необязательный URL override; legacy defaults прежние |
| Create | `site/assets/styles/pages/settlement.css`, `site/assets/pages/settlement.js` | Изолированные особенности раскладки и focus периода |
| Modify | `site/assets/legacy-app.js` | Импорт page CSS/JS и init только при наличии root |
| Create | `site/tests/Functional/Settlement/SettlementUiRefactorTest.php` | Наблюдаемые UI-контракты, параметры/скрытые поля/ссылки |
| Modify only if needed | Существующие Functional/Settlement tests | Только устаревшие selectors, не ожидаемые суммы/права/статусы/число записей |

Read-only reuse: `base.html.twig`, `_layout/legacy.html.twig`, `partials/_tabs`,
`_pagination`, `_filter_pill_picker`, готовые picker JS и legacy DS-стили.
Transaction templates/CSS — эталон, не редактируемые файлы.

## Phase 0 — до продуктового кода

- [ ] Прочитать design.md, актуальные root/frontend rules; подготовить изолированную implementation-ветку с этим планом. Завести checkpoint и Stage Reports по Large workflow.
- [ ] Проверить mount локальных PHP/Node test-контейнеров на текущий worktree, без production config/data. Повторные PHP runs — exec в running test-container; команды ниже указаны внутри `/app`.
- [ ] Baseline: `php bin/phpunit tests/Functional/Settlement` и соседние picker tests; frontend через `docker compose run --rm site-frontend npm run lint`, затем `npm run build` и `npm run test:unit` тем же способом. Записать реальные pre-existing failures.
- [ ] Снять «до»: ДДС и Settlement, один user/company/ru-RU locale и viewport1440/1280/768/390, настоящий Inter/logo/собранные assets. Старый офлайн smoke использовать только как вспомогательную иллюстрацию.
- [ ] Fixtures: долг100000/оплата40000/остаток60000; Finance100000/основание120000;0.30 и−40000; пустой список/нет совпадений; длинный контрагент/крупная точная Money-сумма; writer/reader/чужая компания; отдельный контрагент с26движениями для limit25/page2.
- [ ] Для датированных сценариев — существующий MockClock, Moscow today10.11.2026, до инициализации EM, как SettlementAcceptanceTest. Production Clock не менять.

## Stage 1 — оболочка и готовые UI-компоненты ДДС

**Risk:** HIGH-LOCAL — подключение legacy UI и обратно совместимая shared theme.
**DoD:** Settlement использует тот же layout/assets, что ДДС; вкладки/период/контрагент/пагинация работают с прежними данными и параметрами. Никакого нового backend или React.

### Work item1.1 — layout, шапка, вкладки

**Files:** `settlement/layout.html.twig`, новый `_tabs.html.twig`; scoped page CSS/JS и `legacy-app.js`; `SettlementUiRefactorTest.php`.
**Interfaces:** layout consumes прежние blocks/title/context; produces блок `settlement_header` и `settlement_content`, wrapper `.settlement-ui[data-settlement-ui]`. JS export `initSettlementUi():void` ничего не делает без root. Tabs consume active route/canWrite, include существующий `partials/_tabs`.

- [ ] Добавить test `testUsesCashTransactionsShellAndExistingNavigation`: на `/settlements` ожидаются HTML `data-ui-mode=legacy`, H1 «Взаиморасчёты», вкладки «Сводка»/«Документы», существующая ссылка меню; у reader write-действий нет. Пример ключевого утверждения:
  ```php
  $client->request('GET', '/settlements');
  self::assertResponseIsSuccessful();
  self::assertSelectorExists('html[data-ui-mode="legacy"]');
  self::assertSelectorTextContains('h1', 'Взаиморасчёты');
  ```
- [ ] Run `php bin/phpunit tests/Functional/Settlement/SettlementUiRefactorTest.php --filter testUsesCashTransactionsShellAndExistingNavigation`. До изменения ожидается RED на app/legacy оболочке, а не ошибка fixture/route.
- [ ] Layout наследует base.html.twig, шапка использует vf-ops-head, описание и реальные кнопки справа; вкладки — в card-header. Добавить локальные blocks в дочерних шаблонах, не менять global base/sidebar. Active tabs: index/card→«Сводка», setup→«Начало учёта», остальные экраны→«Документы»; видимость настройки и write-действий остаётся по текущим правам.
- [ ] Использовать flash/billing notice из legacy layout ровно один раз; убрать дублирующий обход app.flashes из Settlement. Новые классы только для изолированных блоков, готовые DDS стили переиспользовать.
- [ ] Импортировать page CSS/JS в legacy-app.js. init выбирает только root; handlers периода/фокуса не затрагивают DDS и не выполняют запросы/денежные операции. Нового Vite entry нет.
- [ ] Run test до PASS; Twig lint изменённых шаблонов, baseline functional Settlement и браузерный smoke навигации/flash. Не писать тесты на каждый CSS-класс.

### Work item1.2 — фильтр, контрагент и пагинация

**Files:** `_filter.html.twig`, `_form_theme.html.twig`, общий `form/counterparty_picker_theme.html.twig`; page JS; UiRefactorTest.
**Interfaces:** filter consumes `filterForm/book`, optional route id; sends ровно существующие `from/to/counterparty/limit`. Пилюля использует `_filter_pill_picker` с `name:'counterparty'`, URL `api_counterparty_search`, adapter `counterparty`, initial DTO из `filterForm.counterparty.vars.counterparties` (selected/keep_id + до20неархивных). Route params имеют приоритет над query.

- [ ] Добавить tests `testReportFilterKeepsExistingQueryNamesAndResetsPage`, `testCardPaginationKeepsRouteIdAndPeriod`, `testCounterpartyFieldUsesExistingPickerAndNativeFallback`. Зафиксировать `from=2026-11-01`, `to=2026-11-10`, limit25, выбранный own party; при смене фильтра page не сохраняется. Карточка26движений даёт page2 с прежним `id/from/to/limit`, чужой query `id` не меняет route id.
- [ ] Run этих tests и existing Access/Validation baseline. Исправные финансовые/маршрутные проверки остаются положительными контролями, искусственно ломать их ради RED не нужно.
- [ ] Создать DDS-style период-поповер с двумя существующими date полями и submit GET. Не вставлять cash `_filter_bar` целиком; не использовать link-mode `_period_presets` с чужими dateFrom/dateTo. В текущем scope новых пресетов нет.
- [ ] Существующие фильтры представить пилюлями/controls; применённые условия — vf-chip/vf-applied со снятием одного условия и общим reset. Ссылки строятся с прежними query-параметрами, сбрасывая page. На карточке не показывать удалённый из filterForm counterparty; её scope задаёт route id.
- [ ] В local theme использовать bootstrap_5_layout и готовый counterparty widget через Twig use+alias. Общая тема принимает необязательный `counterparty_picker_search_url`, default — прежний `form.vars.search_url`; локальная тема задаёт path(api_counterparty_search). Без копии widget HTML, без изменений PHP Form types/core/endpoint.
- [ ] Сохранить select ID/name/choices/keep_id/browse=true, required/disabled, no-JS fallback, API error fallback и keyboard. Поиск формы/пилюли обеспечивают уже подключённые legacy-app counterparty_picker/picker_pill.
- [ ] Legacy `_pagination` вызывать с `params = request.query.all|merge(request.attributes.get('_route_params'))`; route id важнее query. Сам общий partial не менять.
- [ ] Run focused tests до PASS и соседние CounterpartyPickerType/SearchController/CashTransactionCounterpartyPicker tests; браузером проверить field/pill/search/focus/Esc/no-JS/API failure. JS не подключать заново на каждой странице.

**Stage checks:** `php bin/phpunit tests/Functional/Settlement`, `php bin/console lint:twig templates/settlement templates/form/counterparty_picker_theme.html.twig`; frontend lint/build/test:unit; `php bin/phpunit tests/Integration/Company/CounterpartyPickerTypeTest.php tests/Functional/Company/Controller/Api/CounterpartySearchControllerTest.php tests/Functional/Cash/Controller/CashTransactionCounterpartyPickerTest.php`. Internal review полного Stage diff; external только если HIGH-LOCAL diff>500lines. Report→commit→push/update Draft→Stage2 автоматически.

## Stage 2 — отчёты, документы и формы в стиле ДДС

**Risk:** MEDIUM. **DoD:** все13GET-экранов оформлены единообразно, исходные данные и сценарии сохранены.

### Work item2.1 — списки, сводка, карточки

**Files:** `index/card/documents/document/_summary/_positions` в settlement; новые `_status.html.twig`; scoped page CSS; UiRefactorTest.
**Interfaces:** consume прежние report/documents/position DTO; no changes to data providers. Status partial consumes `status` и существующую ссылку/дату отмены. Table row actions показывают только действия, уже обеспеченные текущим контекстом.

- [ ] Добавить `testSummaryKeepsSixSeparateExactBalances`, `testDocumentKeepsSourceAndReversalLinks`, `testReadonlyKeepsReadFiltersAndNoWriteActions`. Проверять60000.00,120000.00,0.30 и signed−40000 через точный вывод, шесть отдельных labels, источник и дату/ссылку отмены, отсутствие write-actions у reader.
- [ ] Run UiRefactorTest + existing Journey/SourceForms/Access. Зафиксировать browser «до» по отклонениям оформления; CSS сверяется в браузере, не десятками implementation-assertions.
- [ ] Списки — как DDS: одна card, tabs/card-header, компактные фильтры только там, где есть данные, информационная полоса с правильной единицей «контрагенты/документы», table-responsive/table/card-table/vf-ops-table и card-footer с общей пагинацией.
- [ ] Сохранить собственные колонки и шесть корзин. KPI оформить компактными карточками DS, обычные значения3/2/1 на1440/768/390 (1280 также3), через существующую row/column сетку. Длинные суммы получают больше места; их не сокращать/не обрезать ради числа колонок.
- [ ] У карточки контрагента сохранить opening/increases/decreases/closing и page движений. Не возвращать непагинируемую таблицу позиций. Preview форм≤50 и ссылка на полный список сохраняются.
- [ ] Детали документа: заголовок/статус, точные сумма/свободный остаток, реквизиты/источники/связи, движения, прежние действия. Draft нейтральный, posted OK, reversal Warn с датой; денежные значения не окрашивать по направлению Cash.
- [ ] Меню строки визуально как DDS, но только с уже доступными переходами. Не добавлять bulk/delete/export, не выводить неверный direct-cancel route для allocation без её allocationId; дополнительные запросы ради меню не нужны.
- [ ] Empty/filtered-empty/error/read-only состояния — готовые vf-empty/alerts/badges по DS. Header/row/title/пагинация/плотность сопоставляются с DDS, а не только друг с другом.
- [ ] Run focused tests до PASS; browser1440/1280/768/390, long names/large exact money, отсутствие горизонтального скролла всей страницы. В таблице собственный скролл допустим и доступен с клавиатуры.

### Work item2.2 — все формы и сохранность POST

**Files:** `form/source/setup/positions_form/document/_form_theme` и новые `_document_fields`, `_source_fields`, `_event_fields`; UiRefactorTest, существующие functional tests только при смене selectors.
**Interfaces:** consume те же FormView и URLs; hidden `_token/requestId/version` и form names неизменны. Локальные partials переиспользуют HTML/controls DS, не вставляют чужие DDS поля/обработчики.

- [ ] Добавить `testFormsKeepHiddenFieldsAndInputOnFailure`, `testSourceAmountsAndReadonlyStateStayExact`. По реальному HTTP сохранить ошибочный0.001, причину/контрагента, сообщение и нулевое число новых записей;0.30 сохраняется точно; readonly POST403 и чужой404 как прежде. Точные HTTP-коды брать из действующих сценариев, не вводить новые.
- [ ] Run существующие `SettlementValidationTest`, `SettlementJourneyTest`, `SettlementSourceFormsTest`, `SettlementAccessTest`. Суммы/права/nonce-результаты не менять ради новой разметки.
- [ ] Явно render form_row по группам: контрагент → вид/корзина → сумма/дата изменения/срок → причина. Source и event формы аналогично своим существующим полям; Cash metadata отдельно read-only, Finance amount изначально пустой.
- [ ] Использовать карточки/группы/сведения/нижние действия по new/edit DDS; scope-specific раскладку изолировать CSS. Не ставить data-dds-op: его JS зависит от полей/эндпоинтов транзакции. Не переносить dirty tracking/autosave или новый modal workflow.
- [ ] Hidden поля выводить ровно один раз; явная render-панель кнопок может использовать form=id, но submit остаётся прежним server POST. Сохранить отдельное проведение черновика и дату+причину отмены.
- [ ] Reader: фильтры просмотра доступны, write-поля/кнопки недоступны. Server guards не изменяются; UI не объявлять механизмом авторизации.
- [ ] Пройти основной journey100000−40000=60000 и идентичный повтор POST: один финансовый эффект, старое Cash allocatedAmount/ОПиУ не изменились. Корректировка/классификация/отмена проходят прежними Actions.
- [ ] Run targeted tests до PASS, Twig lint всего Settlement и frontend gates; browser empty/error/readonly, focus/help/required/disabled, реальный picker с keyboard/API error/no-JS.

**Stage checks:** functional Settlement + affected existing source UI tests; lint/build/test:unit; browser сравнение с DDS. Internal review/исправления/Report/commit/push→Stage3 автоматически.

## Stage 3 — визуальная и регрессионная приёмка

**Risk:** MEDIUM. **DoD:** checklist ниже подтверждён, UI соответствует DDS, запрет бизнес-изменений выполнен.

### Work item3.1 — проверка внешнего вида и сценариев

- [ ] Снять «после» на тех же fixtures/user/company/locale и1440/1280/768/390, что в Phase0. Сводку и документы сравнить рядом с DDS list; формы рядом с DDS new/edit/show. Логотип/шрифт/assets действительно загружены.
- [ ] Все13экранов: сводка, карточка, документы, документ, setup, новый документ, allocation, correction, Cash source, Finance source, classification, document reverse, allocation reverse.
- [ ] Пройти default/empty/filtered-empty/error/readonly, draft/posted/reversed, active/reincluded sources; неверный период/страница, archived party, long name/amount. Только реально применимые состояния, без фиктивных loaders.
- [ ] Проверить только UI diff allowlist: templates/assets/tests/docs. В product PHP/API/DB/Money/financial logic/permissions/UiMode/CI изменений нет. Если понадобится иное, не маскировать это под UI-рефакторинг: вынести из scope и сообщить владельцу.
- [ ] Проверить legacy DDS/Finance picker, соседние страницы и обе ссылки меню Settlement; страницу-эталон не изменять.

### Work item3.2 — gates и handoff

- [ ] Full gates один раз на handoff: `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`, `make site-test`. Frontend lint/build/test:unit — в site-frontend container. API types check не обязателен: API не меняется.
- [ ] Fresh final internal review + обязательный external read-only review Large по AGENTS.md. No BLOCKER/IMPORTANT; фиксировать реальные rounds/metrics/fixes/ограничения.
- [ ] Acceptance report содержит viewport/state matrix, before/after evidence, реальные результаты и проверку UI-only diff. Существующие функциональные assertions не ослаблены.
- [ ] Stage Report/commit/push/PR Ready; один merge+deploy запрос на handoff. Миграций нет; rollback — revert UI. Production не трогать до одобрения.

## Чек-лист приёмки реализации

| Done | ID | Критерий | Доказательство |
|---|---|---|---|
| [ ] | UI01 | ДДС и Settlement имеют одинаковую оболочку, типографику, шапку, кнопки, карточку и вкладки | side-by-side1440/1280; shell test |
| [ ] | UI02 | Все13GET-экранов доступны прежними routes; меню/active-state/ссылки работают | route matrix, browser |
| [ ] | UI03 | Фильтры визуально как DDS, но отправляют from/to/counterparty/limit; page сброшен при изменении | filter test, browser |
| [ ] | UI04 | Chips снимают только своё условие; reset/pagination сохраняют остальной контекст и route id | query/pagination tests,26movements/page2 |
| [ ] | UI05 | Шесть корзин отдельно;100000−40000=60000; opening/turnover/closing прежние | Journey/Report + exact UI assertions |
| [ ] | UI06 |0.30/120000/−40000 и крупная сумма точны;2копейки/NBSP/знак/₽/выравнивание, нет красных расходов | Money/Validation + screenshots |
| [ ] | UI07 | Статусы draft/posted/reversed и дата/ссылка отмены различимы без одного цвета | status/link tests |
| [ ] | UI08 | Таблица, метаданные строки, row menu, empty/error и footer соответствуют DDS | side-by-side всех состояний |
| [ ] | UI09 | Формы сгруппированы по образцу DDS; данные/ошибки/help/required/disabled/focus сохранены | browser + form tests |
| [ ] | UI10 | Native select/choices/keep_id работают; поиск имени/ИНН, keyboard, no-JS/API-error как у существующего picker | picker tests/browser |
| [ ] | UI11 | CSRF/requestId/version сохранены; повтор не дублирует запись; reader403, чужой404 | Journey/Validation/Access |
| [ ] | UI12 | Деньги/даты/source defaults не меняются автоматически, Finance не дублирует ОПиУ, Cash не создаётся повторно | SourceForms/Acceptance |
| [ ] | UI13 |390px: имена переносятся, суммы не обрезаются, скролл только внутри таблицы, controls доступны | mobile screenshots/keyboard |
| [ ] | UI14 | Нет новых API/БД/ролей/бизнес-кода/React/widget engine; DDS и старые формы не изменились | diff allowlist + regression smoke |
| [ ] | UI15 | Пройдены frontend/PHP gates и reviews; результаты и снимки сохранены, не заявлены заранее | handoff report |

## Self-review плана

- [x] Приоритет владельца «как Транзакции ДДС» заменяет прежнее предложение app/React.
- [x] Новый picker engine и изменения shared core/kit/backend исключены.
- [x] Учтены несовместимые названия параметров периода и обязательный id карточки в пагинации.
- [x] Файлы, потребляемые контракты, этапы и owning checks указаны; совпадение UI проверяется браузером.
- [x] К каждому Review Focus привязан owning Work item/test; будущие работы не отмечены выполненными.

План подготовлен для inline-исполнения одним агентом, как в текущем workflow.
Сейчас поставляется документация, запуск реализации требует следующего поручения.
