### Stage 7.9.3: Shared project × ЦФО planner, preview, and apply — DONE

**Risk:** HIGH
**Next action:** DONE; move to Stage 7.9.4 transition / Stage 7.6.2 planning

#### What was done

- Added `responsibilityCenterId` to the existing per-field winner loop without changing priority, specificity, immutable-ID ordering, or same-target conflict semantics.
- Coupled project and ЦФО application into one fail-closed safe-fill plan: empty/system pairs may be replaced atomically, compatible partial pairs may fill one missing side, and assigned custom pairs remain untouched.
- Added stable `PAIR_CONFLICT`, `PAIR_INCOMPLETE`, and `PAIR_UNAVAILABLE` plan issues; a blocked pair does not block independent category/counterparty changes.
- Added one company-scoped active-pair snapshot per preview or single apply operation, including active-CFO and company-isolation filters with joined labels.
- Added ЦФО field counts, current/result labels, resulting-ЦФО breakdown, pair-issue labels, and the same plan to preview and the manual modal.
- Reused the existing Entity mutation, explicit audit, dispatch guard, controller, and worker paths; project and ЦФО provenance records the exact winning rule/revision per changed field.
- Fixed review P2: execution path now returns `null` for no-op pair plans so fallback category changes are not suppressed by an empty auto-rule application plan.
- Fixed review P3: category-only preview now loads active ЦФО labels instead of showing raw UUIDs for already assigned transaction ЦФО values.
- Kept writers/import defaults, history, routes, auth, queues, migrations, documents, P&L, and existing transactions unchanged.
- Production acceptance was closed as preview-only after deployment: rule `Перевод на карту` preview showed 20 July 2026 rows changing only `ЦФО: Не задано → Общий`, zero conflicts, and no category/project/counterparty changes. No historical range or one-row transaction mutation was executed by Codex.

#### Files changed

- `site/src/Cash/Service/Transaction/CashTransactionAutoRuleService.php` — fourth winner and atomic pair planning.
- `site/src/Cash/Application/DTO/CashTransactionAutoRuleApplicationPlan.php` — ЦФО result and pair issue.
- `site/src/Cash/Application/DTO/CashTransactionAutoRulePreviewResult.php` — ЦФО breakdown and labels.
- `site/src/Cash/Enum/Transaction/CashTransactionAutoRulePairIssue.php` — stable issue codes and labels.
- `site/src/Company/Facade/FinancialResponsibilityCenterFacade.php` and pair repository/DTO — active company-pair snapshot.
- Cash auto-rule controller/Twig templates — modal and preview output.
- Focused unit, integration, functional, repository, audit, and handler tests.
- `ARCHITECTURE.md` and Stage 7 plans — contract/status.

#### Self-review

- [x] Scope limited to Stage 7.9.3; no Stage 7.9.4 writer/import defaults
- [x] Existing matcher, application plan, audit, and Company facade patterns reused
- [x] No migration, endpoint, dependency, queue, cron, history, or transaction backfill
- [x] Company isolation, active pair validation, atomicity, and no preview mutation checked
- [x] Tests/checks run
- [x] Architecture and stage documentation updated

#### Checks

- Focused planner unit tests — passed: 34 tests, 140 assertions.
- Focused Company repository, Cash handler integration, and Cash preview/modal functional tests — passed: 9 tests, 75 assertions.
- Focused Cash handler integration and Cash preview/modal functional tests — passed: 8 tests, 63 assertions.
- Full unit suite — passed: 1514 tests, 8898 assertions.
- PHP CS Fixer dry-run for touched PHP files — passed: 0 files fixable.
- Twig lint for `templates/cash_transaction_auto_rule` — passed: 7 files.
- Symfony container lint for `test` — passed.
- Doctrine mapping validate with `--skip-sync` for `test` — passed.
- `git diff --check` — passed.

#### Risks / reviewer focus

- Confirm that any project/ЦФО conflict blocks both pair fields while category/counterparty changes continue.
- Confirm exact system-pair replacement, custom-pair preservation, partial fill, and unavailable-pair fail-closed behavior.
- Confirm preview loads one active-pair snapshot rather than querying per transaction.
- Stage 7.9.2 production gate is complete: command dry-run found 74 assignable rules, execute updated 74, follow-up dry-run found 0 candidates, and read-only SQL found 0 active `PROJECT_GENERAL` rules without ЦФО.
- Stage 7.9.4 remains a transition back to Stage 7.6 writer/import work; writer defaults are still not included in this stage report.
- The user-owned untracked `reports/` directory remains untouched.

#### Open questions

- none for Stage 7.9.3.
