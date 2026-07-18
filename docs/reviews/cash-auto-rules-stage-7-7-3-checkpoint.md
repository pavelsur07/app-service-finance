# Cash auto rules — Stage 7.7.3 checkpoint

## Current checkpoint

**Phase:** Stage 7.7.3 — P&L daily totals Project × ЦФО aggregation key  
**Status:** done — publishing Draft PR

### Completed

- Merged and verified PR #2194 / Stage 7.7.2 on `master`.
- Created branch `agent/cash-stage7-7-3-pl-daily-cfo-key`.
- Completed Phase 0 and saved `docs/reviews/cash-auto-rules-stage-7-7-3-phase0.md`.
- Implemented local Stage 7.7.3 diff:
  - `PLRegisterUpdater` groups daily totals by Project × ЦФО.
  - `PLDailyTotalRepository::upsert()` supports nullable `responsibilityCenterId`.
  - Repository keeps deploy-before-migration fallback and caches only the post-migration `true` state.
  - Added forward-only migration `Version20260718090000`.
  - Category delete merges affected daily totals into uncategorized bucket inside one transaction.
  - Updated tests and `ARCHITECTURE.md`.

### Current diff / affected files

- `docs/reviews/cash-auto-rules-stage-7-7-3-phase0.md` — new Phase 0 plan.
- `site/migrations/Version20260718090000.php` — new migration.
- `site/src/Finance/Application/Service/PLRegisterUpdater.php`
- `site/src/Finance/Repository/PLDailyTotalRepository.php`
- `site/src/Finance/Entity/PLDailyTotal.php`
- `site/src/Finance/Controller/PLCategoryController.php`
- `site/tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php`
- `site/tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest.php`
- `site/tests/Integration/Finance/CreatePLDocumentActionTest.php`
- `site/tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php`
- `site/tests/Functional/Finance/PLCategoryEditControllerTest.php`
- `site/tests/Unit/Finance/Service/PLRegisterUpdaterTest.php`
- `ARCHITECTURE.md`

### Checks and baseline

- Baseline before changes:
  - `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest.php tests/Integration/Finance/CreatePLDocumentActionTest.php tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php tests/Functional/Finance/PLCategoryEditControllerTest.php` — OK, 14 tests, 113 assertions.
- Local `app_test` migration:
  - initial `doctrine:migrations:migrate --env=test` failed due pre-existing local metadata/schema drift at old `Version20250219120000` (`bot_links.updated_at` already existed).
  - `make site-test-db-rebuild` recreated local `app_test`; migrations succeeded to `Version20260718090000`.
  - Clean `app_test` without fixtures also migrated successfully to `Version20260718090000`.
- Final completed checks:
  - targeted: `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest.php tests/Integration/Finance/CreatePLDocumentActionTest.php tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php tests/Functional/Finance/PLCategoryEditControllerTest.php tests/Unit/Finance/Service/PLRegisterUpdaterTest.php` — OK, 17 tests, 162 assertions.
  - module: `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance` — OK, 34 tests, 192 assertions.
  - unit: `make site-test-unit` — OK, 1516 tests, 8914 assertions.
  - mapping: `docker compose run --rm -T site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK.
  - targeted CS: php-cs-fixer dry-run on changed PHP files — OK.
  - full `make site-cs-check` — failed due pre-existing 595 unrelated files outside Stage 7.7.3.

### Review status

- Internal automatic review:
  - iteration 1 found IMPORTANT: category delete merge and ORM remove were not in one explicit transaction.
  - fixed by wrapping merge/remove in `EntityManagerInterface::wrapInTransaction()`.
  - iteration 2: no BLOCKER/IMPORTANT findings.
- External Claude Code review:
  - iteration 1: failed with `Reached max turns (20)`.
  - iteration 2: prompt narrowed to Stage 7.7.3 files; process hung without output and was interrupted after several minutes, returning `Execution error`.
  - iteration 3 with owner-approved `--max-turns 40`: returned one IMPORTANT documentation/deploy-contract mismatch and one MINOR redundant-index finding.
  - fixes applied: Phase 0/Architecture now explicitly document the deploy-before-migration switch contract and unsupported old-code/new-schema rollback mode; migration/entity now drop the redundant old non-unique `idx_pl_daily_company_cat_date`.
  - iteration 4: returned one IMPORTANT missing regression test for the required `new code / old schema` fallback branch.
  - fix applied: `PLDailyTotalRepositoryTest::testUpsertFallsBackToLegacyConflictTargetBeforeProjectCenterMigration()` simulates the pre-migration schema by dropping new indexes, restoring the legacy unique key inside the transactional test, resetting repository cache, and verifying legacy upsert/no-op category move behavior.
  - iteration 5: REVIEW_GREEN.

### Exact next action

- Commit Stage 7.7.3 changes.
- Push branch `agent/cash-stage7-7-3-pl-daily-cfo-key`.
- Create Draft PR.

### Files to inspect first on resume

- `site/src/Finance/Repository/PLDailyTotalRepository.php`
- `site/migrations/Version20260718090000.php`
- `site/src/Finance/Application/Service/PLRegisterUpdater.php`
- `site/src/Finance/Controller/PLCategoryController.php`
- `docs/reviews/cash-auto-rules-stage-7-7-3-checkpoint.md`
