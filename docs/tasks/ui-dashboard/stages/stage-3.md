### Stage 3: React-виджет legacy `/finance` — DONE

**Risk:** MEDIUM
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** Release Gate — owner decision on Draft PR #2361

#### Stage scope

- Stage base commit: `3de320c0ae74583a61b225185bdfdf1ce6e74b69`
- Work items completed: `3.1`, `3.2`, `3.3`, `3.4`, `3.5`

#### What was done

- Под четырьмя KPI legacy `/finance` добавлен отдельный React mount; app-mode и
  `/dashboard` не изменены.
- Добавлен Vite entry `finance_balance_dynamics`, использующий существующий
  abortable HTTP hook и отдельный ErrorBoundary.
- Виджет запрашивает 30/60/90 дней (default 30), показывает точный диапазон,
  сводный остаток, минимальный порог и дни ниже порога.
- Операционная, финансовая и инвестиционная flow-линии включаются независимо и
  по умолчанию скрыты.
- Native SVG поддерживает responsive geometry, hover/touch, клавиши влево/вправо
  и live-region; добавлены loading/error/retry/empty states.
- Переиспользуемый scoped CSS использует только существующие design tokens; chart
  dependency не добавлялась.

#### Files changed

- `site/assets/react/_legacy/finance-balance-dynamics*` — entry, typed widget,
  presentational chart и API types.
- `site/assets/styles/components/financial-chart.css` — reusable chart styles.
- `site/templates/home/index.html.twig` и `site/vite.config.js` — legacy mount и
  isolated entry.
- `site/tests/Functional/Finance/Home*Test.php` — legacy-only и currency mount
  coverage.
- `ARCHITECTURE.md` и task docs — UI contract, deviation и follow-ups.

#### Definition of Done

- [x] Виджет расположен только в legacy `/finance` под четырьмя KPI.
- [x] Периоды 30/60/90 дней, default 30, работают через Stage 2 DTO/API contract.
- [x] Balance, threshold, breaches и optional activity flows отображаются без
  клиентского пересчёта финансовых значений.
- [x] Loading/error/retry/empty, responsive и keyboard-accessible states
  реализованы.
- [x] Новых frontend dependencies нет; CSS scoped и переиспользуемый.

#### Baseline

- До Stage 3 `npm run build` — green после восстановления ownership локальных
  ignored `node_modules`/`public/build`; сохранено существующее предупреждение
  Symfony UX Turbo.
- Stage 2 endpoint/provider and tests were green at Stage base.

#### Checks

- `npm run lint` — green.
- Targeted strict `npx tsc --noEmit` for the new entry/components — green.
- `npm run build` — green; final entry: about 9.8 kB JS / 6.2 kB CSS before gzip;
  existing Symfony UX Turbo warning unchanged.
- Legacy Home functional set — 9 tests, 1183 assertions, green.
- Twig lint, changed PHP CS Fixer, generated entrypoint manifest and
  `git diff --check` — green.
- Repository-wide `tsc` and UI Kit audits remain red on documented pre-existing
  legacy debt; no task-file error was reported. No browser runtime was available
  for screenshot smoke, so responsive/interaction paths were verified by build,
  code review and two independent review layers.

#### Internal automatic review

- Iterations: 5
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: responsive SVG/pointer coordinate mismatch, stale header data,
  mobile scaling, exact decimal formatting, keyboard live-region behavior,
  duplicated raw color fallbacks, narrow-domain axis labels and state layout
  jump.
- FOLLOW-UP: secondary flow scale and hidden browse-mode data table during module
  migration.

#### External Claude Code review

- Completed review outputs: 5; two additional 40-turn attempts were recovered
  automatically with the required narrow 80-turn retry.
- Final result: `REVIEW_GREEN`.
- Confirmed IMPORTANT fixed: documented the owner-approved `_legacy/` quarantine
  deviation and mandatory migration follow-up.
- Confirmed safe MINOR fixed: `html_attr` escaping, responsive scale, money
  fractions, dead effect, accessible live-region, checkpoint structure, token
  centralization, project Props convention, axis precision and matched state
  heights.
- Rejected MINOR with reason: `0 RUB` remains a visible threshold because the
  approved Company contract defines it as a real value, not an unset sentinel;
  malformed first-party decimal data intentionally fails into ErrorBoundary
  instead of being silently coerced.

#### Risks / reviewer focus

- Stage 3 deliberately grows `_legacy/` under the approved legacy-UI requirement.
  Follow-up must move it to `assets/react/modules/finance-dashboard/` plus a thin
  entrypoint when corresponding UI Kit pieces exist, then delete these legacy
  files.
- Flow lines currently share the balance Y-axis; a secondary axis/flow strip is a
  future visual enhancement, not a financial formula change.
- Shared legacy HTTP 401 redirect behavior and frontend test-runner introduction
  remain cross-cutting follow-ups.

#### Checkpoint

- `docs/tasks/ui-dashboard/checkpoint.md` updated to the final Release Gate.
- Exact next action: commit/push Stage 3, update Draft PR #2361, report owner gate.

#### Open questions

- none

#### Expected owner response

- Review Draft PR #2361 and explicitly approve its transition from Draft to Ready,
  or request changes. Merge and automatic production deploy require a later,
  separate explicit approval naming both actions.
