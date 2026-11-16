## Current checkpoint

**Phase:** Stage 4 / handoff
**Status:** implemented/reviewed; PR3037 final runtime delivery/CI
**Stage base commit:** d99ede00de60e210d373feea8b80e371daa521e4
**Branch:** feat/ds-v3-stage-4
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3037 (Draft, base master verified)

### Completed
- Stages1/2/3 delivered: PR3027/3030/3034; fallback rule PR3028 in master.
- Stage3 merge d99ede00d, deploy43830874625SUCCESS; both apps healthy SHA,
  HTTPS23/23 + public browser native fields/navigationPASS.
- Stage4 spec/reference read from Git3882c5dd9; plan/DoD in plan.md.

### Checks and baseline
- Baseline front-test82/78 and preview PASS;15before1440 snapshots.
- Shell SSR10RED→GREEN10, browser20PASS (12base+8layout), Typecheck/lint/build
  both apps PASS after removing obsolete store binding in RequireSession.
- Preview migrated apps36PASS/10skips +fixture20PASS.
- Responsive focus RED→GREEN; logout refresh RED→GREEN4PASS, nginx final44PASS6skips+fixture21.

### Review status
- Author IMPORTANT2fixed: resize focus и logout during background refresh (RED→GREEN).
- Independent fresh internal0/0/0GREEN +focused updated full diffGREEN.
- External separate Codex1round exact REVIEW_GREEN0/0, stdin-only static;
  post-green scoped logout correction verified bothapps desktop/mobile4PASS.

### Exact next action
- Push full runtime/stage report/handoff to Draft3037, verify latest-head CI.
- After green markReady and ask single "merge and deploy #3037".
- On approval run pipeline to post-deploy readonly acceptance without more questions.
- Stage5 separate PR, not started. No site/production config changes.

### Files to inspect first on resume
- plan.md, apps/*/src/app, apps/packages/ds/src/components
- apps/account/tests/components, apps/README.md
