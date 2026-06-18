# Ingestion Blocks 4-7 Summary

## Общий статус

Блоки 4-7 выполнены и доведены до final handoff.

Финальный handoff: `docs/tasks/ingestion/handoff.md`.

Детальные stage reports: `docs/tasks/ingestion/stages/stage-1.md` - `docs/tasks/ingestion/stages/stage-10-correction-money-signed.md`.

`TASK-08` и `TASK-09` не выполнялись: task-файлы отсутствуют, scope был ограничен блоками 4-7.

## Блок 4: Cursor + SyncJob

**Статус:** DONE

Реализована оркестрация загрузок Ingestion:

- `IngestCursor` для хранения состояния курсора по company/connection/resource/shop.
- `SyncJob` для backfill, incremental и chunk jobs.
- Enum/state machine для статусов sync jobs.
- Миграция `Version20260618120000`: таблицы `ingest_cursors`, `ingest_sync_jobs`, индексы.
- Repository-методы с явным `companyId`.
- Actions для старта backfill, split на chunks, running/completed/failed, cursor update, финализации parent job.
- `SyncFacade`.
- `RunSyncChunkMessage` и handler.
- `IngestRateLimitGuard`.
- Messenger routing: `ingest_fetch`, `ingest_normalize`.

**Ключевой результат:** появился управляемый слой синхронизации, который может безопасно запускать загрузку чанками, отслеживать прогресс и не смешивать данные компаний.

## Блок 5: Connector + Canon + Normalization

**Статус:** DONE

Реализован канонический слой финансовых транзакций:

- Shared `Money` вынесен в `site/src/Shared/Domain/ValueObject/Money.php`.
- Shared `Money` поддерживает positive/negative/zero minor units; проверки неотрицательности должны жить в конкретных бизнес-сценариях.
- Entity:
  - `FinancialTransaction`
  - `Counterparty`
  - `NormalizationIssue`
- Миграция `Version20260618130000`: canonical transaction tables.
- Enums для source capabilities, transaction type/direction, issue kind.
- Contracts:
  - `SourceConnectorInterface`
  - `SourceMapperInterface`
  - `RawRecordAwareControlSumMapperInterface`
- Registries для connector/mapper.
- DTO для mapped transactions/control sums, pull/push requests/results.
- `NormalizeRawRecordAction`.
- `UpsertFinancialTransactionAction` с idempotency и stale-update защитой.
- `RecordNormalizationIssueAction`.
- `NormalizationCompletedEvent` и `AffectedPeriod`.
- `IngestionFacade`.
- `NormalizeRawRecordMessage` и handler.
- `IngestRawRecord` расширен методами normalization status.

**Ключевой результат:** появился единый canonical pipeline, куда разные маркетплейсы могут приводить данные в общий формат. P&L может строиться от канона, а не от legacy-документов.

## Блок 6: Ozon Connector

**Статус:** DONE

Реализована новая Ozon-загрузка поверх legacy API без изменения legacy flow:

- `LegacyOzonClientAdapter`.
- Ozon credential provider/adapter interfaces.
- `OzonSellerReportConnector`.
- Mappers:
  - daily seller report mapper
  - realization report mapper
  - transaction component mapper
- Ozon resource/type helpers.
- Money/parser/operation key helpers.
- Anonymized fixtures для Ozon.
- Документация mapping: `docs/ingestion/ozon-mapping.md`.
- Unit/integration tests на connector, mapper, registry, flow.
- Live API calls не выполнялись.

**Ключевой результат:** Ozon можно прогонять через новый Ingestion pipeline, при этом старый Marketplace/Ozon pipeline не выключен и не изменен.

## Блок 7: P&L Projection

**Статус:** DONE

Реализована инфраструктура dirty-period и rebuild P&L:

- `PLDirtyPeriod` в `App\Ingestion` для состояния dirty-period pipeline.
- `PLDirtyPeriodStatus`, `PLDirtyPeriodReason`.
- `PLDirtyPeriodRepository`.
- `PLDirtyPeriod` реализует `TenantOwnedInterface`.
- Миграция `Version20260618140000`:
  - таблица `pnl_dirty_periods`
  - nullable `rebuilt_at` в `pl_daily_totals`
  - nullable `rebuilt_at` в `pl_monthly_snapshots`
- `MarkPnlPeriodDirtyAction`.
- `NormalizationCompletedSubscriber`, который отмечает affected periods после normalization.
- `MaybeBlockByClosePeriodAction`.
- `RebuildPnlPeriodAction`.
- `PnlCategoryResolver`, `PnlPeriodResolver`, `PnlProjectDirectionResolver`.
- `PnlFacade`.
- `MarkPnlPeriodDirtyMessage`, `RebuildPnlPeriodMessage` и handlers.
- CLI command: `finance:pnl:rebuild-dirty`.
- Messenger route: `pnl_rebuild`.

**Важное архитектурное решение:**

- `PLDirtyPeriod*` принадлежит `App\Ingestion`; Finance оставляет только orchestration-классы для mark/rebuild.
- `shop_ref` не добавлен в `pl_daily_totals` или `pl_monthly_snapshots`.
- Source-scoped rebuild сейчас блокируется.
- Отдельный вопрос после приемки сохранен в `docs/tasks/ingestion/FOLLOWUP-finance-source-linking.md`: где правильно хранить source/origin links в Finance, вероятно ближе к `Document` / `DocumentOperation`, и возможно под другим бизнес-названием.

**Ключевой результат:** P&L теперь может пересобираться из canonical Ingestion transactions по dirty periods. Production switch/cron не включались.

## Проверки

Финально пройдено:

- `make site-test-unit` - OK, 1070 tests / 6438 assertions; остались существующие 1 warning и 1 deprecation.
- Focused integration по Ingestion/P&L - OK, 47 tests / 206 assertions; остались 2 существующих PHPUnit deprecations в Marketplace/MarketplaceAds.
- `lint:container --env=test` - OK.
- `doctrine:schema:validate --skip-sync --env=test` - OK по mapping.
- Scoped `php-cs-fixer` по измененным файлам - OK.
- `git diff --check` - OK.

Известное ограничение:

- `make site-cs-check` падает на существующем style drift вне scope: 659/1728 файлов. Dry-run ничего не изменил.

## Что не включалось

- Блоки 8 и 9.
- UI verification page.
- Shadow/admin workflow.
- Production cron/worker включение.
- Production switch с legacy Ozon/P&L на новый Ingestion flow.
- Live external Ozon API calls.

## Что проверить владельцу

- Миграции перед запуском в shared/prod окружении.
- Messenger transport/routing и ожидания по workers.
- P&L rebuild semantics: delete-and-rebuild за company/month, category mapping, close-period guard, retry behavior.
- Ozon mapper output на representative real reports перед shadow mode.
- Follow-up по Finance source/origin linking до добавления source reference fields.
