### Stage 2: удаление v3 и мёртвых цепочек — DONE

**Risk:** HIGH-LOCAL (маршруты Messenger)
**Stage base commit:** `576152bd`
**Work items:** 2.1, 2.2, 2.3 + правки ревью

#### What was done
- Удалены: `OzonDailySyncCommand`, `OzonMonthRawRefreshCommand`, `OzonMonthRawRefreshPlanner`, `OzonMonthRawRefreshPlanItem`, `SyncOzonReportMessage/Handler` + маршрут, `InitialSyncMessage/Handler` + маршрут, `MarketplaceWeekPartitionService`, `OzonAdapter`, `MarketplaceAdapterRegistry/Interface` + теги, `findExistingDayDocument`/`findExistingInitialSyncDocument`, блок cron; 10 тестов удалённого.
- Остаются: `OZON_TRANSACTION_LIST_V3` и процессоры, `OzonRealizationFetcher`, `TriggerInitialSyncMessage`, `WildberriesAdapter` (+ `@return` вместо интерфейса).
- Документация: `ARCHITECTURE.md` (раздел by-day), `PATTERNS.md`, `prod-access.md`, `ozon-mapping.md`, docblock-и by-day.
- Правки внешнего ревью: состояние Ozon-подключения меняется только при поставленных задачах (регрессионный тест красный/зелёный); `TriggerInitialSyncHandler` проверяет компанию.
- baseline 2427 → 2410.

#### Checks
- `make site-stan` OK; cs, strict-types OK; unit/full — см. handoff
- `lint:container` OK; `debug:messenger` — исчезли только `InitialSyncMessage`, `SyncOzonReportMessage`; `list` — только `ozon-daily-sync`, `ozon-month-raw-refresh`

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none; MINOR исправлены (лог сбоя Performance до ответа API, тест пустого окна, комментарий `capture-ozon-accrual.sh`)

#### External review
- required: yes (HIGH-LOCAL, handoff); rounds: 1
- IMPORTANT (lastSyncAt при пустом окне) — fixed without re-run, регрессионный тест; MINOR (компания в первичном синке) — fixed

#### Next
- handoff
