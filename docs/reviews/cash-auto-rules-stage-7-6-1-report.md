### Stage 7.6.1: Cash scalar mapping and pair resolver — DONE

**Risk:** MEDIUM
**Next action:** DONE; production accepted, complete the Stage 7.9 Option A gate before HIGH-risk Stage 7.6.2 writer work

#### What was done

- Mapped the already deployed nullable `cash_transaction.responsibility_center_id` column as scalar `CashTransaction::$responsibilityCenterId`.
- Added a company-scoped scalar facade contract for the exact active `PROJECT_GENERAL × CFO_GENERAL` pair.
- Added one internal Cash resolver for create defaults, explicit active allowed pairs, and changed-update validation.
- Preserved unchanged legacy tuples by returning no requested update when submitted IDs equal stored IDs.
- Added focused integration coverage for system/explicit pairs, malformed/partial/cross-company/disallowed/archived cases, missing system data, update preservation, and real Doctrine round-trip.
- Did not connect the resolver to manual, facade, Telegram, or import writers.

#### Files changed

- `site/src/Cash/Entity/Transaction/CashTransaction.php` — nullable scalar mapping and accessors.
- `site/src/Cash/Application/Service/CashTransactionResponsibilityCenterResolver.php` — minimal pair resolution and validation.
- `site/src/Company/Application/DTO/FinancialResponsibilityCenterProjectDTO.php` — scalar pair DTO.
- `site/src/Company/Application/DTO/FinancialResponsibilityCenterDTO.php` — scalar active-state helper.
- `site/src/Company/Repository/FinancialResponsibilityCenterProjectRepository.php` — exact company system-pair query.
- `site/src/Company/Facade/FinancialResponsibilityCenterFacade.php` — system-pair DTO method.
- `site/tests/Integration/Cash/Application/Service/CashTransactionResponsibilityCenterResolverTest.php` — focused integration coverage.
- `ARCHITECTURE.md` — Stage 7.6.1 Entity/facade/resolver contract.
- `docs/reviews/cash-auto-rules-stage-7-5-report.md` — recorded completed Stage 7.5 production acceptance.
- `docs/reviews/cash-auto-rules-stage-7-6-plan.md` — implementation status.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — Stage 7 gate.
- `docs/reviews/cash-auto-rules-stage-7-6-1-report.md` — this Stage Report.

#### Self-review

- [x] Scope compliance: Stage 7.6.1 only
- [x] Existing Facade/DTO and scalar module-boundary patterns followed
- [x] No migration, writer, UI, import, auto-rule, queue, or production change
- [x] Company boundary, active state, and allowed pair checked
- [x] Malformed IDs fail before UUID database queries
- [x] Tests/checks run
- [x] Architecture documentation updated

#### Checks

- Focused Stage 7.6.1 integration test — passed: 5 tests, 19 assertions.
- Responsibility-center compatibility set — passed: 8 tests, 74 assertions.
- Symfony test container lint — passed.
- Targeted PHP CS Fixer for all seven Stage 7.6.1 PHP files — passed, zero fixable files.
- Full unit suite — passed: 1506 tests, 8853 assertions.
- Doctrine mapping validation with database sync intentionally skipped — passed.
- Full integration suite — 718 of 719 tests passed; the unrelated Ingestion/Ozon rolling-refresh test observed order-dependent shared state (`ozon_finance_accrual_types` instead of `ozon_finance_accrual_by_day`). Its isolated run passed: 3 tests, 21 assertions. No Cash/Company/Stage 7.6.1 test failed.

#### Risks / reviewer focus

- Resolver is intentionally not wired into runtime writers, so application behavior is unchanged.
- Nullable storage remains required for legacy rows and rolling compatibility.
- The application validation/removal race for configured pairs is not solved with speculative DDL in this stage.
- Option A remains the gate before writer defaulting: the complete-pair auto-rule transition must be designed before defaults can replace project `null` values.

#### Open questions

- none for Stage 7.6.1; Stage 7.6.2 remains blocked by its HIGH-risk owner gate.

#### Production acceptance

- PR #2186 merged to `master` as `167b24d4f5502fa7c9db6668a5ef891d66f41c1a`.
- Frontend lint, unit tests, API type sync, empty-database migrations, production image builds, rolling deployment, and the production migration job passed.
- Rolling-deployment logs reported PHP-FPM, PostgreSQL, and Redis healthy; application workers and supporting services started/running, and deployment completed without downtime.
- Production was already at `DoctrineMigrations\\Version20260717120000`; Stage 7.6.1 applied no migration, backfill, or historical recalculation.
- Runtime writers remain disconnected, so this stage does not assign or change transaction ЦФО values.
- The restricted production wrappers remained intermittent and hung without output, so a separate post-deploy SQL count was not counted as passed. Successful deployment/migration logs are the acceptance evidence; wrapper reliability remains an operational follow-up.
