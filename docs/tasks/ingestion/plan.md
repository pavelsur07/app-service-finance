# Ingestion Blocks 4-7 Execution Plan

## Summary

- Scope: execute only existing `docs/tasks/ingestion/TASK-04` through `TASK-07`; `TASK-08` and `TASK-09` are out of scope because their task files are absent.
- Current repo state: `App\Ingestion` contains blocks 1-3 only; `messenger.yaml` has `async_sync` and `async_pipeline`, no ingestion transports yet.
- Because blocks 4, 5, and 7 require migrations, and block 7 touches P&L semantics, execution must be staged with owner review at every HIGH-risk stage.

## Implementation Stages

- Stage 1 - Block 4 schema/domain: add `IngestCursor`, `SyncJob`, sync enums, transition exceptions, zero-downtime migration for `ingest_cursors` and `ingest_sync_jobs`, plus entity invariant/unit tests. Risk HIGH. STOP after stage report.
- Stage 2 - Block 4 orchestration: add repositories, transition policy, commands/actions, `SyncFacade`, `RunSyncChunkMessage`, `IngestRateLimitGuard`, messenger transports/routing for `ingest_fetch` and `ingest_normalize`, test overrides, architecture update. Risk HIGH because Messenger config changes. STOP after stage report.
- Stage 3 - Block 5 canon schema: add `Money`, transaction/capability enums, `FinancialTransaction`, `Counterparty`, `NormalizationIssue`, migration, repositories, tenant-leak tests. Risk HIGH. STOP after stage report.
- Stage 4 - Block 5 normalization pipeline: add connector/mapper contracts, registries, DTOs, normalize/upsert/issue actions, `NormalizationCompletedEvent`, handlers/messages/routing, fake dev/test connector+mapper, `IngestionFacade`, and update `IngestRawRecord` with normalization state methods. Risk HIGH due Messenger routing and central pipeline. STOP after stage report.
- Stage 5 - Block 6 reconnaissance: read legacy Ozon API/client code and produce a short report in `docs/tasks/ingestion/stages/stage-5.md`; no code changes. Risk LOW.
- Stage 6 - Block 6 Ozon connector: add `LegacyOzonClientAdapter`, Ozon DTOs/resource constants, connector, daily/realization mappers, DI tags, anonymized fixtures, `docs/ingestion/ozon-mapping.md`, unit/contract/e2e tests using mocks. Do not modify legacy Marketplace clients or make live API calls. Risk HIGH because it crosses into legacy integration boundaries. STOP after stage report.
- Stage 7 - Block 7 P&L dirty-period schema: add `PLDirtyPeriod`, Ingestion enums, migration for dirty periods and nullable `rebuiltAt` on P&L aggregates, repository methods and invariant tests. Risk HIGH due Finance/P&L schema. STOP after stage report.
- Stage 8 - Block 7 rebuild infrastructure: add period resolver, category resolver, mark-dirty action/message/handler, ingestion event subscriber, close-period guard/event, rebuild action/message/handler, `PnlFacade` methods, rebuild dispatch command. Do not enable production cron automatically unless explicitly approved. Risk HIGH due financial semantics and Messenger config. STOP after stage report.
- Stage Final - Handoff: run full relevant checks, review complete diff, write `docs/tasks/ingestion/handoff.md`, and STOP.

## Public Interfaces And Contracts

- New Ingestion facades/contracts: `SyncFacade`, `IngestionFacade`, `SourceConnectorInterface`, `SourceMapperInterface`.
- New messages: `RunSyncChunkMessage`, `NormalizeRawRecordMessage`, `MarkPnlPeriodDirtyMessage`, `RebuildPnlPeriodMessage`; all tenant-aware messages implement `CompanyAwareMessage`.
- New transports/routing: `ingest_fetch`, `ingest_normalize`, and later `pnl_rebuild`, mapped to existing DSNs as specified by task files.
- No HTTP API changes in blocks 4-7.
- No live external Ozon calls during tests or implementation verification.

## Test Plan

- Per stage: focused unit/integration tests for entity invariants, repositories with explicit `companyId`, state transitions, actions, handlers, and tenant-leak coverage.
- Block 4: cursor advance, job transition matrix, split/backfill dispatch, parent finalization, rate-lock behavior.
- Block 5: money currency guard, canonical upsert idempotency/staleness, mapper failure, sum mismatch issue, event dispatch, fake full pipeline.
- Block 6: Ozon mapper fixture contracts, error classification, registry tags, mock-adapter end-to-end flow, no response-body logging.
- Block 7: dirty-period idempotency, Moscow timezone period resolution, old/new period marking, closed-period blocking, rebuild idempotency/lock behavior.
- Checks before final handoff: `make site-cs-check`, PHPStan command if available, `make site-test-unit`, integration tests if available, `git status --short`, `git diff --stat`.

## Assumptions And Guardrails

- `TASK-08` and `TASK-09` are intentionally excluded until task files are added.
- Existing uncommitted `docs/tasks/ingestion/*` and `.mimocode/` are treated as user changes and not overwritten.
- Migrations are create/additive only unless a later approved task explicitly allows destructive changes.
- Legacy Marketplace/Ozon code remains unmodified; Block 6 adds only a new adapter in Ingestion.
- Existing legacy P&L and Ozon pipelines remain active; Block 7 infrastructure must not switch production data flow or enable cron without explicit owner approval.
