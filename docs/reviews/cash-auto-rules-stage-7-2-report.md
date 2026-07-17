### Stage 7.2: Company ЦФО master data and project mapping — DONE

**Risk:** HIGH
**Next action:** STOP; owner review required before Stage 7.3 or any production migration

#### What was done

- Added the flat company-owned `FinancialResponsibilityCenter` model with immutable tenant/code, active/archive lifecycle, optimistic locking, and guarded `CFO_GENERAL`.
- Added allowed project/ЦФО pairs with same-company constructor checks and restrictive project/ЦФО foreign keys.
- Added company-scoped repositories, scalar DTO, and minimal facade methods for choices, lookup, pair validation, and allowed project IDs.
- Added nullable stable `ProjectDirection.systemCode`; default lookup now prefers `PROJECT_GENERAL` and keeps legacy name fallback.
- Protected the system project from deletion both in the HTTP deletion flow and with a Doctrine `PreRemove` invariant.
- Added additive migration `Version20260716170000`:
  - aborts on ambiguous legacy general-project candidates;
  - marks one approved candidate per company as `PROJECT_GENERAL`;
  - creates `PROJECT_GENERAL` when no candidate exists;
  - creates one `CFO_GENERAL` and one system pair per company;
  - verifies the system-pair invariant before commit.
- Updated the shared company bootstrap so company, owner membership, project, ЦФО, allowed pair, and default account are persisted in one flush.
- Updated fixtures and all known production creation-path tests, including manual company creation, public registration, owner account creation, and Admin account creation.
- Documented the new Company entities and facade contract in `ARCHITECTURE.md`.

#### Files changed

- `site/src/Company/Entity/FinancialResponsibilityCenter.php` — new ЦФО entity.
- `site/src/Company/Entity/FinancialResponsibilityCenterProject.php` — new allowed-pair entity.
- `site/src/Company/Enum/FinancialResponsibilityCenterStatus.php` — active/archive status.
- `site/src/Company/Repository/FinancialResponsibilityCenterRepository.php` — company-scoped reads.
- `site/src/Company/Repository/FinancialResponsibilityCenterProjectRepository.php` — company-scoped pair reads.
- `site/src/Company/Application/DTO/FinancialResponsibilityCenterDTO.php` — scalar cross-module DTO.
- `site/src/Company/Facade/FinancialResponsibilityCenterFacade.php` — minimal public contract.
- `site/src/Company/Entity/ProjectDirection.php` — stable nullable system code.
- `site/src/Company/Controller/ProjectDirectionController.php` — explicit system-project deletion guard.
- `site/src/Company/Repository/ProjectDirectionRepository.php` — code-first default lookup.
- `site/src/Company/Application/Service/CompanyOwnerMembershipCreator.php` — atomic system-pair bootstrap.
- `site/src/DataFixtures/ProjectDirectionsFixtures.php` — fixture invariant.
- `site/migrations/Version20260716170000.php` — additive schema/master-data migration.
- `site/tests/Unit/Company/FinancialResponsibilityCenterTest.php` — lifecycle and company guards.
- `site/tests/Integration/Company/FinancialResponsibilityCenterRepositoryTest.php` — company-isolated repository coverage.
- `site/tests/Functional/Company/CompanyCreateFlowTest.php` — manual company bootstrap coverage.
- `site/tests/Functional/Company/PublicRegistrationFlowTest.php` — registration bootstrap coverage.
- `site/tests/Unit/Company/CompanyOwnerMembershipCreatorTest.php` — shared bootstrap unit coverage.
- `site/tests/Unit/Company/CompanyOwnerAccountCreatorTest.php` — owner-account path coverage.
- `site/tests/Unit/Admin/Application/CreateAccountActionTest.php` — Admin path coverage.
- `ARCHITECTURE.md` — Company entity/facade contract.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No unrelated refactoring or dependencies
- [x] Company/IDOR access checked
- [x] No historical financial facts or report aggregates changed
- [x] Unit, mapping, container, syntax, and style checks run
- [x] Architecture documentation updated

#### Checks

- PHP syntax for changed PHP files — OK.
- Focused Company unit tests — OK; lifecycle, bootstrap, and both cross-company pair guards covered.
- `make site-test-unit` — OK, 1506 tests / 8853 assertions.
- `php bin/console lint:container --env=test` — OK.
- `php bin/console doctrine:schema:validate --skip-sync --env=test` — mapping OK; database sync intentionally skipped.
- `php bin/console doctrine:schema:validate --env=test` — mapping OK; full sync reports existing repository-wide drift plus the intentional DB-only company foreign keys. The SQL diff contains no missing Stage 7.2 table or column.
- Targeted PHP CS Fixer dry-run — OK.
- Pre-execution `doctrine:migrations:list --env=test` — migration discovered as `not migrated`.
- Doctrine schema SQL dump — new entity DDL matches the migration; command was read-only.
- Local `app_test` migration — OK; 1 migration / 23 SQL statements / approximately 113 ms.
- Post-migration invariant — OK; 1 company, 1 valid system pair, 0 invalid pairs.
- Post-migration financial row counts — unchanged: Cash 10, documents 18, operations 18, P&L totals 18.
- Targeted integration/functional tests — OK, 3 tests / 34 assertions.
- System-project deletion unit/functional checks — OK, 8 tests / 37 assertions.
- Full integration suite on clean `app_test` — OK, 705 tests / 3380 assertions.
- Full functional suite — 218 of 219 tests passed; one unrelated time-sensitive preview regression test failed.
- Local migration metadata restored after functional `DbReset`; `app_test` is at latest version with 216/216 migrations registered.

The functional failure is `SoftDeleteExclusionRegressionTest::testAutoRuleCheckPreviewExcludesSoftDeletedTransactions`. It creates transactions dated January 2024 but opens preview without explicit dates; in July 2026 the page defaults to the last six months and correctly scans zero rows. This predates Stage 7.2 and does not exercise ЦФО, project system codes, pairs, bootstrap, or the migration. It was not changed because it is outside the approved stage.

#### Risks / reviewer focus

- Review the migration candidate SQL and invariant assertion against the Stage 7.1 cohorts.
- Reviewer packaging feedback is addressed: all new ЦФО classes and `Version20260716170000` are included in the Stage 7.2 patch.
- System-project deletion is rejected before persistence in the controller and independently by the entity lifecycle callback.
- Company foreign keys for the scalar tenant IDs are deferred until transaction commit, so Doctrine insert ordering cannot break the atomic new-company bootstrap.
- Migration `up()` is additive and does not update Cash, documents, document operations, P&L totals, transfers, or aggregates.
- Migration `down()` removes the new tables and system-code column but deliberately preserves newly created general-project rows because it cannot safely distinguish them from pre-existing projects after the code column is removed.
- The database foreign keys enforce record existence; cross-company pair equality is enforced in the entity constructor and company-scoped repositories, as approved in Phase 0.
- The migration was executed only on local `app_test`. No production migration, production write, or production access occurred in Stage 7.2.

#### Open questions

- Existing functional test debt: pass explicit `dateFrom=2024-01-01` and `dateTo=2024-01-31` to the auto-rule preview regression test in a separate focused fix.
- Next gate: owner review of Stage 7.2.
