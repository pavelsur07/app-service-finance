## Current checkpoint

**Phase:** Final Release Gate
**Status:** done
**Stage base commit:** `3de320c0ae74583a61b225185bdfdf1ce6e74b69`
**Current Work item:** 3.5
**Owner gate:** yes

### Completed

- Stage 1 committed as `db4e36b4`; Draft PR #2361 targets `master`.
- Добавлен `Company.minimumBalance` как неотрицательный shared `Money` с default `0 RUB`.
- Добавлены миграция, редактирование в форме компании и пользовательские ошибки валидации.
- Миграция проверена циклом up/down/up; mapping column names закреплены integration-тестом.
- Основной dirty worktree и пользовательские файлы не затронуты.
- Stage 2 request/response DTO, invokable endpoint, DBAL series query and provider implemented.
- Balance carry-forward/opening fallback, split directions and dashboard ДДС exclusions covered.
- Stage 3 Vite entry and legacy-only Twig mount added under the four KPI cards.
- Typed React widget implements 30/60/90 periods, flow toggles, native responsive
  SVG, minimum/breach markers, tooltip and loading/error/retry/empty states.
- Reusable scoped chart CSS added without a frontend dependency.

### Current diff / affected files

- `site/assets/react/_legacy/finance-balance-dynamics*`, reusable chart CSS,
  legacy home Twig mount and Vite config.
- Legacy/app isolation and selected currency assertions in Home functional tests.
- Architecture/task docs record the UI entry and approved `_legacy/` deviation.

### Checks and baseline

- Baseline `CompanyEntityTest`: 8 tests, 12 assertions — green.
- Targeted Stage 1 suite after review fixes: 183 tests, 611 assertions — green; 1 pre-existing deprecation.
- Twig lint and Doctrine mapping validation — green.
- Migration up/down/up — green; task columns absent from schema update diff.
- PHP CS Fixer for changed PHP files and `git diff --check` — green after review fixes.
- Stage 2 targeted plus existing dashboard reconciliation: 14 tests, 127 assertions — green.
- Stage 2 PHP CS Fixer, route discovery and container lint — green.
- Full relevant Finance/ДДС/module-access set: 122 tests, 1573 assertions — green; 1 pre-existing deprecation.
- Stage 3 `npm run lint`, targeted strict TypeScript compile and `npm run build` — green;
  build retains the pre-existing Symfony UX Turbo warning.
- Stage 3 legacy Home functional set: 9 tests, 1183 assertions — green.
- Twig lint, changed PHP CS Fixer, entrypoint manifest and `git diff --check` — green.
- Repository-wide `tsc`, UI Kit class/mapping audits remain red only on recorded
  pre-existing legacy debt; the task-targeted checks are green.

### Review status

- iteration: 3 for Stage 2
- Stage 1 external review: `REVIEW_GREEN`.
- internal Stage 2 review: aggregate overflow conversion and pre-opening false threshold signal removed.
- external Stage 2 review iteration 1: no green marker; one confirmed IMPORTANT fixed.
- external Stage 2 review iteration 2: `REVIEW_GREEN`; safe inactive-account coverage/documentation MINOR fixed afterwards.
- external Stage 2 review iteration 3: `REVIEW_GREEN` on the final Stage diff.
- rejected conditional findings: both source columns are PostgreSQL `date NOT NULL`, verified through `information_schema`.
- Stage 3 internal review iteration 1 fixed responsive SVG/pointer coordinate mismatch.
- Stage 3 external review iteration 1: one IMPORTANT documentation conflict and
  safe MINOR findings confirmed and fixed.
- Stage 3 external review iteration 2: `REVIEW_GREEN`; all remaining safe,
  in-scope MINOR findings were fixed afterwards, so a final review is required.
- Stage 3 external review iteration 3: `REVIEW_GREEN`; three final local MINOR
  findings (axis labels, responsive coupling note, state height) were fixed and
  rechecked afterwards.
- Stage 3 external review iterations 4 and 5: `REVIEW_GREEN`; final checkpoint
  structure and cent-level axis labels were fixed before the last green review.
- Rejected Stage 3 MINOR: zero threshold remains visible because `0 RUB` is the
  approved explicit Company default, not an «unset» sentinel.
- Deferred Stage 3 shared/out-of-scope findings: secondary flow scale, frontend
  test-runner introduction and shared legacy HTTP 401 handling.
- unresolved findings: none.

### Exact next action

- Commit/push Stage 3, update Draft PR #2361, then request the declared owner
  decision: approve transition from Draft to Ready or request changes.

### Stage 3 baseline

- Initial host build was blocked by root-owned generated `node_modules` and `public/build` artifacts.
- Ownership of those generated, ignored paths was corrected; `npm install --no-package-lock` changed no lock file.
- `npm run build`: green; the existing Symfony UX Turbo package warning remains.

### Files to inspect first on resume

- `site/assets/react/_legacy/finance-balance-dynamics/BalanceDynamicsWidget.tsx`
- `site/assets/react/_legacy/finance-balance-dynamics/BalanceDynamicsChart.tsx`
- `site/assets/styles/components/financial-chart.css`
- `site/templates/home/index.html.twig`
