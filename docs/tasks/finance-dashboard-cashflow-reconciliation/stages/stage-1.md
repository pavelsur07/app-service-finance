## Stage 1: Единый серверный контур сверки KPI и ДДС — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously

### Scope Stage

- Stage base commit: `3fc4e02f427e042d801186fc3bbcaa63f315359f`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`.
- Definition of Done выполнена: точные периоды, authenticated-only opt-in scope, идентичный KPI фильтр, exact decimal summary, default/public compatibility.

### Что сделано

- KPI provider возвращает фактические текущий/предыдущий 30-дневные интервалы и дату сравнения остатка.
- Защищённые HTML/JSON маршруты ДДС принимают `reconcile=dashboard`, валидные `activity` и `currency`; публичные JSON/CSV игнорируют эти параметры.
- Строки отчёта в режиме сверки используют те же split-based правила, что KPI: company/date/currency, no transfer/deleted/technical, activity и unallocated semantics.
- Exact `inflow/outflow/net` строятся существующим KPI-агрегатором и bcmath-строками.
- Обычный отчёт и публичный контракт сохранены; report balances остаются company-wide и документированы отдельно от movement scope.
- Отфильтрованное дерево безопасно re-root-ит legacy orphan nodes.

### Затронутые файлы

- `site/src/Finance/Application/Service/FinanceDashboardKpiProvider.php`
- `site/src/Finance/Controller/ReportCashflowController.php`
- `site/src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php`
- `site/src/Report/Cashflow/CashflowReportBuilder.php`
- `site/src/Report/Cashflow/CashflowReportParams.php`
- `site/src/Report/Cashflow/CashflowReportRequestMapper.php`
- релевантные unit/integration/functional tests.
- `ARCHITECTURE.md` и task-документация.

### Self-review

- [x] Scope compliance; no UI, schema, dependency or production expansion.
- [x] KPI/report predicates compared condition-by-condition.
- [x] Tenant/auth isolation and public endpoint opt-in boundary checked.
- [x] Exact summary avoids float arithmetic.
- [x] Default/public compatibility covered by negative assertions.
- [x] No secrets, debug code, migration, N+1 regression or unrelated files.

### External review

- Reviewer: Claude Code 2.1.238.
- Iterations: 4 (first 40-turn attempt exhausted; second found one IMPORTANT; third returned green; fourth revalidated safe MINOR fixes).
- Result: `REVIEW_GREEN`.
- Confirmed findings fixed: row-derived category/matrix totals now prove `applyDashboardScope()`; default auth export negative assertions; orphan-safe tree; architecture balance semantics; checkpoint format.
- Rejected findings with reason:
  - enum/value-object extraction — speculative complexity for a validated internal string;
  - active-only report balances — would change approved existing company-wide report semantics.
- Remaining MINOR/FOLLOW-UP:
  - Stage 2 functional HTML coverage and balance explanation;
  - invalid opt-in UX handling remains conservative fallback to default report;
  - shared activity mapping only if more consumers appear;
  - pre-existing float report rollups/per-account balance queries require separate scope.
- Ограничения ревьюера: read-only tools, no test execution or external access; repository diff and recorded red/green evidence inspected.

### Команды для проверки

- Relevant PHPUnit suite: PASS, 38 tests / 841 assertions; 2 pre-existing deprecations.
- Regression mutation: without `applyDashboardScope()` focused test FAIL (`1160.0` vs `160.0`); restored code PASS, 1 test / 22 assertions.
- PHP syntax for changed files: PASS in project CLI image.
- PHP CS Fixer changed-file dry run: PASS.
- `git diff --check`: PASS.

### Риски / на что обратить внимание ревьюеру

- Category rows and exact summary share predicates but deliberately use separate queries; integration tests pin both.
- Report opening/closing balances are not scoped like KPI movement rows and are explained in Stage 2.

### Открытые вопросы

- нет.
