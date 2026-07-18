### Stage 7.10: Hardening and controlled production acceptance — DONE

**Risk:** HIGH-EXTERNAL for production acceptance; LOW for local test-only fix  
**Next action:** commit, push, Draft PR

#### What was done

- Ran full local unit and integration regression on current `master`.
- Fixed one pre-existing integration-test isolation failure in `NormalizeRawRecordActionTest`:
  - the test inserted a deterministic `SystemCounterparty` row;
  - migration `Version20260619110000` permanently seeds the same `system_counterparty` row in any correctly migrated test DB;
  - the Wildberries-only fixture now inserts the system counterparty only when missing and asserts the actual DB id by `source`.
- Ran read-only production acceptance using approved wrappers only.
- Confirmed production Company system-pair invariant and Cash auto-rule transition gate.
- Confirmed no production import, queue consume, migration, recalculation, backfill, or history run was executed.

#### Files changed

- `site/tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php` — idempotent system-counterparty fixture for stable full integration regression.
- `docs/reviews/cash-auto-rules-stage-7-10-report.md` — this report.

#### Definition of Done

- [x] Full unit suite checked.
- [x] Full integration suite checked and green after test isolation fix.
- [x] Company system `PROJECT_GENERAL × CFO_GENERAL` invariant checked on production read-only.
- [x] Active project-target auto-rules checked on production read-only.
- [x] Cash/Finance Project×ЦФО pair health checked on production read-only.
- [x] Production queues checked read-only.
- [x] Production import logs checked read-only.
- [x] No production writes, imports, queue consumption, migrations, recalculations, or backfills.

#### Baseline

- `make site-test-unit` — OK, 1517 tests, 8919 assertions.
- `make site-test-integration` — failed before the test fix:
  - `NormalizeRawRecordActionTest::testNormalizesRawRecordUpsertsTransactionAndDispatchesEvent`;
  - duplicate primary key in `system_counterparties`;
  - root cause: deterministic global fixture was not idempotent against an already seeded `source`.

#### Checks

- targeted: `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php` — OK, 7 tests, 37 assertions.
- full unit: `make site-test-unit` — OK, 1517 tests, 8919 assertions.
- full integration after fix: `make site-test-integration` — OK, 740 tests, 3631 assertions.
- lint/CS: `docker compose run --rm -T site-php-cli sh -lc 'php -l tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php && PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php'` — OK.
- diff hygiene: `git diff --check` — OK.

#### Production read-only acceptance

Commands used:

- `sudo /usr/local/bin/codex-docker-ps` — read-only Docker status.
- `sudo /usr/local/bin/codex-console messenger:stats` — read-only Messenger stats.
- `sudo /usr/local/bin/codex-psql-ro` — read-only aggregate SQL through `codex_ro`.

Results:

- Containers:
  - `site-php-fpm`, `site-nginx`, and worker containers were up; key app containers reported healthy where health checks exist.
- Messenger:
  - `async_sync`: 0
  - `async_pipeline`: 0
  - `ingest_fetch`: 0
  - `ingest_normalize`: 0
  - `pnl_rebuild`: 0
  - `async_wb_finance`: 0
  - `async_ads`: 0
  - `failed`: 3
- Company system pair:
  - companies total: 1344
  - missing exact active `PROJECT_GENERAL × CFO_GENERAL` pair: 0
  - duplicate `PROJECT_GENERAL`: 0
  - duplicate active `CFO_GENERAL`: 0
  - duplicate active system pair: 0
- Cash auto-rule transition gate:
  - active project-target rules total: 74
  - active project-target rules without ЦФО: 0
  - active `PROJECT_GENERAL` target rules without ЦФО: 0
- Cash transaction pair health:
  - active total: 4165
  - legacy `NULL/NULL`: 3290
  - legacy project without ЦФО: 875
  - ЦФО without project: 0
  - complete pair not allowed: 0
  - complete pair with archived ЦФО: 0
- Finance pair health:
  - `documents`: total 421, project without ЦФО 411, invalid complete pairs 0.
  - `document_operations`: total 759, project without ЦФО 719, invalid complete pairs 0.
  - `pl_daily_totals`: total 433, project without ЦФО 433, invalid complete pairs 0.
- Import logs for the current production day:
  - no rows; no post-deploy import smoke happened during this acceptance window.
- Messenger messages table:
  - `failed`: 3.

#### Internal automatic review

- Iterations: 1.
- BLOCKER: none.
- IMPORTANT: none.
- MINOR fixed:
  - idempotent integration fixture for `SystemCounterparty`;
  - report root-cause wording corrected after external review.
- FOLLOW-UP:
  - inspect the three existing failed Messenger messages through a separately approved read-only diagnostic path if operationally required;
  - run a bounded production import smoke only with immediate explicit owner approval because it mutates production.

#### External Claude Code review

- Iterations: 2 successful reviews after one max-turns retry.
- Result: REVIEW_GREEN.
- Confirmed findings fixed:
  - MINOR: report root-cause wording now references the migration-seeded `system_counterparty` row instead of a dirty/order-dependent DB.
- Rejected findings with reason: none.

#### GitHub review fixes

- `gemini-code-assist` noted that `ensureSystemCounterparty(IngestSource $source, string $name)` accepted a generic source while hardcoding the Wildberries UUID.
- Fixed by making the helper explicitly Wildberries-only instead of adding speculative source-to-UUID mapping.
- Post-fix external Claude Code review result: REVIEW_GREEN.

#### Risks / reviewer focus

- Production still contains expected legacy `project without ЦФО` rows because Stage 7 explicitly forbids backfill and historical recalculation.
- Invalid complete Project×ЦФО pairs were 0 in Cash and Finance aggregate checks.
- Today's production import logs were empty, so Stage 7.6.4 was accepted by invariants, not by a mutating import smoke.
- Failed queue count is 3; this report does not inspect message payloads and does not consume or retry anything.

#### Open questions

- none for read-only Stage 7.10 acceptance.
