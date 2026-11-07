### Stage 2: отчёты, документы и формы — DONE

**Risk:** MEDIUM
**Stage base commit:** `d10c43891`
**Work items:**2.1,2.2

- [x] Все существующие страницы используют таблицы/card-header/footer и плотность ДДС;6корзин отдельно, точные server Money выражения сохранены.
- [x] Карточка:opening/increases/decreases/closing, paged movements, источники. Документы:метаданные, статусы, read navigation menu; без unsupported delete/export/direct allocation cancel.
- [x] Explicit partials полей документа/source/event; native forms/CSRF/requestId/version остались, form_rest выводит hidden один раз.
- [x] Finance amount пустой, Cash metadata readonly; readers без submit, write fields в disabled fieldset. Фильтры чтения активны.

**Checks:** Functional Settlement34tests/234assertions PASS (8новых). Новые summary/status/fieldset assertions сначала RED; readonly submit RED→GREEN. Journey100000−40000=60000, исторический/текущий остаток после отмены, SourceForms120000vsОПиУ100000, Validation/Access/nonce без изменения ожиданий PASS. Twig19files PASS; PHPStan new test PASS; CS3changed testfiles PASS. Frontend lint/build/67unit tests PASS.

**Browser:**4основных Settlement экрана и DDS reference×1440/1280/768/390: HTTP200, Inter, overflow страницы false. Снимки stage2-* в ignored evidence. Полная матрица13экранов/readonly/no-JS/stress — Stage3.

**Internal review:**1iteration; B0/I1fixed: reader form controllers не задают disabled FormView; templates теперь используют текущую permission для disabled fieldset/скрытия submit. MINOR: локальный overflow таблиц дажеdesktop, fixed Popper для row menu, сохранение границ KPI при длинной сумме. B/I open0.

**Selectors:** Journey .kpi→summary .col; Access .app-workzone→.settlement-ui. Финансовые/HTTP/count assertions сохранены.
**External:** MEDIUM Stage, not required; handoff required.
**Bundle:** новых entries/kit/global tokens нет; CSS только .settlement-ui. Динамика размера записывается в final acceptance.
**Next:**Stage3 automatically; acceptance/full gates/fresh internal+external reviews.
