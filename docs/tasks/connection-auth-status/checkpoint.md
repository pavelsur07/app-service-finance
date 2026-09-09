## Current checkpoint

**Phase:** Stage 1
**Status:** done
**Stage base commit:** `a1330be1`

### Completed

- 1.1 — enum `MarketplaceConnectionAuthStatus` (`OK` / `FAILED`)
- 1.2 — поля `authStatus`, `authFailedAt`, `authFailureCount` и геттеры на
  `MarketplaceConnection`; переход выполняется атомарным оператором в
  репозитории, а не методами сущности
- 1.3 — миграция `Version20260909060000`, expand-only, обратимая
- 1.4 — `RecordConnectionAuthResultAction` (порог 3), методы фасада
  `recordConnectorAuthFailure` / `recordConnectorAuthSuccess` /
  `getBrokenConnections`, `BrokenConnectionsQuery`,
  `MarketplaceConnectionRepository::findByIdAndCompanyId`
- 1.5 — 4 unit-теста на политику Action, 9 интеграционных тестов на машину
  состояний и изоляцию компаний на живой БД
- `ARCHITECTURE.md` — поля сущности и методы фасада описаны

### Checks and baseline

- baseline `a1330be1`: cs-check `Found 0 of 2489`, stan `No errors`,
  unit 2349 тестов / 12055 утверждений / 4 deprecation
- после Stage 1: cs-check `Found 0 of 2494`, cs-strict-types `Found 0 of 2494`,
  stan `No errors`, unit 2353 / 12070 / 4
- `ConnectionAuthStateTest` — 9 тестов, 43 утверждения, зелёный
- интеграционный набор целиком — 1286 тестов, 5897 утверждений, зелёный (первый
  прогон дал 142 ошибки `predis` из-за не поднятого `site-redis`)
- миграция применена и откачена на тестовой БД; `down()` — 4 оператора
- `doctrine:schema:validate`: маппинг корректен; расхождение
  `ALTER connection_type DROP DEFAULT` проверено откатом — предсуществующее
- PHPStan-baseline не изменён

### Review status

- internal: iteration 2, открытых нет
- external: раундов 2, открытых BLOCKER/IMPORTANT нет. Раунд 1 — две IMPORTANT
  (закрытый EntityManager, гонка), обе исправлены переходом на атомарный
  оператор. Раунд 2 — MINOR (мёртвый метод) исправлена; IMPORTANT про
  регрессионный тест на гонку вынесена в FOLLOW-UP с причиной, см. Stage Report

### Exact next action

Stage 1 закрыт. Дальше Stage 2: запись исхода из `RunSyncChunkHandler`,
`executeSyncable()` в `ActiveSellerConnectionsQuery` с переключением только
`RunIncrementalCommand`, снятие состояния в
`UpdateMarketplaceConnectionApiKeyController`.

### Files to inspect first on resume

- `site/src/Marketplace/Application/RecordConnectionAuthResultAction.php`
- `site/src/Marketplace/Entity/MarketplaceConnection.php`
- `site/migrations/Version20260909060000.php`

### Чужие файлы в рабочем дереве — не коммитить

`docs/plan/my_paln_app.md`, удалённые `docs/tasks/ui-pnl/*`,
`site/bin/capture-wb-inventory.sh`, `site/tests/Fixtures/Inventory/`,
`site/ui-kit/_audit/**`, `docs/integrations/`, `docs/tasks/ui-dashboard/task.md`,
`.mimocode/command/` — правки Владельца, к задаче не относятся.
