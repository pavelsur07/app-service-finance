# marketplace-provider-facades: закрыть внешние обращения к Ozon/WB-классам Marketplace

Этап 1 разделения `src/Marketplace` на `Ozon/` и `Wildberries/`. Соседние модули
импортируют три класса конкретного провайдера; перенос их сломает Ingestion и
MarketplaceAnalytics. Переводим обращения на фасады Marketplace без изменения
поведения: те же ключи Redis и бакеты лимитера, те же группы затрат, те же суммы
сверки.

Baseline (на `d7f2a599`):
- `phpunit WbFinanceReportClientTest MarketplaceCostAnalyticsGroupResolverTest WidgetGroupBackwardCompatTest WbFinanceRateLimiterTest` — OK (24 tests, 318 assertions)
- `phpunit VerificationQueriesTest --filter Reconciliation` — OK (2 tests, 17 assertions)
- `phpstan-baseline.neon` — 2463 записи; `testModuleInternalsAreClosedToOtherModulesMarketplace` — 8

## Stage 1: фасады вместо прямых импортов
Risk: MEDIUM (новые Facade-методы, межмодульная правка, без изменения финансовой семантики)
stage_base_commit: d7f2a599
Definition of Done:
- `WbFinanceReportClient` (Ingestion) зависит от `WbFinanceThrottleFacade`, а не от `WbFinanceRateLimiter`; сервис лимитера один, с `limiter.wb_finance` и Redis-хранилищем cooldown
- `ReconciliationQuery` (Ingestion) получает контроль Ozon через `MarketplaceSyncFacade::findLatestOzonTotalsCheck`, сущность наружу не отдаётся
- `MarketplaceCostAnalyticsGroupResolver` читает справочники через `CostCategoryCatalogFacade`; ветвление резолвера не меняется; мёртвый `WidgetServiceGroupMap` удалён
- существующие регрессионные тесты зелёные; новые тесты на фасады и IDOR-негатив
- baseline уменьшен ровно на 2 записи; `ARCHITECTURE.md` описывает новые фасады
- исключено: перенос файлов, YAML-справочники, остальные 6 пробоев границы, правка гейта
Work items:
- 1.1 — `WbFinanceThrottleFacade` + DTO, перевод клиента, `services.yaml`, тесты
- 1.2 — `findLatestOzonTotalsCheck` + DTO, перевод `ReconciliationQuery`, IDOR-тест
- 1.3 — `CostCategoryCatalogFacade` + DTO, перевод резолвера, удаление `WidgetServiceGroupMap`, тесты
- 1.4 — baseline −2, `ARCHITECTURE.md`, план систематизации
Stage checks:
- targeted phpunit по затронутым тестам; phpstan по изменённым файлам; cs-check
Reviewer focus:
- совпадение порядка проверок и текстов исключений лимитера; один экземпляр `WbFinanceRateLimiter`
- «первое вхождение побеждает» в справочнике Ozon; неизменность веток резолвера
- `Assert::uuid($companyId)` и фильтр компании в новом методе фасада
