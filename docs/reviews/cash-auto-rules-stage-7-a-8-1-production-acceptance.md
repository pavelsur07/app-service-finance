# Stage A: Stage 7.8.1 post-merge production acceptance — DONE

## Scope

Close Stage 7.8.1 after PR #2198 merge without returning to it later.

Stage 7.8.1 added an optional read-only `responsibilityCenterId` / ЦФО filter to the existing ДДС cashflow report UI and public cashflow JSON/CSV code paths. It must not mutate production, import data, consume queues, run recalculations, run backfills, or change history.

## Result

Accepted with the available production evidence.

Important production limitation: current production has no active Cash transactions with non-null `responsibility_center_id`, so a positive live UI comparison where one selected ЦФО shows a subset of category rows is not possible without mutating production data. The positive behavior is covered by the Stage 7.8.1 regression tests and CI. Production acceptance therefore confirms deployment, health, data safety, and that no production data currently contradicts the rollout contract.

## Evidence

### Merge and deploy

- PR #2198: merged.
- Merge commit: `238fa83a0138008d1bfc7e17d67d2e94474fc23e`.
- GitHub Actions on `master` for the merge commit:
  - `🚀 Deploy to Production` — success.
  - `Frontend Lint` — success.
  - deploy job — success.
  - migrations job — success; no Stage 7.8.1 migration was introduced.

### Production read-only health

Command:

- `ssh vf-prod 'sudo /usr/local/bin/codex-docker-ps && sudo -u codex-prod sudo /usr/local/bin/codex-console messenger:stats'`

Result:

- `site-php-fpm`, `site-nginx`, Redis, PostgreSQL, and workers were up; key app containers with healthchecks were healthy.
- Messenger:
  - `async_sync`: 0
  - `async_pipeline`: 0
  - `ingest_fetch`: 0
  - `ingest_normalize`: 0
  - `pnl_rebuild`: 0
  - `async_wb_finance`: 0
  - `async_ads`: 0
  - `failed`: 3

The 3 failed messages are not consumed, retried, deleted, or inspected in Stage A. They remain part of Stage 7.11 read-only diagnostics.

### Production read-only data check

Command:

- `ssh vf-prod "/usr/local/bin/codex-psql-ro -c '<aggregate query>'"`

Result:

```text
active_cash_total: 4165
with_cfo: 0
project_without_cfo: 875
null_pair: 3290
min_date: 2025-08-01
max_date: 2026-07-12
```

Interpretation:

- No production Cash row currently has `responsibility_center_id`.
- A selected ЦФО filter would therefore return no filtered category rows in production until new/imported/manual/rule-applied transactions with ЦФО exist.
- This is compatible with Stage 7 constraints: no historical backfill and no production transaction mutation.

### Local regression check on current master

Command:

- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php tests/Unit/Report/Cashflow/CashflowReportRequestMapperTest.php tests/Unit/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatterTest.php`

Result:

- OK, 8 tests, 45 assertions.

Covered behavior:

- valid active company-owned ЦФО filters category rows;
- malformed/invalid/archived ЦФО ids are ignored;
- public JSON metadata contains `responsibility_center_id`;
- opening/closing balances remain company-wide while selected ЦФО filters category rows.

## Not executed

- No production imports.
- No queue consume/retry/delete.
- No migration.
- No recalculation.
- No backfill.
- No historical auto-rule run.
- No production transaction mutation.
- No raw report API token was printed or reconstructed; report keys are stored hashed.

## Final status

Stage 7.8.1 is closed and must not be reopened in the linear Stage 7 plan unless a new bug is reported.

Next linear stage: Stage B / Stage 7.11 consolidated cleanup.
