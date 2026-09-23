# marketplace-ozon-v3-removal: убрать легаси Ozon v3, ручной и первичный синк Ozon — через by-day

Этап 4 разделения `src/Marketplace` по провайдерам (пункт 9 плана систематизации).
Ozon снял `/v3/finance/transaction/list` 09.09.2026; живой путь — `/v1/finance/accrual/by-day`.
`OzonAdapter` (v3) тянули три пользовательских сценария, сломанных с 09.09: кнопки
синхронизации подключения, первичный синк нового подключения, «Проверить» для Ozon
Performance. Решение Владельца 23.09.2026: перевести синк на by-day.

Прод (read-only, 23.09): v3-документов 967, последний 08.09; by-day — 61, ежедневно;
Ozon-подключений 4 seller + 4 performance, созданы до 17.07; в failed 56 из 61 —
`SyncOzonReportMessage` (id 1997–2052, 09.09 13:34).

Baseline (на `b71b6061`):
- `phpstan-baseline.neon` — 2454 записи
- `debug:messenger` — 47 обработчиков; снимок `bin/console list`
- targeted unit (`OzonFinancialReportsSyncCommandTest`, `SyncConnectionActionTest`, `TriggerInitialSyncHandlerTest`, `tests/Unit/Marketplace/Controller`) — OK 29/156; integration `TriggerInitialSyncHandlerTest` — OK 1/4

## Stage 1: ручной и первичный синк Ozon через by-day
Risk: MEDIUM
stage_base_commit: b71b6061
Definition of Done:
- `OzonAccrualSyncPlanner::planRange` — единая точка постановки by-day задач (порог 2026-09-08, до вчера, МСК)
- `OzonFinancialReportsSyncCommand` на планировщике, поведение не меняется
- `SyncConnectionAction`, `TriggerInitialSyncHandler` для Ozon SELLER ставят by-day задачи
- «Проверить» без реестра адаптеров: Ozon Performance — `OzonPerformanceConnectionValidator`, WB — `WildberriesAdapter`
- тесты планировщика, Action, хендлера, контроллера
Work items:
- 1.1 — планировщик + DTO + тест, перевод команды
- 1.2 — `SyncConnectionAction` + контроллер (flash)
- 1.3 — `TriggerInitialSyncHandler`
- 1.4 — проверка подключения
Stage checks: targeted unit/integration, phpstan изменённых файлов, cs
Reviewer focus: порог двойного учёта, часовой пояс, IDOR в Action, тексты flash

## Stage 2: удаление v3 и мёртвых цепочек
Risk: HIGH-LOCAL (маршруты Messenger)
Definition of Done:
- удалены `OzonDailySyncCommand`, `OzonMonthRawRefreshCommand`+планер+DTO, `SyncOzonReportMessage/Handler`, `InitialSyncMessage/Handler`, `OzonAdapter`, `MarketplaceAdapterRegistry/Interface`, мёртвый код `MarketplaceWeekPartitionService`/`findExistingInitialSyncDocument`, маршруты, блок cron
- остаются `OZON_TRANSACTION_LIST_V3` и процессоры, `OzonRealizationFetcher`, `TriggerInitialSyncMessage`, `WildberriesAdapter`
- baseline уменьшен по удалённым файлам; `debug:messenger` — исчезли только два сообщения
Work items:
- 2.1 — v3-команды и сообщение
- 2.2 — InitialSync-цепочка
- 2.3 — адаптеры/реестр, cron, docblock-и, baseline, ARCHITECTURE.md
Stage checks: полные гейты, lint:container, diff debug:messenger и list
Reviewer focus: ничего живого не зависит от удалённого; failed-очередь (56 сообщений) — удаление до деплоя с отдельным согласием
