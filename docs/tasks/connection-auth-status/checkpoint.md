## Current checkpoint

**Phase:** handoff
**Status:** done
**Stage base commits:** Stage 1 `a1330be1`, Stage 2 `804c40fb`, Stage 3 `470a8bed`

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
- 2.1 — запись исхода из `RunSyncChunkHandler` через `MarketplaceFacade`
- 2.2 — `executeSyncable()`; переключён только `RunIncrementalCommand`
- 2.3 — снятие состояния при обновлении ключа, одной транзакцией с ключом
- 2.4 — `warning` на отказ, один `error` на переходе
- 2.5 — 4 теста обработчика, 2 теста команды крона, 2 теста выборки, 1 функциональный
- 3.1 — бейдж, строка с датой и кнопка «Обновить ключ» на странице подключений
- 3.2 — блок «Загрузка данных остановлена» на дашборде
- 3.3 — разметка Tabler, как на обеих страницах вокруг
- 3.4 — тексты без HTTP-кодов и фрагментов ключа
- серверный запрет ручной синхронизации по отвергнутому ключу (находка ревью)
- `ARCHITECTURE.md` — поля сущности и методы фасада описаны

### Checks and baseline

- baseline `a1330be1`: cs-check `Found 0 of 2489`, stan `No errors`,
  unit 2349 тестов / 12055 утверждений / 4 deprecation
- после Stage 1: cs-check `Found 0 of 2494`, cs-strict-types `Found 0 of 2494`,
  stan `No errors`, unit 2353 / 12070 / 4
- `ConnectionAuthStateTest` — 9 тестов, 43 утверждения, зелёный
- после Stage 2: cs-check и strict-types `Found 0 of 2496`, stan `No errors`,
  unit 2353 / 12070 / 4
- интеграционный набор целиком — 1294 теста, 5928 утверждений, зелёный
- функциональный `UpdateMarketplaceConnectionApiKeyControllerTest` — 25 / 100
- после Stage 3: cs-check и strict-types `Found 0 of 2497`, stan `No errors`,
  unit 2353 / 12070, интеграционный 1294 / 5928, функциональный 611 / 4993,
  `lint:twig` зелёный
- миграция применена и откачена на тестовой БД; `down()` — 4 оператора
- `doctrine:schema:validate`: маппинг корректен; расхождение
  `ALTER connection_type DROP DEFAULT` проверено откатом — предсуществующее
- PHPStan-baseline не изменён

### Review status

- internal: Stage 1 — 2 итерации; Stage 2 — 2 итерации (найден ложный алерт на
  каждом успешном чанке, исправлен предохранителем `Uuid::isValid`)
- external: Stage 1 — 2 раунда, Stage 2 — 1 раунд, Stage 3 — 1 раунд. Всего 10
  находок: 8 исправлено, 1 отклонена с причиной (модульные права —
  предсуществующее свойство страниц), 1 в FOLLOW-UP (регрессионный тест на
  гонку). Открытых BLOCKER/IMPORTANT нет

### Exact next action

Все три Stage закрыты. PR #2447 помечен Ready, ожидается решение Владельца
«merge and deploy». Подробности — `handoff.md`.

### Files to inspect first on resume

- `site/src/Marketplace/Application/RecordConnectionAuthResultAction.php`
- `site/src/Marketplace/Entity/MarketplaceConnection.php`
- `site/migrations/Version20260909060000.php`

### Чужие файлы в рабочем дереве — не коммитить

`docs/plan/my_paln_app.md`, удалённые `docs/tasks/ui-pnl/*`,
`site/bin/capture-wb-inventory.sh`, `site/tests/Fixtures/Inventory/`,
`site/ui-kit/_audit/**`, `docs/integrations/`, `docs/tasks/ui-dashboard/task.md`,
`.mimocode/command/` — правки Владельца, к задаче не относятся.
