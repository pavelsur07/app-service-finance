# Cash auto rules — Stage 7.11 consolidated cleanup/follow-ups report

## Status

- Phase: Stage B / Stage 7.11.
- Risk: MEDIUM for local code cleanup and debug-page filtering; production work was read-only only.
- Branch: `agent/stage7-linear-closure`.
- Next action: commit, push, and create Draft PR.

## Definition of Done

- [x] Current Stage 7 status docs no longer point to already-completed work as active next action.
- [x] Runtime P&L old-schema detection removed from `PLDailyTotalRepository`.
- [x] Raw P&L debug page can filter operations and daily totals by active company-owned ЦФО.
- [x] Malformed, foreign, archived, or missing ЦФО filter preserves current unfiltered behavior.
- [x] Existing Project × ЦФО P&L aggregation behavior remains unchanged.
- [x] Existing failed Messenger messages inspected through read-only production diagnostics only.
- [x] No production mutation, import run, queue consume/retry/delete, migration, backfill, or recalculation.
- [x] Targeted tests/checks pass.
- [x] Internal review completed; no unresolved BLOCKER/IMPORTANT findings.
- [x] External read-only Claude review returns `REVIEW_GREEN`.
- [ ] Draft PR created.

## What was done

- Added one current Stage 7 status source to stop stale next-action loops:
  - `docs/reviews/cash-auto-rules-stage-7-current-status.md`.
- Updated historical Stage 7 docs so completed stages are not presented as active next steps.
- Removed temporary runtime detection of the old `pl_daily_totals` uniqueness schema.
- Removed the legacy fallback test that intentionally dropped the Project × ЦФО unique indexes.
- Added the `responsibilityCenterId` filter to `/finance/reports/pl-raw`.
- Kept the filter additive:
  - empty or invalid UUID = no filter;
  - only active company-owned ЦФО choices are accepted;
  - operation-level ЦФО is authoritative, with fallback to document-level ЦФО;
  - totals filter uses `pl_daily_totals.responsibility_center_id`.
- Added a functional regression test for:
  - operation-level filtering;
  - document-level fallback;
  - mixed operations inside one document;
  - daily total filtering;
  - invalid filter preserving unfiltered behavior.

## Read-only production diagnostics

Production commands were read-only. No queue state was changed.

- Containers and workers were up/healthy.
- `messenger:stats` showed three existing failed messages.
- Read-only SQL against `messenger_messages` showed:
  - queue `failed`: 3 messages;
  - oldest available: `2026-06-29 15:25:31`;
  - newest available: `2026-07-17 11:10:35`;
  - message IDs: `1813`, `1814`, `1826`;
  - payload class was not safely detectable from headers/first serialized-class regex;
  - payload bodies were not printed or persisted.

Conclusion: this stage records the failed-message state without classifying payload contents or mutating the queue. Further diagnosis that prints payload/error details or retries/deletes messages remains outside Stage 7.11.

## Files changed

- `docs/reviews/cash-auto-rules-stage-7-current-status.md` — new current Stage 7 status source.
- `docs/reviews/cash-auto-rules-stage-7-11-followups-plan.md` — new consolidated cleanup plan.
- `docs/reviews/cash-auto-rules-stage-7-a-8-1-production-acceptance.md` — new Stage A production acceptance record.
- `docs/reviews/cash-auto-rules-stage-7-8-1-report.md` — mark Stage 7.8.1 merged/deployed/accepted.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — current phase and next action updated.
- `docs/reviews/cash-auto-rules-stage-7-6-plan.md` — Stage 7.6 closed status updated.
- `docs/reviews/cash-auto-rules-stage-7-6-4-report.md` — Stage 7.6.4 closed status updated.
- `docs/reviews/cash-auto-rules-stage-7-9-4-transition.md` — transition checklist closed.
- `docs/reviews/cash-auto-rules-stage-7-10-report.md` — closed status updated.
- `site/src/Finance/Repository/PLDailyTotalRepository.php` — removed old-schema runtime fallback.
- `site/tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php` — removed old-schema fallback test.
- `site/src/Finance/Controller/RawPlReportController.php` — added ЦФО filter and per-operation guard.
- `site/templates/finance/reports/pl_raw.html.twig` — added ЦФО select.
- `site/tests/Functional/Finance/Controller/RawPlReportControllerTest.php` — new regression test.

## Checks

- Baseline:
  - `make site-test-unit` — OK, 1522 tests / 8951 assertions.
  - `make site-cs-check` — pre-existing unrelated failure: 590 fixable files outside this stage.
- Targeted:
  - `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php tests/Functional/Finance/Controller/RawPlReportControllerTest.php` — OK, 2 tests / 45 assertions.
  - `docker compose run --rm -T site-php-cli php -l src/Finance/Repository/PLDailyTotalRepository.php` — OK.
  - `docker compose run --rm -T site-php-cli php -l src/Finance/Controller/RawPlReportController.php` — OK.
  - `docker compose run --rm -T site-php-cli php -l tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php` — OK.
  - `docker compose run --rm -T site-php-cli php -l tests/Functional/Finance/Controller/RawPlReportControllerTest.php` — OK.
  - `docker compose run --rm -T site-php-cli php bin/console lint:twig templates/finance/reports/pl_raw.html.twig --env=test` — OK.
  - `docker compose run --rm -T site-php-cli php bin/console lint:container --env=test` — OK.
  - `docker compose run --rm -T site-php-cli php vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php src/Finance/Repository/PLDailyTotalRepository.php src/Finance/Controller/RawPlReportController.php tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php tests/Functional/Finance/Controller/RawPlReportControllerTest.php` — OK.
  - `git diff --check` — OK.

## Internal automatic review

- Iterations: 2.
- Finding fixed:
  - IMPORTANT: raw P&L query filtered documents by operation ЦФО, but the render loop could still iterate non-matching operations from a mixed document. Fixed with an operation-level guard and regression coverage.
- BLOCKER: none.
- IMPORTANT: none remaining.
- MINOR fixed: targeted CS blank-line issue in `PLDailyTotalRepositoryTest`.
- FOLLOW-UP: none inside Stage 7.11.

## External Claude Code review

- Iterations: 2.
- Result: `REVIEW_GREEN`.
- First attempt: stopped with `Reached max turns (20)`.
- Second attempt: completed with `--max-turns 60` and the same read-only/safe-mode restrictions.
- Confirmed findings fixed: none.
- Rejected findings with reason: none.
- Non-blocking findings:
  - MINOR: per-operation ЦФО guard duplicates SQL-level filter intentionally because Doctrine may still expose non-matching operations from a selected document; covered by regression test.
  - FOLLOW-UP: pre-existing N+1 on the debug-only raw P&L page remains out of scope.

## Risks / reviewer focus

- Raw P&L is a debug page; filter is additive and does not change formulas, signs, period semantics, rebuilds, or public APIs.
- Removing old-schema runtime detection assumes Stage 7.7.3 production migration is complete. Stage 7.10 and Stage A production checks recorded this as accepted.
- Full-project CS remains red from unrelated pre-existing formatting drift; targeted stage files pass CS.

## Open questions

- none.
