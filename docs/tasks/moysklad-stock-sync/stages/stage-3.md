### Stage 3: POST-контракт и read model — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `25585a3bedd270d5355f4e7638ead566a20a9f72`
**Work items:** 3.1, 3.2, 3.3

#### What was done

- Добавлен tenant-safe POST ручного запуска `SyncStockSnapshotMessage` с UUID,
  CSRF, `MARKETPLACE_WRITE` и preconditions подключения.
- Status query переименован в `MoySkladSyncStatusQuery`, поддерживает пять
  потоков и сохраняет batch-историю до пяти запусков.
- Добавлен batch read последнего `completed` stock snapshot с числом строк;
  list-controller получает store/stock/snapshot данные только для текущей
  страницы Pagerfanta.

#### Files changed

- `site/src/MoySklad/Controller/MoySkladStockSyncController.php` — новый POST.
- `site/src/MoySklad/Controller/MoySkladConnectionsController.php` — batch read.
- `site/src/MoySklad/Infrastructure/Query/MoySkladSyncStatusQuery.php` — generic
  status/history/cursor и completed snapshot query.
- `site/tests/Functional/MoySklad/ConnectionsControllerTest.php` — endpoint,
  tenant isolation и snapshot fallback.
- `ARCHITECTURE.md`, task plan/checkpoint — актуальные контракты и workflow.

#### Definition of Done

- [x] Dispatch содержит только точные scalar `companyId`/`connectionId`.
- [x] GET/UUID/CSRF/write permission/foreign tenant/preconditions покрыты.
- [x] `store|stock` runs, cursor и история читаются batch-запросами.
- [x] Последний completed snapshot и `0`/ненулевое число строк читаются batch;
  failed/building не заменяют completed.

#### Checks

- baseline: `ConnectionsControllerTest` — 23 tests, 158 assertions, PASS.
- RED endpoint: 4 expected failures на отсутствующем route; GREEN: 4 tests,
  18 assertions.
- RED read model: отсутствующий service; GREEN: 1 test, 8 assertions.
- Stage functional: 28 tests, 184 assertions, PASS.
- focused PHP CS Fixer: 4 files, 0 fixable.
- focused PHPStan: no errors.

#### Internal review

- iterations: 1; BLOCKER: 0; IMPORTANT: 0; MINOR: 0; FOLLOW-UP: none.
- Проверены active company before lookup, company filters во всех SQL ветках,
  отсутствие N+1, latest completed ordering и отсутствие secrets/debug code.

#### External review

- required: no separate round; mandatory Large-task review runs once at handoff.

#### Risks / reviewer focus

- Query count фиксирован для страницы и не зависит от числа подключений.
- Новый endpoint не запускает каталог и не меняет retry/locking Stage 2.

#### Next

- continue to Stage 4 automatically.
