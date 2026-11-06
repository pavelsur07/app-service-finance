# Current checkpoint

**Phase:** Stage4 / release prerequisite approval
**Status:** release BLOCKER requires named CI/CD approval; final check evidence in PR
**Branch:** feat/cash-import-description-update
**Worktree:** /tmp/vf-cash-import-preparation
**Task base:** cfb6728d2b95f4938c19996ef7d44214c9066648
**Stage1:**0bfe1a259→9f6d94a81; Stage2 base9f6d94a81. Backend/UI work was parallel, commits group integration boundaries.

## Completed

- Whole-file planner/matcher, explicit apply/chunk/snapshot/audit/source protection and Twig UI implemented.
- Stage1 pushed; Stage2 commit d512ea500 / Stage3 commit88d0eb73e pushed; current master integrated107da28b1. Stage4 documents and reviewable release patch prepared; final code tree excludes baseline changes (restored exactly master).
- Full unit4468/full8226/46454 GREEN before last fixes; integrated unit4470/25248 and CS+strict3760 GREEN. CI tests/builds107da28b1 GREEN; baseline removal caused static failure, baseline restored exactly master, clean rerun required.
- Browser desktop/mobile keyboard/empty/error/long text:0JS errors; fixtures removed/server stopped.
- Fresh internal review and Claude Stage2round1/handoffrounds3 completed; last exact REVIEW_GREEN. Final UUID/UTF8 minor fixes and committedfixture rowlock test complete; targeted2/5+1/3 and focused PHPStan GREEN.
- Approved InputRow actual-create guard prevents relative date drift; over1024 text invalid; empty descriptions cannot steal ambiguous candidates.
- Acceptance results/review ledger/release plan prepared. No production actions performed.

## Open release requirement

Confirmed: old worker can consume new plan job between FPM and worker update. Minimal consumer-first patch is stored (not applied) in release-worker-order.patch, git apply --check GREEN. §3.3 requires named approval for CI/CD; until then Draft/Stage4 remain open. Details handoff.md/release.md.

## Exact next action

Final run output and final SHA/check status are recorded in PR body/checks; runtime logs /tmp/vf-cash-frozen-final-* and /tmp/vf-cash-frozen-clean-stan.log. After green CI, wait only for concrete named approval for consumer-first patch +migration+deploy. Do not apply workflow patch or touch production before approval. On resume first compare git status/checkpoint and PR.

## Environment

Local Compose override /tmp/vf-cash-compose.yml mounts this worktree and original vendor, DATABASE_URL points isolated app_test_cash_description. Gates use DOCKER_COMPOSE override. Do not touch owner original worktree feat/ai-observability-budget or original app_test schema. Do not expose credentials/production data. Runtime reports /tmp/vf-cash-*.log and site/var/external-review/.
