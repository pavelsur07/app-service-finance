### Stage 1: ручной и первичный синк Ozon через by-day — DONE

**Risk:** MEDIUM
**Stage base commit:** `b71b6061`
**Work items:** 1.1, 1.2, 1.3, 1.4

#### What was done
- 1.1 — `OzonAccrualSyncPlanner` + `OzonAccrualSyncPlanResult`: единая постановка by-day (порог 2026-09-08, до вчера МСК, от новых к старым); `OzonFinancialReportsSyncCommand` на планировщике, поведение прежнее.
- 1.2 — `SyncConnectionAction` для Ozon SELLER ставит by-day, возвращает `SyncConnectionResult`; прочее — `ManualSyncNotSupportedException` (flash, не 404); контроллер сообщает окно и обрезку.
- 1.3 — `TriggerInitialSyncHandler`: Ozon SELLER → by-day с 01.01 (фактически с 08.09), прочие не-WB — skip.
- 1.4 — «Проверить» без реестра: Ozon Performance → `OzonPerformanceConnectionValidator`, WB → `WildberriesAdapter::authenticate`.
- `find()` в затронутых методах сужен через `instanceof`; baseline 2454 → 2427.

#### Checks
- targeted unit/integration зелёные; phpstan изменённых файлов OK; cs OK

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none
- MINOR исправлены в Stage 2: docblock `canRunManualSync`, широкий catch проверки Performance, `from > to` до Action, текст пустого окна, тесты flash (функциональный `OzonManualSyncTest`)
- FOLLOW-UP: порог общий для всех кабинетов; второй рубеж порога в `SyncOzonAccrualByDayHandler`

#### Next
- Stage 2 автоматически
