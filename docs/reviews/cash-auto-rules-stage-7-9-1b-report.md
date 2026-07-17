### Stage 7.9.1b: Scalar Cash auto-rule CFO target and authoring — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done

- Mapped the existing nullable rule `responsibility_center_id` column as a scalar UUID.
- Added active company-scoped ЦФО choices to rule create/edit forms and preserved the current archived choice on edit.
- Added fail-closed validation for malformed, archived, cross-company, and disallowed project/ЦФО targets.
- Preserved unchanged legacy project-only and archived targets so unrelated rule edits remain possible before the configuration gate.
- Added the ЦФО target to rule form/list display without changing matcher, preview, worker, or transaction behavior.
- Fixed the existing edit change-set sequence so form changes and optimistic revision metadata are persisted together.

#### Files changed

- `site/src/Cash/Entity/Transaction/CashTransactionAutoRule.php` — scalar ЦФО target mapping and accessors.
- `site/src/Cash/Application/Service/CashTransactionAutoRuleTargetValidator.php` — company/active/pair authoring validation.
- `site/src/Cash/Form/Transaction/CashTransactionAutoRuleType.php` — scalar ЦФО choice field.
- `site/src/Cash/Controller/Transaction/CashTransactionAutoRuleController.php` — company-scoped choices, validation, display labels, and complete change-set recomputation.
- `site/templates/cash_transaction_auto_rule/_form.html.twig` — ЦФО form control.
- `site/templates/cash_transaction_auto_rule/index.html.twig` — ЦФО list column.
- `site/tests/Integration/Cash/Application/Service/CashTransactionAutoRuleTargetValidatorTest.php` — validation and compatibility coverage.
- `site/tests/Functional/Cash/Controller/CashTransactionAutoRuleEditControllerTest.php` — company isolation, pair rejection, persistence, revision, and list-display coverage.
- `docs/reviews/cash-auto-rules-stage-7-9-plan.md` — stage status.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — overall Stage 7 status.
- `docs/reviews/cash-auto-rules-stage-7-9-1a-report.md` — production acceptance closure.
- `docs/reviews/cash-auto-rules-stage-7-9-1b-report.md` — this Stage Report.

#### Self-review

- [x] Scope compliance; no matcher, preview, worker, or transaction changes
- [x] Existing Entity, form, controller, Facade, and optimistic-lock patterns followed
- [x] No migration, production mutation, history processing, or public endpoint change
- [x] Company isolation and forged/archived/disallowed values checked
- [x] Tests/checks run
- [x] Stage documentation updated

#### Checks

- Focused controller/validator/unit tests — passed: 10 tests, 71 assertions.
- Cash integration and functional suites — passed: 73 tests, 409 assertions.
- Full unit suite — passed: 1506 tests, 8853 assertions.
- Targeted PHP CS Fixer for all six changed PHP files — passed.
- PHP syntax, Twig lint (7 templates), Symfony container lint, Doctrine mapping validation, and `git diff --check` — passed.
- Full Doctrine schema sync — baseline remains red; `--dump-sql` confirmed the mapped rule column exists and the reported Stage 7.9 drift is only the intentionally database-owned FK/index from Stage 7.9.1a.
- Global PHP CS Fixer — baseline remains red in 607 unrelated files; no Stage 7.9.1b file is reported by the targeted check.

#### Risks / reviewer focus

- Review unchanged-target compatibility: existing project-only and archived tuples bypass new-pair validation only when the tuple itself is unchanged.
- Review the Doctrine UnitOfWork recomputation after `recordUpdate()`; it preserves the form/condition change set while adding revision and actor metadata.
- The matcher still ignores `responsibilityCenterId` by design. No transaction can change from this stage alone.
- Production requires the already accepted Stage 7.9.1a column before deploying this code.
- The user-owned untracked `reports/` directory remains untouched.

#### Open questions

- none for Stage 7.9.1b.
