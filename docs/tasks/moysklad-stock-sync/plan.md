# МойСклад: склады и снимки остатков

**Цель:** после принятого каталога загружать склады и полные снимки остатков
из проверенного подключения МойСклад внутрь модуля `MoySklad`. Другие модули,
включая `Inventory`, не изменяются. Запись в API МойСклад отсутствует.

**Контракт:** [`stock-api-contract.md`](stock-api-contract.md).

**Task base:** `13f706a92e847eb21d1c82c303257b5196735b3e`.

**Baseline:** `make site-test-unit` — 2965 tests, 16341 assertions,
4 существующих deprecations, PASS.

## Решения

- `MoySkladStore` хранит tenant-scoped идентичность, название, коды, путь,
  архив и source/load timestamps. Адреса, зоны, ячейки и raw JSON вне scope.
- `MoySkladStockSnapshot` — отдельный неизменяемый запуск со статусом
  `building/completed/failed`, временем начала и завершения. Читатели в будущем
  смогут использовать только `completed`.
- `MoySkladStockSnapshotLine` хранит склад, ровно одну ссылку на локальный
  товар или модификацию и исходные decimal `stock`, `reserve`, `inTransit`.
  Tenant composite FK не позволяют смешивать компании и подключения.
- Сначала выполняется полный upsert складов, затем создаётся новый снимок.
  Строки пишутся страницами, снимок публикуется и cursor `stock` продвигается
  только после полного прохода. Провалившийся снимок остаётся `failed` и не
  участвует в чтении.
- Отдельные `MoySkladSyncRun`/cursor для `store` и `stock`; общий advisory lock
  на stock flow. Повторный запуск создаёт новый снимок и не меняет предыдущий.
- Ручной запуск и статус добавляются на существующую страницу подключений.
  Расписания, retention, Facade для внешнего потребителя и перенос в Inventory
  проектируются отдельно при появлении потребителя.

## Stage 1: контракт, схема и парсеры

**Risk:** HIGH-LOCAL — три таблицы и миграция.

**stage_base_commit:** `13f706a92e847eb21d1c82c303257b5196735b3e`.

**Definition of Done:** Entity/DTO/parser точно фиксируют поля склада и строки
остатка; decimal, accountId, ссылки store/product/variant и типы meta строго
проверяются; миграция содержит tenant FK, уникальности, check ровно одной
assortment-ссылки и индексы; документированные JSON-фикстуры обезличены.

**Work items:**

- 1.1 — RED-тесты парсера складов и отчёта: account mismatch, неизвестный
  meta type, неизвестная ссылка, decimal/negative/zero, неверная форма страницы.
- 1.2 — DTO и parser, документированные Store/Stock fixtures.
- 1.3 — Entity, repository, builders, migration, tenant/constraint integration
  tests и обновление `ARCHITECTURE.md`.

**Stage checks:** MoySklad unit+integration, migration up/down на пустых
таблицах, focused CS/PHPStan. **Reviewer focus:** tenant FK, decimal precision,
полиморфная ссылка product/variant, guarded rollback, отсутствие raw JSON.

По решению владельца Stage 1 выпускается отдельным PR #2498. Stage 2 и Stage 3
остаются последующей работой в отдельных ветках после выпуска схемы и
контракта.

## Stage 2: полный снимок и отказоустойчивость

**Risk:** HIGH-LOCAL — внешний отчёт, транзакции и Messenger.

**stage_base_commit:** записать перед 2.1.

**Definition of Done:** store active+archived и stock report проходят все
страницы; size/duplicate/source-drift guards не публикуют неполный снимок;
страницы атомарны, курсоры обновляются только при успехе; 401/403/429/5xx,
сбой страницы и retry покрыты тестами; журнал безопасен.

**Work items:**

- 2.1 — RED-тесты HTTP-клиента и action для первичного/повторного запуска,
  zero/negative stock, pagination, missing store/assortment, drift и page error.
- 2.2 — расширить `MoySkladClient`; реализовать `SyncStockSnapshotAction` с
  advisory lock, page transaction, snapshot lifecycle и отдельными runs/cursors.
- 2.3 — Message/Handler на `async_sync`, bounded retry/backoff, интеграционные
  tenant и failure recovery tests.

**Stage checks:** MoySklad unit+integration, focused CS/PHPStan, Messenger
routing. **Reviewer focus:** полнота снимка, retry, отсутствие HTTP внутри DB
transaction, failed snapshots invisible, rate limits, stale-run recovery.

## Stage 3: ручной запуск, статус и handoff

**Risk:** MEDIUM — внутренний POST и существующий Legacy Twig экран.

**stage_base_commit:** записать перед 3.1.

**Definition of Done:** администратор своей компании запускает flow для
active+connected подключения; видит store/stock status, counters, cursor и
последний completed snapshot; CSRF, permission, foreign tenant, stale/running,
empty/error/success покрыты functional tests.

**Work items:**

- 3.1 — RED functional tests для маршрута, прав, CSRF, tenant isolation и UI.
- 3.2 — Controller, Message dispatch, расширение status query и Twig.
- 3.3 — документация, Stage report, полный handoff и reviews.

**Stage checks:** MoySklad functional, Twig lint, focused CS/PHPStan. На handoff
один раз: `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`,
`make site-test-unit`, `make site-test`, затем fresh internal и обязательный
external review.

## Release

PR #2498 содержит миграцию `Version20260921100000`. Разрешение владельца
покрывает merge Stage 1 после зелёного CI. Production-миграция и deploy требуют
отдельного ручного запуска по `docs/workflow/release.md`; до такого разрешения
production не изменяется.
