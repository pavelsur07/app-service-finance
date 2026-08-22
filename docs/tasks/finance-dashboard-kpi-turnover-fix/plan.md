## Stage 1: Correct gross turnover KPIs on the Finance dashboard

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: f6ba0e628407e30cdeda33357312af4ebcf2d5a5

Definition of Done:
- `Inflow (30 days)` and `Outflow (30 days)` aggregate gross split amounts by transaction direction before cashflow report netting.
- `Net flow (30 days)` equals gross inflow minus gross outflow and reconciles with the signed cashflow total under identical filters.
- Transfers and technical categories are excluded; unallocated splits are included only for the `all` activity.
- Current/previous rolling 30-day periods, comparison formatting, balance calculation, controller payloads, and Twig UI remain compatible.
- Relevant regression tests, container lint, internal review, and external review are green.
- No migration, dependency, production access, or report UI change is introduced.

Work items:
- 1.1 — Record instructions, task branch, baseline, plan, and checkpoint.
- 1.2 — Add direct split-based turnover aggregation and switch `FinanceDashboardKpiProvider` to it.
- 1.3 — Add regression, filtering, boundary, and cashflow reconciliation coverage.
- 1.4 — Run Stage checks, internal review, external `REVIEW_GREEN`, and prepare delivery artifacts.

Stage checks:
- `docker compose run --rm -T site-php-cli php bin/phpunit tests/Integration/Finance/Application/Service/FinanceDashboardKpiProviderTest.php tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php tests/Unit/Analytics`
- `docker compose run --rm -T site-php-cli php bin/console lint:container`
- PHP syntax checks for changed PHP files.

Reviewer focus:
- Gross direction-based money aggregation without float arithmetic.
- Company, currency, date, soft-delete, transfer, technical, activity, and unallocated filters.
- Reconciliation invariant and unchanged output/comparison contracts.

Release Gate:
- Commit and non-force push the task branch and create one Draft PR with base `master`.
- Keep the PR Draft; merge and the automatic production deploy require a later explicit owner approval.
