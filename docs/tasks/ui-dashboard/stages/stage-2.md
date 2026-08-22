### Stage 2: API и финансовые временные ряды — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously

#### Stage scope

- Stage base commit: `db4e36b491b735dd1b6d63f7f903cfd21764bd8b`
- Work items completed: `2.1`, `2.2`, `2.3`, `2.4`, `2.5`

#### What was done

- Добавлен `GET /api/finance/dashboard/balance-dynamics` с request/response DTO рядом с controller.
- Реализованы периоды 30/60/90 дней (default 30) и фильтр fiat currency.
- Одним PostgreSQL query строится closing balance series активных счетов с carry-forward/opening fallback.
- Вторым query строятся signed split-flow series по operating/financing/investing в scope сверки ДДС.
- Порог компании применяется только при совпадении валюты и только в дни, когда существует счёт.

#### Files changed

- `site/src/Finance/Controller/Api/BalanceDynamics/*` — DTO и invokable controller.
- `site/src/Finance/Application/Service/FinanceBalanceDynamicsProvider.php` — alignment и threshold semantics.
- `site/src/Finance/Infrastructure/Query/BalanceDynamicsQuery.php` — tenant-scoped PostgreSQL reads.
- `site/tests/{Unit,Integration,Functional}/Finance/*BalanceDynamics*` — contract, formulas, tenant и HTTP coverage.
- `ARCHITECTURE.md` и task docs — API/financial rules and checkpoint.

#### Definition of Done

- [x] Endpoint принимает только 30/60/90 и supported fiat currency.
- [x] Response содержит inclusive period, threshold и aligned decimal-string points.
- [x] Balance использует active selected-currency accounts без N+1.
- [x] Activity flows совпадают с ДДС по split signs и exclusions.
- [x] Tenant/currency/transfer/deleted/technical/unallocated scopes покрыты тестами.

#### Baseline

- Existing `FinanceDashboardKpiProviderTest` — green; Stage не меняет legacy KPI provider.
- Stage 1 branch base and tests — green before Stage 2 implementation.

#### Checks

- targeted Stage 2 plus existing KPI reconciliation: 15 tests, 131 assertions — green; 1 pre-existing deprecation.
- full relevant Finance/ДДС/module-access: 123 tests, 1577 assertions — green; 1 pre-existing deprecation.
- real PostgreSQL schema evidence: both `cash_transaction.occurred_at` and `money_account.opening_balance_date` are `date NOT NULL`.
- PHP CS Fixer, container lint, route discovery and `git diff --check` — green.

#### Internal automatic review

- Iterations: 3
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: aggregate overflow conversion, per-day pre-opening threshold guard, defensive flow accumulation, explicit date series, inactive-account contract coverage.
- FOLLOW-UP: observe LATERAL query latency for unusually large account counts.

#### External Claude Code review

- Iterations: 3 completed reviews; one initial repository-crawl timeout recovered with supplied-patch review.
- Result: REVIEW_GREEN
- Confirmed findings fixed: false `below_minimum` before first account opening; inactive-account flow contract coverage/documentation.
- Rejected findings with reason: `occurred_at` is `date NOT NULL`, not timestamp; `opening_balance_date` is `date NOT NULL`, not nullable (ORM and `information_schema`).

#### Review fixes applied

- Threshold breach now requires a positive daily `account_count`.
- Flow values accumulate defensively by date/kind.
- Period generation narrows values explicitly to PostgreSQL `date`.
- Provider/controller period contracts aligned; response uses named DTO arguments.
- Empty/no-active-account and inactive-account historical-flow behavior documented and tested.

#### Risks / reviewer focus

- LATERAL uses the existing `(money_account_id, date)` index; maximum period is 90 days.
- Flow scope intentionally matches selected-activity ДДС reconciliation.
- No company id accepted from the client; every joined table is company-scoped.

#### Checkpoint

- `docs/tasks/ui-dashboard/checkpoint.md` updated.
- exact next action: commit/push Stage 2, update Draft PR #2361, start Stage 3 React widget.

#### Open questions

- none

#### Expected owner response

- not required; continuing autonomously
