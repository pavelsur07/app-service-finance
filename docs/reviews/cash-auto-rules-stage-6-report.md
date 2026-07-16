### Stage 6.1: Scalar exact conditions — DONE

**Risk:** MEDIUM
**Next action:** STOP, owner review required before the Stage 6.2 migration; Stage 6.3 also needs its business contract

#### What was done

- Added exact conditions for currency, import source, transfer flag, and document type.
- Reused the existing condition value, validation, matcher, preview, and worker path; no second matcher was introduced.
- Added explicit `__MISSING__` matching for transactions whose import source is `NULL`.
- Added canonical validation and UI hints for the new values.
- Documented the additive condition contract and remaining Stage 6 gates.

#### Files changed

- `docs/reviews/cash-auto-rules-stage-6-plan.md` — Phase 0 plan and gates.
- `ARCHITECTURE.md` — scalar condition and missing-lineage contract.
- `site/src/Cash/Enum/Transaction/CashTransactionAutoRuleConditionField.php` — new additive field cases.
- `site/src/Cash/Entity/Transaction/CashTransactionAutoRuleCondition.php` — operator matrix and value validation.
- `site/src/Cash/Form/Transaction/CashTransactionAutoRuleConditionType.php` — field labels.
- `site/src/Cash/Service/Transaction/CashTransactionAutoRuleService.php` — shared matcher branches.
- `site/assets/controllers/auto_rule_conditions_controller.js` — operator matrix and input hints.
- `site/tests/Unit/Cash/Entity/Transaction/CashTransactionAutoRuleConditionValidationTest.php` — validation matrix.
- `site/tests/Unit/Cash/Service/Transaction/CashTransactionAutoRuleServiceTest.php` — matching, AND, missing-source, and non-match coverage.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

No migration, route/API, queue/worker, production configuration, historical data, or existing condition semantics changed. The user-owned untracked `reports/` directory was not touched.

#### Checks

- Targeted PHPUnit validation/matcher tests — OK, 45 tests / 126 assertions.
- `make site-test-unit` — OK, 1497 tests / 8815 assertions.
- Targeted PHP CS Fixer dry run — OK, 0 fixable files.
- `php bin/console lint:container` — OK.
- `php bin/console doctrine:schema:validate --skip-sync` — mapping OK.
- `npm run build` — OK; existing non-blocking warning about unavailable `@symfony/ux-turbo/package.json` remains.
- `git diff --check` — OK.

#### Risks / reviewer focus

- `IMPORT_SOURCE` is intentionally case-sensitive and matches stored identifiers exactly.
- `__MISSING__` represents only database `NULL`; existing source rows are not normalized or backfilled.
- Document type equality is normalized only for surrounding whitespace and case.
- New fields affect no transaction until a user creates or edits a rule to use them.

#### Open questions

- Stage 6.3 requires owner-defined sample count, analysis period, confirmation source, target fields, consistency threshold, and tie behavior.

### Stage 6.2: Exact money-account condition — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required before deployment or Stage 6.3

#### What was done

- Added a nullable `MoneyAccount` association to auto-rule conditions.
- Added the exact `MONEY_ACCOUNT/EQUAL` matcher branch using stable entity identity.
- Restricted form choices to accounts from the active company and added domain-level cross-company validation.
- Added Stimulus behavior that shows only the relevant account selector.
- Added an additive migration with one nullable UUID column, one index, and one restrictive foreign key.
- Executed the migration only in the local test database; no production or development data was changed.

#### Files changed

- `site/migrations/Version20260716092436.php` — additive money-account condition schema.
- `site/src/Cash/Entity/Transaction/CashTransactionAutoRuleCondition.php` — association and company invariant.
- `site/src/Cash/Enum/Transaction/CashTransactionAutoRuleConditionField.php` — `MONEY_ACCOUNT` case.
- `site/src/Cash/Form/Transaction/CashTransactionAutoRuleConditionType.php` — account choice.
- `site/src/Cash/Form/Transaction/CashTransactionAutoRuleType.php` — scoped entry options.
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php` — active-company account loading.
- `site/src/Cash/Service/Transaction/CashTransactionAutoRuleService.php` — exact account matching.
- `site/assets/controllers/auto_rule_conditions_controller.js` — account row behavior.
- focused unit, integration, and functional tests.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions after explicit migration approval
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

Existing condition rows remain valid with `money_account_id = NULL`. The migration does not update transactions or rule conditions. No public route, queue, worker, or matching semantics for existing fields changed.

#### Checks

- Migration dry-run in local test environment — OK, one migration.
- Migration execution in local test environment — OK, 3 SQL queries / 27 ms.
- Targeted unit tests — OK, 48 tests / 130 assertions.
- Targeted integration and functional tests — OK, 4 tests / 19 assertions.
- `make site-test-unit` — OK, 1500 tests / 8819 assertions.
- Targeted PHP CS Fixer dry run — OK, 0 fixable files.
- `php bin/console lint:container` — OK.
- Migration PHP syntax — OK.
- `npm run build` — OK; existing non-blocking `@symfony/ux-turbo` warning remains.

#### Risks / reviewer focus

- Deploy the additive migration before code that reads the new association.
- The foreign key uses restrictive deletion so a referenced money account cannot be physically deleted while a rule condition uses it.
- The repository-wide test schema has unrelated pre-existing Doctrine drift. `schema:update --dump-sql` proposed no change for `cash_transaction_auto_rule_condition` after this migration.

#### Open questions

- none

### Stage 6.3: Read-only rule-candidate report — DONE

**Risk:** HIGH (owner-approved protected GET route)
**Next action:** STOP, owner review required before Stage 6.4 or deployment

#### What was done

- Added a protected, company-scoped candidate report linked from the Cash auto-rule list.
- Added one DBAL read model over the approved 180-day window and 5-sample/3-date/100%-consistency contract.
- Considered a category confirmed only when its latest category audit is a user action; a later auto-rule category audit excludes the sample.
- Accepted both current root-level and nested manual category audit shapes when no `autoRules` provenance is present.
- Excluded empty and whitespace-only import-source values without treating them as the `__MISSING__` null sentinel.
- Proposed category-only candidates from one exact signal at a time and capped output at 100 rows.
- Kept the request read-only: no rule creation, transaction mutation, messages, persisted report state, or historical recalculation.

#### Files changed

- `site/src/Cash/Application/DTO/CashTransactionAutoRuleCandidate.php` — scalar candidate read model.
- `site/src/Cash/Infrastructure/Query/CashTransactionAutoRuleCandidateQuery.php` — company-scoped aggregation query.
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleCandidateController.php` — protected GET page.
- `site/templates/cash_transaction_auto_rule/candidates.html.twig` — read-only report UI.
- `site/templates/cash_transaction_auto_rule/index.html.twig` — report link.
- `site/tests/Functional/Cash/Controller/CashTransactionAutoRuleCandidateControllerTest.php` — access, isolation, thresholds, provenance, and no-write coverage.
- `ARCHITECTURE.md` and the Stage 6 plan — approved report contract.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions after explicit route approval
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

The query includes explicit company predicates for transactions, audits, accounts, counterparties, and categories. Descriptions, INNs, raw bank data, amounts, and date-condition candidates are not selected or logged. The report has no write-capable dependency.

#### Checks

- Focused functional test — OK, 1 test / 29 assertions.
- Cash auto-rule controller regression tests — OK, 3 tests / 50 assertions.
- `make site-test-unit` — OK, 1500 tests / 8819 assertions.
- Targeted PHP CS Fixer dry run — OK, 0 fixable files.
- `php bin/console lint:container` — OK.
- Twig lint for both affected templates — OK.
- Symfony route inspection — OK, GET-only protected page path registered.
- `git diff --check` — OK.

#### Risks / reviewer focus

- Manual confirmation intentionally excludes legacy/imported assignments without a field-level user audit.
- A later auto-rule category audit excludes that transaction even when the resulting category ID is unchanged.
- Candidates are suggestions only; broad signals such as currency still require human judgement before any rule is created.
- The query uses existing transaction-period and audit-entity indexes and creates no schema objects.

#### Open questions

- none

### Stage 6.4: Final hardening and production acceptance — DONE

**Risk:** MEDIUM locally; HIGH for production acceptance
**Next action:** STOP, final owner review required

#### What was done

- Reviewed the complete Stage 6 diff from the Stage 5 baseline through the merged Stage 6.3 change.
- Re-ran the full unit suite and focused functional/integration regressions on merged `master`.
- Verified that existing persisted condition fields remain supported; Stage 6 only adds enum values.
- Confirmed the production rolling deployment, healthy application/workers, and the applied additive money-account migration.
- Executed the candidate aggregation read-only against production data; it completed successfully and returned the valid empty state for the checked company.
- Did not mutate transactions, create rules, dispatch messages, or enqueue historical ranges.

#### Files changed

- `docs/reviews/cash-auto-rules-stage-6-plan.md` — final Stage 6 status.
- `docs/reviews/cash-auto-rules-stage-6-report.md` — final hardening and production acceptance report.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Tests/checks run
- [x] Documentation updated

The final Stage 6.4 change is documentation-only. Production checks used restricted read-only wrappers. No application code, schema, configuration, queue, rule, or transaction was changed during acceptance.

#### Checks

- GitHub production workflow for merge `42860450` — OK, including build, rolling deployment, and Doctrine migration job.
- Production container status — OK; PHP application and workers healthy.
- Production migration `DoctrineMigrations\\Version20260716092436` — present.
- Production persisted auto-rule condition fields — compatible (`COUNTERPARTY` and `DESCRIPTION`); no invalid money-account references.
- Production candidate aggregation — OK; valid empty result for the checked company.
- `make site-test-unit` — OK, 1500 tests / 8819 assertions.
- Focused functional/integration regression — OK, 5 tests / 48 assertions.
- Symfony container lint — OK.
- Doctrine mapping validation with `--skip-sync` — OK.
- Twig lint for affected templates — OK.
- Targeted PHP CS Fixer dry run — OK, 0 fixable files.
- Frontend production build — OK; existing non-blocking UX Turbo package warning remains.
- Complete Stage 6 `git diff --check` — OK.

#### Risks / reviewer focus

- Candidate output depends on field-level manual audit history; zero candidates is a valid result.
- Existing rules are unchanged until a user explicitly edits or creates a rule using a new exact condition.
- Historical recalculation remains explicitly out of scope.

#### Open questions

- none
