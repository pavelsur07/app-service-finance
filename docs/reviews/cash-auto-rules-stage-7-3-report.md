### Stage 7.3: Company ЦФО management backend — DONE

**Risk:** MEDIUM
**Next action:** STOP; owner review required before HIGH-risk Stage 7.4 protected routes/UI

#### What was done

- Added company-scoped Actions for creating, editing, and archiving ЦФО records.
- User-created ЦФО records receive an immutable generated `CFO_<UUID>` code; no user-facing code input was introduced.
- Added atomic replacement of allowed project/ЦФО pairs with explicit company checks.
- Added expected-version checks for edit, archive, and pair configuration.
- Pair changes bump the ЦФО version before pair writes in the same transaction, preventing concurrent stale configurations from overwriting each other.
- Preserved `CFO_GENERAL` and the `PROJECT_GENERAL × CFO_GENERAL` system pair.
- Added the version to `FinancialResponsibilityCenterDTO` for the later protected form flow.
- Added no route, controller, form, template, menu item, API, schema change, or migration.

#### Files changed

- `site/src/Company/Application/CreateFinancialResponsibilityCenterAction.php` — create Action.
- `site/src/Company/Application/UpdateFinancialResponsibilityCenterAction.php` — optimistic edit Action.
- `site/src/Company/Application/ArchiveFinancialResponsibilityCenterAction.php` — optimistic archive Action.
- `site/src/Company/Application/ConfigureFinancialResponsibilityCenterProjectsAction.php` — atomic pair configuration.
- `site/src/Company/Application/DTO/FinancialResponsibilityCenterDTO.php` — exposes version.
- `site/src/Company/Entity/FinancialResponsibilityCenter.php` — marks pair configuration changes.
- `site/src/Company/Facade/FinancialResponsibilityCenterFacade.php` — maps version.
- `site/src/Company/Repository/FinancialResponsibilityCenterProjectRepository.php` — company-scoped pair load.
- `site/src/Company/Repository/ProjectDirectionRepository.php` — company-scoped project ID load.
- `site/tests/Integration/Company/FinancialResponsibilityCenterActionsTest.php` — management, isolation, system guard, idempotency, and stale-write coverage.
- `ARCHITECTURE.md` — internal management contract.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — stage status.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No public route, UI, migration, dependency, or production change
- [x] Company/IDOR access checked
- [x] Optimistic locking and system invariants checked
- [x] Tests/checks run
- [x] Architecture documentation updated

#### Checks

- PHP syntax for changed PHP files — OK.
- Targeted PHP CS Fixer dry-run — OK.
- Focused Company integration tests — OK, 7 tests / 30 assertions.
- `make site-test-unit` — OK, 1506 tests / 8853 assertions.
- `make site-test-integration` — OK, 711 tests / 3403 assertions.
- `php bin/console lint:container --env=test` — OK.
- `php bin/console doctrine:schema:validate --skip-sync --env=test` — mapping OK; database sync intentionally skipped because Stage 7.3 has no schema change.

#### Risks / reviewer focus

- Review the two-step transactional pair write: the center version is flushed first, then pair rows, and the whole transaction rolls back together on failure.
- Review that all entity and project reads include `companyId`; a project from another company is rejected.
- Review that an unchanged project set is idempotent and does not increment the version.
- System ЦФО cannot be archived, and its system project pair cannot be removed.
- Production and financial facts remain untouched.

#### Open questions

- none
