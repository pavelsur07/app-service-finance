# Взаиморасчёты по действующей DS — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans for inline implementation. Steps use checkbox (`- [ ]`) syntax. Work items не являются Stage gates; действуют AGENTS.md и его единственный запрос merge + deploy на handoff.

**Goal:** Привести все страницы взаиморасчётов к действующей дизайн-системе «Ваш Финдир», сохранив финансовое поведение.
**Architecture:** Серверные Twig/Symfony Forms, существующий app kit. Небольшой React-остров адаптирует существующее ядро picker; значение и валидация остаются в CounterpartyPickerType/select.
**Tech Stack:** Symfony/Twig, CSS kit, React/TypeScript/Vite, действующие PHPUnit/Vitest, без новых зависимостей.
**Spec:** [design.md](design.md), включая аудит, подход и инварианты.
**Status:** План подготовлен. Реализация не начата; все нижние чекбоксы — будущие работы.
**Audit base:** `3c2cabc399226cceb33a10d41a10b3d5ca6a906d`. Baseline планирования: `git diff --check` — exit0, изменение только документации. PHP/frontend tests на подготовке плана не запускались.

## Global Constraints

- Эталон — шесть `docs/design-system/sheets/*.dc.html`, правила — `CLAUDE.frontend.md`; классы брать из `site/assets/styles/kit/`.
- Сохранить `_layout/app.html.twig`, Inter, действующие токены и общие partials вкладок/пагинации. Новые компоненты/модификаторы кита документировать; не создавать параллельный набор стилей Settlement.
- Все денежные значения и вычисления остаются через существующий Money. В JS не считать, не округлять и не преобразовывать денежные строки в Number.
- Не менять финансовые Actions/Domain/Repository/Query, DTO отчётов, схемы/миграции, права, API-контракты и набор фильтров. PHP Form опции отображения допустимы; серверная валидация и финансовые defaults сохраняются.
- Не ставить UI-библиотеки, jsdom/Testing Library или иной test runner. Vitest есть для чистой логики; DOM/компоненты проверяются браузером.
- `assets/react/_legacy/` не изменять/не импортировать в модуль. Имеющийся ErrorBoundary допускается только в entrypoint по действующему образцу budget_grid_page.tsx.
- Новые запросы app-адаптера — через `apiFetch`. Legacy fetch внутри существующего picker core остаётся совместимым default, не используется новым адаптером.
- 1 реализация = 1 отдельная ветка и Draft PR. Эта документационная ветка реализации не содержит. Без миграций; будущий выпуск по обычному merge + deploy.

## Review Focus

1. Hidden CSRF/requestId/version пропали/дублируются при замене form_widget на form_row: HTTP-повтор не должен создавать второй документ/распределение.
2. Длинный контрагент и крупная точная сумма при 390px: имя переносится, деньги не обрезаются, скролл только внутри таблицы.
3. Reader на форме записи: фильтры GET работают, write-поля/действия недоступны, принудительный POST403 и чужие ID404 сохраняются.
4. Picker: смена компании/архивный keep_id/поздний ответ A→B→A не подменяют выбранный ID; без JS и при сетевой ошибке форма заполнима.
5. Общие kit/picker изменения: существующие формы ОПиУ/ДДС и поля Баланса не теряют стили, поиск, клавиатуру и серверную валидацию.

## Phase 0 — перед кодом

- [ ] Создать свежую implementation-ветку от актуального master и изолированное рабочее дерево; этот план читать вместе с design.md.
- [ ] По Large workflow завести checkpoint и Stage Reports; зафиксировать base и DoD перед каждым Stage.
- [ ] Подготовить локальные PHP/Node test-контейнеры с текущим worktree. Production-данные/секреты не использовать; повторные PHP-тесты — exec в running test-container.
- [ ] Baseline: `php bin/phpunit tests/Functional/Settlement` в PHP test-container; frontend `docker compose run --rm site-frontend npm run lint`, затем `npm run build` и `npm run test:unit` тем же способом. Зафиксировать реальные результаты, не повторять исправные полные gates до handoff.
- [ ] Снять «до» на локальном авторизованном приложении с реальными сборкой, Inter, логотипом и assets. Старые офлайн smoke-снимки считать вспомогательными.
- [ ] Подготовить company-scoped fixtures: долг100000/оплата40000/остаток60000; Finance100000/основание120000; точные0.30, отрицательное движение−40000, длинные названия и допустимая крупная Money-сумма; writer/reader/чужая компания. Для датированных A5/A10 использовать в test-окружении существующий MockClock с московским today10.11.2026, подменённый до инициализации EM, как в SettlementAcceptanceTest; production Clock не менять.

## Stage 1 — общий каркас, controls и сетка

**Risk:** HIGH-LOCAL — новые модификаторы общего CSS-кита.
**DoD:** общий порядок шапка → вкладки → сообщения → контент; согласованные группы действий и поля; сетка шести KPI без 5+1. Новая CSS-семантика применяется только явно подключившими её страницами.

**Files:** `site/templates/settlement/layout.html.twig`, `_form_theme.html.twig`, `_filter.html.twig`, `_summary.html.twig`; `site/assets/styles/kit/patterns/page-scaffold.css`, `site/assets/styles/kit/components/kpi.css`.

### Work item 1.1 — каркас и controls

- [ ] В common layout выделить Twig block `settlement_header`, выводить его перед общими вкладками. Сохранить существующие названия/active состояния вкладок и их права.
- [ ] Шапка: `.wz-head.wz-head--responsive`, H1 `.wz-title`, подзаголовок `.wz-sub`, действия `.wz-head-actions`. Добавить только opt-in modifier `wz-head--responsive` для переноса и min-width:0; существующий `.wz-head` глобально не менять.
- [ ] Разнести flash по success/info/warning/danger компонентам alert; error — role=alert, прочие — role=status. Не использовать общий flex-ряд как внутреннюю компоновку alert.
- [ ] В локальной form theme различить input/select/textarea/checkbox, оформить required/help/error. `aria-describedby` объединяет существующие help/errors, а не стирает их. Widget attributes Symfony сохраняются.
- [ ] Фильтр: отдельные `form_row(from/to/counterparty/limit)` прямо в `.wz-row.wz-filters`, apply-кнопка в `.wz-row-actions`; `form_rest` сохраняет остальные поля. Никаких новых пресетов или серверных фильтров.

### Work item 1.2 — KPI

- [ ] Добавить opt-in `.kpi-grid` в kit/kpi.css: gap из существующих токенов, 3 колонки; при viewport≤1100px —2, ≤600px —1. Для обычных значений при1440/1280/768/390 получаются3/3/2/1 колонки. Если хотя бы одна точная decimal-строка имеет≥12символов, использовать generic modifier `.kpi-grid--wide-values`:2колонки, при≤1100px —1. Это только решение о ширине отображения, без округления или вычислений над суммами.
- [ ] Сохранить шесть `.kpi` в прежнем экономическом порядке и точные `vf_money(2,'RUB')`. Нулю — приглушённый стиль существующего formatter, расходам не назначать красный, текстам разрешить перенос.
- [ ] Проверить новый modifier/класс через `rg` по всем потребителям; определить влияние на базовую DS, не вводить переопределений общих цветов/шрифтов.

**Checks:** focused Twig lint четырёх шаблонов, frontend lint/build/test:unit, functional Settlement; screenshot summary1440/768/390 и smoke Баланса/API settings. Internal review полного Stage diff; внешний round только если HIGH-LOCAL diff>500lines. Report→commit→push/Draft update→Stage2 автоматически.

## Stage 2 — списки, карточки и все серверные формы

**Risk:** MEDIUM, финансовое поведение не меняется.
**DoD:** все13GET-экранов используют Stage1; данные, имена полей и POST-сценарии сохранены; состояние страницы понятно без чтения длинного абзаца.

**Files:** остальные `site/templates/settlement/*.html.twig`; новые локальные partials `_status.html.twig`, `_document_fields.html.twig`, `_source_fields.html.twig`, `_event_fields.html.twig`; `site/tests/Functional/Settlement/SettlementPresentationTest.php`.

### Work item 2.1 — отчёты и документы

- [ ] `index/card`: компактный фильтр, шесть KPI, отдельно подписанные просрочка/без срока/итоги всей выборки; в карточке сохраняются opening/increases/decreases/closing и пагинация движений.
- [ ] Таблицы: `.t-table`, обычные data cells — `td`, колонки денег и их заголовки справа; `caption`, `scope=col`; пустые строки — `td.t-empty`. Не обрезать денежные значения или скрывать корзины на mobile.
- [ ] Общая карточка таблицы `.card.card--flush`, заголовок `.card-head`, отдельный `.report-scroll` вокруг table, пагинация вне скролла внутри карточки. Скролл-область имеет подпись/focus; при скролле страница не растягивается.
- [ ] Список и детали: draft — neutral, posted — success, наличие reversal — warning с прежней ссылкой/датой. Финансовые ошибки показываются alert, а не красной суммой. Вид документа и знак движения не подменять статусом.
- [ ] Детали документа: выделить сумму/свободный остаток, затем реквизиты, источники, связи и движения. Все существующие ссылки на Cash/Finance/основание/оплату/отмену остаются.

### Work item 2.2 — формы и защитные состояния

- [ ] Новый документ: контрагент; ряд kind/bucket; ряд amount/effectiveDate/dueDate; reason на всю ширину; `.wz-actions` внизу. Рендерить поля явно, hidden requestId/_token/version ровно один раз через form_rest/существующий POST-form.
- [ ] Аналогично сгруппировать source, positions_form, setup и generic event form: метаданные источника отдельно от ввода, объяснение последствий в info/warning, первичное действие одно.
- [ ] Для Finance сумма обязательства остаётся пустой до ввода; Cash-сумма — только чтение. Суммы, даты и направление при смене внешнего вида автоматически не менять. Отмена сохраняет отдельные дату и причину; не заменять её браузерным confirm.
- [ ] READ: фильтры/выбор контрагента для отчёта доступны; формы записи показывают readonly state без доступной отправки, ссылки просмотра работают. POST guards не перемещать и не ослаблять.
- [ ] Сохранить preview≤50 позиций с полным количеством и переходом ко всем документам; не создавать новый непагинируемый список и не менять полный набор допустимых choices.
- [ ] В PresentationTest проверить наблюдаемые данные/права: `testSummaryKeepsSixSeparateExactBalances`, `testDocumentKeepsReversalAndSourceLinks`, `testReadOnlyKeepsFiltersAndHidesWriteControls`. Ошибки и повторы дополнительно проверяют существующие Journey/Validation/Access/SourceForms tests. Не писать PHPUnit-проверки совпадения каждого CSS-класса.

**Checks:** Twig lint всего каталога, functional Settlement и affected existing source UI tests; frontend gates; browser desktop/mobile с ошибками, readonly и длинными данными. Internal Stage review/report/commit/push→Stage3.

## Stage 3 — стандартный выбор контрагента в app layer

**Risk:** HIGH-LOCAL — совместимость общего picker core и новый browser adapter.
**DoD:** поиск/выбор по названию и ИНН соответствует стандарту DS, серверный select остаётся источником ID и границей company-scoped валидации; legacy не регрессирует.

**Files:** `site/assets/picker_core.js`, новый `picker_core.d.ts`; `site/assets/react/modules/settlement/features/counterparty-picker/picker-core.test.ts`; `site/assets/react/modules/settlement/features/counterparty-picker/{CounterpartyPickerWidget.tsx,CounterpartyPickerView.tsx,useCounterpartyPicker.ts,counterparty-picker.types.ts}`; `site/assets/react/entrypoints/settlement_counterparty_picker_page.tsx`; `site/vite.config.js`; `settlement/_form_theme.html.twig`, `layout.html.twig`; presentation options `SettlementDocumentType.php`, `SettlementCounterpartyType.php`, `SettlementReportFilterType.php`; `docs/design-system/components/entity-picker.md`.

### Work item 3.1 — переиспользование core

- [ ] Сохранить createSearch/createListbox/positionPortal и существующий adapter.counterparty; не копировать поиск, debounce и клавиатуру в Settlement.
- [ ] Расширить `createSearch({...})` необязательным `request: (url:string, signal:AbortSignal)=>Promise<unknown>`; default выполняет прежний legacy fetch. Новый app caller передаёт apiFetch. Поскольку у apiFetch сейчас нет signal, физическую отмену не обещать: поколение запроса меняется при cancel/schedule/run и исключает устаревшие ответы, включая A→B→A.
- [ ] Добавить createListbox opt-in `appearance:'legacy'|'kit'`, default legacy. Kit-вариант использует существующие picker/select/feedback классы, без Bootstrap/Tabler зависимости. Добавить точные декларации используемых экспортов в picker_core.d.ts без any.
- [ ] Pure Vitest tests: custom/default transport,250ms debounce, устаревший ответ и ошибка, A→B→A, cancel. Файл теста находится внутри assets/react (текущий tsconfig включает react/api); window в Node заменяется только stub таймеров. DOM/keyboard/appearance — браузерная проверка, без новых DOM-библиотек.

### Work item 3.2 — scoped app-адаптер

- [ ] Props острова: `selectId:string`, `searchUrl:string`, `describedBy?:string`; деньги в props не передаются. Twig data-props — JSON+html_attr escaping, существующие ID/name/select/options/keep_id сохраняются.
- [ ] Widget/hook владеют open/loading/results/error; View рисует оболочку и ref-контейнер для существующего imperative listbox. React управляет открытием/закрытием портала; core владеет только своим DOM-поддеревом. Entry — только mount с проверкой элемента/повтора и существующим ErrorBoundary.
- [ ] На открытии — выбранный, в том числе архивный keep_id, и первые20неархивных options; сетевой поиск с2символов,250ms, текущий endpoint≤20строк. Данные и поиск scope компании задаёт сервер, companyId в URL не добавляется.
- [ ] Выбор меняет только настоящий select и отправляет change. ID, отсутствующий в исходных scoped choices, не добавлять незаметно: показать предложение обновить страницу. Свободный текст не становится выбранным контрагентом.
- [ ] Required/disabled/error/help/label переносятся на видимое поле; без JS native select остаётся рабочим. При ошибке enhancement восстановить native select; при API-ошибке доступны retry и поиск по загруженным options.
- [ ] Клавиши ↑/↓/Enter/Esc/Tab, combobox/listbox/aria-activedescendant, возврат focus, закрытие снаружи, отсутствие перекрытия кнопок. Позиционирование — positionPortal, существующие DS popup размеры и классы; без отдельного z-index хака.
- [ ] В Form types задавать только существующий search_url через route api_counterparty_search; browse=true/keep_id/required/value_type/choices не менять. endpoint, API client и общий CounterpartyPickerType не переписывать.

**Checks:** pure Vitest + frontend lint/build/test:unit; changed PHP focused CS/Stan; functional Settlement, `tests/Functional/Company/Controller/Api/CounterpartySearchControllerTest.php`, `tests/Integration/Company/CounterpartySearchQueryTest.php`, `tests/Integration/Company/CounterpartyPickerTypeTest.php`; browser smoke legacy Cash/Finance browse-pickers и всех новых мест. Internal review и внешний round при HIGH-LOCAL diff>500; Report→commit/push→Stage4.

## Stage 4 — визуальная приёмка и handoff

**Risk:** MEDIUM. **DoD:** таблица ниже подтверждена; нет открытых BLOCKER/IMPORTANT, итоговый PR соответствует DS и сохраняет финансовые сценарии.

- [ ] Снять одинаковые fixtures «после» для всех13GET-экранов; на1440/1280/390 — все семейства, на768 — сетка/фильтр/сложная форма. Сравнить с «до» и исходниками DS, не только с уже работающим CSS-китом.
- [ ] Просмотреть default/empty/filtered-empty/error/readonly, draft/posted/reversed, источники free/active/reincluded, allocation/correction/classification/cancellation. Только реальные применимые состояния.
- [ ] Пройти A1/A5/A7/A8/A10 прежней приёмки через UI и проверить точные суммы/историю и один эффект при повторе. Старый backend register не переписывать ради внешнего вида.
- [ ] Handoff full gates один раз: `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`, `make site-test`; frontend lint/build/test:unit в site-frontend. API types check — только при фактическом изменении API (план его не предусматривает).
- [ ] Fresh final internal review и обязательный external read-only review по AGENTS.md. Зафиксировать findings/fixes/ограничения, Stage Reports и handoff, скриншоты и checklist.
- [ ] При изменении классов кита/контрактов picker обновить профильные DS/architecture docs текущими фактами. Ни один будущий компонент не объявлять существующим заранее.
- [ ] Commit/push, PR Ready, один запрос merge+deploy. Rollback — revert кода, миграций/преобразования данных нет. Production менять только после одобрения.

## Чек-лист приёмки реализации

| Done | ID | Наблюдаемый результат / доказательство |
|---|---|---|
| [ ] | UI01 | Все13GET-экранов имеют единый каркас и рабочие переходы; browser route matrix |
| [ ] | UI02 | Фильтр компактный, кнопка по содержимому; значения/пагинация сохраняются |
| [ ] | UI03 | Все6KPI:3/3/2/1 колонки обычных значений на1440/1280/768/390, без5+1; крупные значения — wide-values2/2/1/1; screenshot |
| [ ] | UI04 | Деньги0.30/60000/120000/−40000 точны, NBSP/копейки/знак/цвет DS, без float и сокращений |
| [ ] | UI05 | Длинные названия/суммы не растягивают страницу; horizontal scroll только внутри таблицы |
| [ ] | UI06 | Статусы draft/posted/reversed различимы текстом+бейджем; ссылка отмены сохранена |
| [ ] | UI07 | Empty/filter-empty/errors показаны соответствующими DS состояниями и сообщениями |
| [ ] | UI08 | Явные группы полей, типы controls, label/help/required/error/focus корректны; hidden поля не потеряны |
| [ ] | UI09 | Reader использует фильтры, не может записать; чужой ресурс404/POST403 остаются |
| [ ] | UI10 | Picker: название/ИНН, initial20, keep_id, клавиатура, очистка и корректный select ID |
| [ ] | UI11 | Picker: no-JS/API failure/stale A→B→A/смена компании/XSS-текст не приводят к неверному выбору |
| [ ] | UI12 | Финансовые A1/A5/A7/A8/A10 и повторы сохраняют прежние суммы и историю |
| [ ] | UI13 | Preview≤50, полные server choices, paginated tables/totals без новых запросов/неограниченных списков |
| [ ] | UI14 | Legacy Cash/Finance pickers, соседние Balance/API screens без регрессий; shared kit defaults прежние |
| [ ] | UI15 | Линтеры/build/unit/full PHP и review завершены; реальные результаты и снимки в handoff |

## Проверка самого плана

- [x] Изучены DS, код, действующие shared contracts и сохранённые локальные снимки.
- [x] Каждое замечание design.md связано со Stage и UI-критерием.
- [x] Разделены уже существующие компоненты и предлагаемые дополнения.
- [x] Контракты денег/компаний/прав и область изменения явно ограничены.
- [x] Будущие проверки и редизайн не отмечены выполненными.

Рекомендуемый способ исполнения — inline одним агентом: темы/kit/picker общие,
параллельная реализация увеличивает пересечения. Независимые reviews выполняются
по правилам репозитория. Сейчас поставляется только план; команда на реализацию
отдельная от принятия этой документации.
