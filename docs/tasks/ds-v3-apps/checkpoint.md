## Current checkpoint

**Phase:** Stage 2 / Work item 2.4 / approval gate
**Status:** stopped — named production Docker/CI approval, AGENTS.md §3.4 item2
**Stage base commit:** fbfcc71c88984d560b4cf80e56310031cdd11eff
**Branch:** feat/ds-v3-stage-2
**PR:** [#3030](https://github.com/pavelsur07/app-service-finance/pull/3030) — Draft, base master проверена.
**Implementation commit:** 404c35c8b

### Completed
- Stage1 / PR3027 merged/deployed fbfcc71c8; workflow43804985365 SUCCESS,
  оба SPA healthy на SHA,19/19 HTTPS PASS.
- Отдельные project rules: PR3028 Ready, commit c043a73dc, fallback
  Claude CLI→separate fresh read-only Codex. Пока не merged.
- Stage2 2.1–2.3: tests-first, Tailwind4.3.5/Vite/DS theme/preflight,
  explicit sources/test exclusions, Manrope/OFL, current screen CSS migration.
- 2.4: internal/fix cycle, Codex REVIEW_GREEN, exact production proposal
  (не применён), локальные images/smoke и release contracts проверены.
- 2.4 commit/push выполнены: отдельный Draft PR3030, base master.
- site/, auth/session logic и фактические production configs не менялись.

### Checks and baseline
- Baseline front-test43/39 PASS. New5Vitest+2browser RED→GREEN.
- front-typecheck/front-lint/front-test/front-build PASS:48/44,13DS tests.
- Preview26PASS/8skipped; production-candidate nginx30PASS/4skipped.
- После MINOR fix: contract RED2→GREEN5, builds/typecheck обоих apps PASS,
  assert compiled CSS no .visible PASS; nginx smoke повторён PASS.
- 15 before+15 after screenshots1440; /tmp/vf-ds-v3-stage2-artifacts.
- Candidate release path test RED(old)→GREEN6; deploy workflow+tools6 PASS;
  git apply --check и bash -n PASS. DS check:generated PASS.
- DS audit exit1:4moderate/2low, HIGH/CRITICAL0, как Stage1. Apps existing
  dependency versions unchanged. CSS73766→23189B, font164700B.

### Review status
- Internal fresh reviewer: BLOCKER/IMPORTANT0/0, MINOR1 fixed, open0.
- External separate Codex1round, stdin-only: REVIEW_GREEN0/0, exit0.
  Lock/binary directly not reviewed; author checked provenance/install/build.

### Exact next action
- Stage2 опубликован в Draft PR #3030, commit404c35c8b; merge не выполнять
  до применения reviewed production patch и фактического зелёного CI.
- Получить named approval, затем применить тот же reviewed patch:
  git apply docs/tasks/ds-v3-apps/proposals/stage-2-production.patch
- Проверить реальные CI/release/build, Ready и merge+deploy Stage2.
- До named approval actual Docker/CI не менять. PR3028 ждёт отдельного
  решения merge and deploy #3028. Stage3–5 не начаты.

### Files to inspect first on resume
- docs/tasks/ds-v3-apps/proposals/{README.md,stage-2-production.patch}
- docs/tasks/ds-v3-apps/stages/stage-2.md
- docs/tasks/ds-v3-apps/reviews/codex-stage-2.md
- apps/packages/ds/src/styles.css и styles.test.ts
