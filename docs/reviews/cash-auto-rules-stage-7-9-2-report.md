### Stage 7.9.2: Existing-rule system CFO configuration command — DONE

**Risk:** HIGH
**Next action:** STOP, owner review required

#### What was done

- Re-ran the aggregate-only production preflight: 104 active rules, 74 active `PROJECT_GENERAL` targets without ЦФО across two companies, and zero invalid complete targets.
- Recorded the owner-approved exact policy: assign `CFO_GENERAL` only to active rules whose target is `PROJECT_GENERAL` and whose ЦФО is still `NULL`.
- Added a manual transition command that is read-only by default and requires `--execute`, an existing `--actor-user-id`, and the exact dry-run `--expected-count` before writing.
- Reused the Company facade to validate each company's active system pair; no project/ЦФО inference by name, ordering, or first match was added.
- Made execution all-or-none: one missing/mismatched system pair blocks every assignment before Entity mutation.
- Persisted through the existing rule Entity and Doctrine optimistic version so revision and actor metadata advance normally.
- Kept matcher, preview, workers, transactions, history, Messenger, cron, migrations, and production configuration unchanged.

#### Files changed

- `site/src/Cash/Command/AssignGeneralCfoToCashAutoRulesCommand.php` — guarded dry-run/execute CLI entrypoint.
- `site/src/Cash/Application/Service/CashTransactionAutoRuleGeneralCfoAssigner.php` — validation and atomic assignment service.
- `site/src/Cash/Repository/Transaction/CashTransactionAutoRuleRepository.php` — focused candidate query with company/project eager loading.
- `site/tests/Integration/Cash/Command/AssignGeneralCfoToCashAutoRulesCommandTest.php` — dry-run, actor, scope, revision, idempotency, and atomic-failure coverage.
- `ARCHITECTURE.md` — operational command contract.
- `docs/reviews/cash-auto-rules-stage-7-9-plan.md` — current stage/gate status.
- `docs/reviews/cash-auto-rules-stage-7-plan.md` — overall Stage 7 status.
- `docs/reviews/cash-auto-rules-stage-7-9-2-report.md` — this Stage Report.

#### Self-review

- [x] Exact owner-approved `PROJECT_GENERAL → CFO_GENERAL` scope only
- [x] Existing repository, Facade, Entity revision, and command patterns followed
- [x] No migration, endpoint, dependency, queue, cron, history, or transaction mutation
- [x] Company system-pair validation and all-or-none failure checked
- [x] Tests/checks run
- [x] Architecture and stage documentation updated

#### Checks

- Focused command integration test — passed: 3 tests, 23 assertions.
- Cash integration and functional suites — passed: 76 tests, 432 assertions.
- Full unit suite — passed: 1506 tests, 8853 assertions.
- Local command dry-run — passed: zero candidates and zero updates in local `app_test`.
- Targeted PHP CS Fixer, PHP syntax, Symfony container lint, and `git diff --check` — passed.

#### Risks / reviewer focus

- Confirm the repository selector cannot include custom projects, disabled rules, or already configured rules.
- Confirm a missing system pair produces no partial Entity changes and returns failure.
- Confirm candidate-count drift after dry-run blocks execution before Entity mutation.
- `--actor-user-id` must identify the approving production user; existence is checked, while production authorization remains enforced operationally by the restricted wrapper and immediate owner approval.
- The command is intentionally idempotent: after successful execution the candidate count is zero.
- No production wrapper permission was added and neither dry-run nor execute was run in production from this code branch.
- The user-owned untracked `reports/` directory remains untouched.

#### Open questions

- none for implementation; production dry-run, command whitelisting, and `--execute` each retain their required gates.
