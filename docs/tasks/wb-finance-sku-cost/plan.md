# План: товары, себестоимость и результат SKU в отчёте WB

## Phase 0

### Baseline

- Base branch: `master`
- Task branch: `agent/wb-finance-sku-cost-result`
- Task base commit: `cbc5775c7474e266486c07ea49bcedc09f97bd09`
- Команда:
  `docker compose run --rm -T site-php-cli php bin/phpunit tests/Unit/Marketplace/Application/Service/WbRawFinancialReportBuilderTest.php tests/Functional/Marketplace/Controller/WbRawFinancialReportControllerTest.php`
- Результат: `OK (15 tests, 116 assertions)`.
- Pre-existing tracked failures: нет.
- Посторонние untracked-файлы в рабочем дереве исключены из scope.

### Existing patterns

- `WbRawFinancialReportBuilder` — один проход по завершённым raw-документам,
  Money-safe агрегация без транзакционной модели.
- `WbRawFinancialReportQuery` — tenant-scoped DBAL read model.
- `InventoryCostPriceResolver` — историческая цена на дату и fallback на самую
  раннюю цену.
- `ListingSalesAggregateQuery` / `ListingReturnAggregateQuery` — соседние
  паттерны товарной агрегации; их транзакционные таблицы в этой задаче не
  используются.
- `wb_finance_report.html.twig` — существующие card/table/empty-state паттерны
  страницы без нового React/Vite entry.

### Expected areas

- `site/src/Marketplace/Application/Service/WbRawFinancialReportBuilder.php`
- новый application service для расчёта себестоимости и результата SKU
- новый tenant-scoped DBAL Query для листингов и истории цен
- `site/src/Marketplace/Controller/WbRawFinancialReportController.php`
- `site/templates/marketplace/wb_finance_report.html.twig`
- unit/integration/functional tests отчёта
- `site/src/Marketplace/WB_API_V5_FIELDS.md`

### Explicit exclusions

- `marketplace_sales`, `marketplace_returns`, финансовые транзакции и проводки;
- миграции, backfill и изменение существующей истории себестоимости;
- распределение логистики, хранения, штрафов и общих удержаний по SKU;
- FIFO, партионный учёт, остатки, отдельный API, React/Vite и новые зависимости;
- production/staging операции, merge, release и deploy.

## Stage 1: Raw-агрегация по SKU

Risk: HIGH-LOCAL

owner_gate: no

release_candidate: no

independently_deployable: no

stage_base_commit: `cbc5775c7474e266486c07ea49bcedc09f97bd09`

Definition of Done:

- каждая валидная товарная raw-строка попадает ровно в один сырой SKU-агрегат;
- размеры не объединяются;
- продажи и возвраты разнесены по количествам и суммам;
- сохранены даты и количества, необходимые для исторической себестоимости;
- корректировки без товара не превращаются в SKU;
- существующие summary/articles/deductions/reports/operations не меняют
  семантику;
- unit-тесты покрывают aliases, фильтр reportId, возвраты, корректировки,
  несопоставленные идентификаторы и даты.

Work items:

- 1.1 — добавить внутренний raw-контракт SKU в builder.
- 1.2 — агрегировать продажи/возвраты и cost-date buckets в том же проходе.
- 1.3 — добавить product lookup keys и сверочные итоги.
- 1.4 — добавить unit-тесты и обновить документацию raw-полей.

Stage checks:

- targeted PHPUnit для `WbRawFinancialReportBuilderTest`;
- полный unit suite Marketplace, если доступен;
- `make site-cs-check`;
- `git diff --check`.

Reviewer focus:

- отсутствие двойного счёта;
- соответствие существующим eligibility и знакам;
- Money без float;
- память: нет хранения исходных raw-строк;
- транзакционные таблицы не используются.

## Stage 2: Историческая себестоимость и результат SKU

Risk: HIGH-LOCAL

owner_gate: no

release_candidate: no

independently_deployable: no

stage_base_commit: `e601ddb2b1b914e6c34ab621eed80a1d204e2fc1`

Definition of Done:

- листинги сопоставляются пакетно и только внутри active company;
- конфликт barcode против `nmId + размер` не разрешается молча;
- история себестоимости загружается ограниченным числом запросов;
- продажи и возвраты используют утверждённую цепочку дат;
- рассчитаны проданная, возвращённая и нетто-себестоимость;
- по каждому SKU рассчитан результат либо явный статус невозможности расчёта;
- покрытие, fallback, unmapped и conflict видимы в read model;
- общие SKU-итоги равны сумме строк.

Work items:

- 2.1 — добавить DBAL Query каталога и истории цен.
- 2.2 — добавить service сопоставления, слияния raw-групп и расчёта стоимости.
- 2.3 — подключить service в оба controller action.
- 2.4 — добавить integration/functional tests tenant isolation, дат, fallback,
  missing cost и identity conflict.

Stage checks:

- targeted unit/integration/functional PHPUnit;
- Marketplace bounded-context tests;
- `make site-cs-check`;
- `git diff --check`.

Reviewer focus:

- tenant isolation и отсутствие IDOR;
- отсутствие N+1;
- inclusive effective date;
- RUB-only и нулевая цена как missing;
- скрытие недостоверного результата;
- переполнение и точность Money/BCMath.

## Stage 3: HTML, CSV и финальный Release Gate

Risk: MEDIUM

owner_gate: yes

release_candidate: yes

independently_deployable: yes

stage_base_commit: фиксируется после завершения Stage 2

Definition of Done:

- блок расположен после удержаний и перед `reportId`;
- общая сводка и таблица SKU показывают количества, деньги, себестоимость,
  результат и качество;
- отрицательный и нерассчитанный результат визуально различим;
- есть корректное пустое состояние;
- CSV содержит тот же SKU-раздел и spreadsheet-safe внешние строки;
- Twig/CSV/financial reconciliation покрыты функциональными тестами;
- документация формул обновлена;
- полный task diff прошёл внутреннее review и внешний Claude review с
  `REVIEW_GREEN`;
- task-ветка запушена, Draft PR создан и оставлен в Draft.

Work items:

- 3.1 — добавить summary и SKU-таблицу в Twig.
- 3.2 — добавить SKU-раздел в CSV.
- 3.3 — добавить functional tests порядка блоков, значений, empty/missing
  states и CSV parity.
- 3.4 — обновить документацию, выполнить полный check/review/fix цикл.
- 3.5 — подготовить handoff, commit/push и Draft PR.

Stage checks:

- targeted и полный релевантный PHPUnit;
- Twig lint и Symfony container lint;
- `make site-test-unit`;
- `make site-cs-check`;
- `git diff --check`;
- internal independent review;
- external read-only Claude Code review до `REVIEW_GREEN`.

Reviewer focus:

- точное положение блока;
- HTML escaping и CSV formula injection;
- адаптивность таблицы;
- нейтральные общие итоги и семантические статусы;
- отсутствие изменений транзакций, схемы, API и UI Kit.

## Release and Production gates

- Final Release Gate: после Stage 3 — Draft PR и точное решение Владельца о
  переводе в Ready/merge.
- Production Gate: в задачу не входит. Любой deploy или production mutation
  требует отдельной явной инструкции.
