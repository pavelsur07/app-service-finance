# Удаление отчёта `/marketplace/reconciliation` («Сверка»)

- **Класс:** Large (миграция схемы + backend + frontend + данные)
- **Ветка:** `chore/remove-marketplace-reconciliation`
- **stage_base_commit (Stage 1):** `260765fc`

## Цель

Убрать отчёт «Сверка» целиком: страницу `/marketplace/reconciliation`, все
`/api/marketplace/reconciliation/*`, React-остров, сущность `ReconciliationSession`,
таблицу `marketplace_reconciliation_sessions` и загруженные xlsx в объектном хранилище.

## Решения владельца

1. Удалять всё сразу, включая таблицу и файлы в хранилище.
2. Соседний мёртвый код (`ReconciliationLog`, `ReconciliationLogRepository`,
   `CostsVerifyQuery`, `ProcessingBatch`, `MarketplaceStaging`) не трогать — он не
   связан с этим отчётом. Зафиксировать FOLLOW-UP.

## Границы: что НЕ трогаем

`site/src/Marketplace/Application/Reconciliation/` — общий парсер Ozon xlsx. Второй
потребитель — `ReconcileCostsAction` («Закрытие месяца», `POST /marketplace/month-close/reconcile`).
Остаются: `OzonReportParserFacade`, `XlsxReaderService`, `RowClassifierService`,
`ReportAggregatorService`, `BaseSignResolverService`, `OzonXlsxServiceGroupMap`,
`CostReconciliationQuery`, `ReconciliationFileReadException`, `ReconcileCostsAction`,
`CostReconciliationController`, `Application/Processor/RECONCILIATION.md`,
`tests/Unit/Marketplace/Application/Reconciliation/*`.

Однофамильцы вне задачи: Ingestion verification (`/ingestion/verification/reconciliation`
+ vite-entry `ingestion_verification_reconciliation_page`), MarketplaceAds
(`WbAdSpendReconciliation*`), WB-финотчёты (`WbFinancialReportReconciliationService`),
cashflow `reconcile=dashboard` (`src/Report/Cashflow/`), Ozon accrual reconcile-команда.

## Stages

| Stage | Содержание | Риск | Definition of Done |
|---|---|---|---|
| 1 | Удаление backend + frontend + навигация + baseline + доки | MEDIUM | роутов нет, сайдбар не падает, гейты зелёные, month-close и ingestion-сверка живы |
| 2 | Миграция DROP TABLE + план удаления файлов в хранилище | HIGH-LOCAL | миграция применяется локально, `doctrine:schema:validate` зелёный |

## Handoff

PR несёт миграцию → запрос владельцу по форме AGENTS.md §3.2
(«run the migration and deploy #N»), плюс отдельным пунктом — удаление файлов
сверки в объектном хранилище (§3.3).

**Файлы удаляются поштучно по списку `stored_file_path`, а не по префиксу:**
префикс `marketplace/reconciliation/` делят с сохранённым `ReconcileCostsAction`
(«Закрытие месяца»), форматы путей идентичны. Детали — `stages/stage-2.md`.
