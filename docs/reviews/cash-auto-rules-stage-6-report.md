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

- Stage 6.3 remains blocked on the rule-candidate report business contract defined in the Stage 6 plan.
