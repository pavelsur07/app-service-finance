# TASK-FIX-05 — Ingestion backfill/incremental CLI plan

## Summary

Add CLI entrypoints for the existing Ingestion pipeline:

- `app:ingestion:start-backfill` starts Ozon backfill jobs for one company connection.
- `app:ingestion:run-incremental` starts incremental jobs only for active Ozon seller connections that already have cursors.
- Cron is a separate high-risk stage because it changes production scheduling.

## Stages

### Stage 1: Backfill CLI

Risk: MEDIUM.

- Add `App\Ingestion\Command\StartBackfillCommand`.
- Resolve Ozon resource types and shop reference.
- Support `--dry-run`, UUID validation, `days-back` bounds, and active-backfill warnings.

### Stage 2: Incremental CLI

Risk: MEDIUM.

- Add incremental application DTO/action and `SyncFacade::startIncremental()`.
- Add cursor repository lookup by resource.
- Add `App\Ingestion\Command\RunIncrementalCommand`.
- Default to Ozon only; skip Wildberries as out of scope.

### Stage 3: Cron

Risk: HIGH.

- Add the daily `app:ingestion:run-incremental` cron entry after owner review.

### Stage 4: Tests and handoff

Risk: LOW.

- Add command integration tests for dry-run, dispatch, duplicate active jobs, cursor skipping, and company filtering.
- Run focused tests, unit tests, CS check, and final diff review.

## Checks

- Targeted Ingestion command integration tests.
- `make site-test-unit`
- `make site-cs-check`
- `lint:container --env=prod` if available in the project.

## Assumptions

- No DB migration, public HTTP API change, Messenger routing change, dependency install, or live external API call is part of this task.
- Wildberries incremental is out of scope for TASK-FIX-05.
