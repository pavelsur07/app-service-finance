### Stage 7.4: Protected Company ЦФО management UI — DONE

**Risk:** HIGH
**Next action:** STOP; owner review required before HIGH-risk Stage 7.5 fact-schema migration

#### What was done

- Added protected `ROLE_USER` routes for listing, creating, editing, and archiving company ЦФО records.
- Added a separate protected form for configuring allowed project/ЦФО pairs through the Stage 7.3 Action.
- Added `Справочники → ЦФО` to the existing sidebar with route-aware active/open state.
- Added an explicit archived-record filter; system and archived states are visible in the list.
- Kept all entity reads scoped by the active company and returned 404 for cross-company IDs.
- Kept details, project configuration, and archive as separate CSRF-protected writes with expected versions.
- Added no role, voter, public API, React entrypoint, dependency, design-system component, schema change, migration, or production mutation.

#### Files changed

- `site/src/Company/Controller/FinancialResponsibilityCenterController.php` — protected company-scoped routes and form orchestration.
- `site/src/Company/Form/FinancialResponsibilityCenterType.php` — create/edit form.
- `site/src/Company/Form/FinancialResponsibilityCenterProjectsType.php` — scalar allowed-project form.
- `site/src/Company/Repository/FinancialResponsibilityCenterRepository.php` — company-scoped management list query.
- `site/templates/financial_responsibility_center/index.html.twig` — list, archive filter, and archive action.
- `site/templates/financial_responsibility_center/new.html.twig` — create page.
- `site/templates/financial_responsibility_center/edit.html.twig` — separate details and project forms.
- `site/templates/partials/_sidebar.html.twig` — route-aware `ЦФО` navigation item.
- `site/tests/Functional/Company/FinancialResponsibilityCenterControllerTest.php` — protection, navigation, lifecycle, pair configuration, version, and company-isolation coverage.
- `ARCHITECTURE.md` — protected management UI contract.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — stage status and next gate.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden migration, dependency, API, RBAC, or production action
- [x] Security, CSRF, company isolation, and IDOR access checked
- [x] Optimistic locking and system invariants preserved
- [x] No list-query N+1 introduced
- [x] Tests/checks run
- [x] Architecture documentation updated

#### Checks

- PHP syntax for changed PHP files — OK.
- Targeted PHP CS Fixer dry-run — OK, 5 files clean.
- Twig lint for the three ЦФО templates and sidebar — OK.
- `php bin/console lint:container --env=test` — OK.
- Protected route table inspection — OK.
- Focused functional test — OK, 3 tests / 34 assertions.
- `make site-test-unit` — OK, 1506 tests / 8853 assertions.
- `make site-test-integration` — OK, 711 tests / 3404 assertions.
- Local `app_test` migration metadata restored after test resets — 216 executed / 216 available.
- `make check-ui-kit` — existing repository-wide legacy-class violations remain, including the established Twig layout classes reused by this stage; no UI-kit configuration or design-system scope was changed.

#### Risks / reviewer focus

- Review that every record load includes the active `companyId` and cross-company IDs return 404.
- Review the separate details/project forms and expected-version handling for stale writes.
- Review that the system ЦФО cannot be archived and its system project pair cannot be removed.
- The legacy Twig screens use classes currently reported by the repository-wide UI-kit checker. Replacing those patterns or changing the checker is intentionally outside this stage.
- Production and financial facts remain untouched.

#### Open questions

- none
