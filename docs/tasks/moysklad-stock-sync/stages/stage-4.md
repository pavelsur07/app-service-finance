### Stage 4: Legacy Twig status UI — DONE

**Risk:** MEDIUM
**Stage base commit:** `b47e6875705803bc6e12866c65dc51b14469040e`
**Work items:** 4.1, 4.2, 4.3

#### What was done

- В карточку подключения добавлены отдельные секции «Склады» и «Остатки»:
  empty/running/succeeded/failed, времена, counters, cursor и история.
- Остатки показывают ID, начало/завершение и число строк последнего completed
  snapshot, включая `0 строк`; failed run не скрывает snapshot.
- Для active+connected подключения с write-доступом добавлена форма запуска;
  при running она остаётся доступной для recovery. Inactive/unverified/read-only
  видят статус без mutation-формы.

#### Files changed

- `site/templates/moy_sklad/connections/index.html.twig` — Legacy Twig UI.
- `site/tests/Functional/MoySklad/ConnectionsControllerTest.php` — UI states,
  history limit, snapshot fallback и form visibility.
- task plan/checkpoint — Stage 4 base и evidence.

#### Definition of Done

- [x] Empty/running/succeeded/failed и безопасные русские labels отображаются.
- [x] Store/stock counters, timestamps, cursor и история до пяти запусков видны.
- [x] Completed snapshot сохраняется при failed/building и показывает `0 строк`.
- [x] Running допускает повтор; inactive/unverified/read-only не видят форму.
- [x] Только существующие Twig/Tabler классы; React/Vite/UI Kit не изменены.

#### Checks

- RED UI: 3 expected failures на отсутствующих store/stock секциях; GREEN UI:
  4 tests, 42 assertions.
- Stage functional after review fixes: 32 tests, 232 assertions, PASS.
- focused Twig CS: no violations; focused PHP CS Fixer: 0 fixable; focused
  PHPStan: no errors.
- `npm run lint` — PASS; `npm run build` — PASS (122 modules).
- `npm run check:ui-kit` — pre-existing global baseline red: 8996 findings;
  diff adds no new CSS classes.
- `npm run check:uikit-react-mapping` — pre-existing global baseline red:
  47 missing mappings; task changes neither UI Kit nor React.
- Rendered DOM smoke covers statuses/forms and preserves responsive
  `col-12 col-xl-6` cards. The running action now uses Tabler's `mw-100` and
  `text-wrap`; the loaded Tabler 1.2 stylesheet was checked to resolve these to
  `max-width: 100%` and `white-space: normal`. A real browser viewport is
  unavailable in this environment; no 320/375 px browser screenshot is claimed.

#### Internal review

- iterations: 1; BLOCKER: 0; IMPORTANT: 1 fixed; MINOR: 1 fixed;
  FOLLOW-UP: none.
- Fresh review found the running button's non-wrapping mobile overflow and
  missing ordering regressions. The form/button are now width-constrained and
  wrapping; tests distinguish two completed snapshots by row count and assert
  newest-five history order/exclusion.

#### External review

- round 1: `REVIEW_GREEN`; BLOCKER: 0; IMPORTANT: 0.

#### Risks / reviewer focus

- Global Twig/UI Kit baselines remain red outside this task; changed Twig file
  is point-wise clean and uses existing Tabler utility classes only.

#### Next

- commit review fixes and complete PR handoff.
