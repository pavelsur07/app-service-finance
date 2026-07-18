### Stage 7.7.3: P&L daily totals Project × ЦФО aggregation key — DONE

**Risk:** HIGH-LOCAL  
**Next action:** Draft PR

#### What was done

- Switched document-driven `pl_daily_totals` writes to aggregate by Project × ЦФО.
- Added deterministic repository `ON CONFLICT` targets for:
  - categorized rows keyed by `company_id × pl_category_id × date × project_direction_id × COALESCE(responsibility_center_id, zero-uuid)`;
  - uncategorized rows keyed by `company_id × date × project_direction_id × COALESCE(responsibility_center_id, zero-uuid)`.
- Preserved the deploy-before-migration window: new code falls back to the legacy conflict target until the new unique index exists.
- Added a forward-only migration that locks `pl_daily_totals`, checks duplicates, removes the old project-only key/index, and creates the new Project×ЦФО indexes.
- Made P&L category deletion merge affected daily totals into the uncategorized bucket inside one transaction before removing the category.
- Kept legacy `responsibility_center_id IS NULL` facts valid and unbackfilled.
- Updated architecture and Phase 0 documentation.

#### Files changed

- `site/migrations/Version20260718090000.php` — new switch migration.
- `site/src/Finance/Application/Service/PLRegisterUpdater.php` — Project×ЦФО aggregation.
- `site/src/Finance/Repository/PLDailyTotalRepository.php` — Project×ЦФО upsert, legacy fallback, category merge helper.
- `site/src/Finance/Entity/PLDailyTotal.php` — index metadata.
- `site/src/Finance/Controller/PLCategoryController.php` — transactional delete merge.
- `site/tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php` — conflict targets, category deletion, pre-migration fallback.
- `site/tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest.php` — register split by ЦФО.
- `site/tests/Integration/Finance/CreatePLDocumentActionTest.php` — fixture-safe daily total assertion.
- `site/tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php` — index contract assertions.
- `site/tests/Functional/Finance/PLCategoryEditControllerTest.php` — delete endpoint regression.
- `site/tests/Unit/Finance/Service/PLRegisterUpdaterTest.php` — aggregation structure update.
- `ARCHITECTURE.md` — Stage 7.7.3 contract.
- `docs/reviews/cash-auto-rules-stage-7-7-3-phase0.md` — Phase 0 plan.
- `docs/reviews/cash-auto-rules-stage-7-7-3-checkpoint.md` — checkpoint.

#### Definition of Done

- [x] Two document operations with same date/category/project but different ЦФО persist as two `pl_daily_totals` rows.
- [x] Same Project×ЦФО key upserts accumulate/replace as before.
- [x] Uncategorized totals upsert instead of duplicating for same company/date/project/ЦФО.
- [x] Category deletion still succeeds and merges daily totals into uncategorized rows.
- [x] Legacy `responsibility_center_id IS NULL` rows remain valid.
- [x] No historical rebuild is run.
- [x] Production/staging migration execution is excluded.

#### Baseline

- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest.php tests/Integration/Finance/CreatePLDocumentActionTest.php tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php tests/Functional/Finance/PLCategoryEditControllerTest.php` — OK, 14 tests, 113 assertions.

#### Checks

- clean local `app_test` migration to `Version20260718090000` — OK.
- targeted: `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Repository/PLDailyTotalRepositoryTest.php tests/Integration/Finance/PLRegisterUpdaterStornoSymmetryTest.php tests/Integration/Finance/CreatePLDocumentActionTest.php tests/Integration/Finance/ResponsibilityCenterFactSchemaTest.php tests/Functional/Finance/PLCategoryEditControllerTest.php tests/Unit/Finance/Service/PLRegisterUpdaterTest.php` — OK, 18 tests, 171 assertions.
- module: `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance` — OK, 35 tests, 201 assertions.
- unit: `make site-test-unit` — OK, 1516 tests, 8914 assertions.
- mapping: `docker compose run --rm -T site-php-cli php bin/console doctrine:schema:validate --skip-sync --env=test` — OK.
- targeted CS: php-cs-fixer dry-run on changed PHP files — OK.
- full `make site-cs-check` — failed due 595 pre-existing unrelated files outside Stage 7.7.3.

#### Internal automatic review

- Iterations: 2.
- BLOCKER: none.
- IMPORTANT fixed:
  - wrapped P&L category daily-total merge and category removal in one transaction.
- MINOR fixed:
  - removed redundant old non-unique `idx_pl_daily_company_cat_date`.
- FOLLOW-UP:
  - remove repository runtime schema detection after production migration is confirmed everywhere.

#### External Claude Code review

- Iterations: 5.
- Result: REVIEW_GREEN.
- Confirmed findings fixed:
  - documented deploy-before-migration switch contract and unsupported old-code/new-schema rollback mode;
  - dropped redundant old non-unique P&L daily index;
  - added regression coverage for `new code / old schema` fallback branch.
- Rejected findings with reason: none.

#### Review fixes applied

- Documentation now matches actual deployment ordering.
- New fallback regression test simulates pre-migration schema in a transactional integration test.
- Migration drops redundant old read index.

#### Risks / reviewer focus

- Production migration takes a table lock and performs duplicate guards; production execution remains HIGH-EXTERNAL and must be accepted separately.
- Old application code is not compatible with the new schema after the switch migration; rollback requires a reviewed forward-fix or redeploying Stage 7.7.3-compatible code.

#### Checkpoint

- `docs/reviews/cash-auto-rules-stage-7-7-3-checkpoint.md` updated.
- exact next action: commit, push, create Draft PR.

#### Open questions

- none

#### Expected owner response

- not required; continuing autonomously to Draft PR.
