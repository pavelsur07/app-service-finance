# Current checkpoint

**Phase:** handoff — implementation complete, owner release decision pending
**Status:** done (implementation); production unchanged
**Task base:** `4fdad15c974830d13ff9342d1f40a526f3b5bd33`
**Stage5 base:** `89ec6ea49`
**Merged master:** `407ec649c` via feature merge `a3e9f02a5`
**Branch:** `feat/counterparty-settlements`
**Worktree:** `/home/deploy/.codex/worktrees/3821/app-service-finance`
**PR:** https://github.com/pavelsur07/app-service-finance/pull/2901, base master

## Completed

Stage1 core66e666657; Stage2 sources81ba2ffa5; Stage3 allocation/reports/rebuild
a3b1ba860; Stage4 UI89ec6ea49; Stage5 acceptance/source/access fixesdbc31b643,
master synca3e9f02a5 and closing test/docs commit. All task docs in this directory.
Source inventory, EXPLAIN and desktop/mobile smoke preserved as evidence.
Original owner checkout/unrelated artifacts untouched. No new dependencies/assets.

P01–P24 PASS. Final local gates on merged tree:
- PHPStan3860files: no errors.
- PHP CS/style and strict types3896files: no fixes.
- Unit4547tests/25384assertions PASS.
- Full8485tests/47654assertions PASS, exit0,6m57.816s.
- Fresh final307 migrations and schema1/1 PASS; existing306→307 (36SQL)
  preserved source snapshots1Cash40000.00+1Finance/operation100000.00;
  all6newtables empty, no automatic history inclusion.

Make command used -o site-composer-install and DOCKER_COMPOSE=/tmp/vf-settlement-compose,
same CLI recipes in worktree-mounted vf-settlements-php. Vendor RO from checkout
with identical composer.lock SHA256, all cache/DB/migration/test prerequisites run.
No production config/data. Logs /tmp/vf-settlement-merged-final-gates.log,
-merged-final-tests.log, -existing-final-migration.log and -final-schema-correct.log.

## Reviews / failure history

Fresh whole-task internal review and bounded fix/merge rechecks clean. Primary-base
corruption8RED→green; finance deleteactualHTML500RED→green; source reader403RED→green.
First full8400:1E/4F, four causes fixed (mixedrouteREAD/POSTWRITE, stable int type,
transfer preflight and legacyDTO31representation). Split identity assertion ignores
unordered reload order, retains exactIDs/count. Unitmock count loosened to permit
preflight+lockedcheck; onebatch/recalc assertions retained. Obsolete3Stanignores
removed; no baseline growth. One CS comma fixed. Wrong early schema-test path and
wrong initial /finance/documents URL corrected before valid proof; not hidden.

Review metrics: internal BLOCKER0/IMPORTANT19fixed; external BLOCKER0/IMPORTANT4fixed,
5rounds total. Stage1/2/5 fixed without re-run per policy; Stage3/4 REVIEW_GREEN with
safe MINOR fixes. No open BLOCKER/IMPORTANT. Batch guard query optimization in rare
company maintenance and enhanced app DS picker adapter recorded as FOLLOW-UP.

## Exact next action

Finish closing docs commit/push/PR body/Ready and inspect GitHub CI. No additional
local tests without new changes or failures. Ask once: `run the migration and deploy #2901`.
No merge, production migration or deploy approval received. After explicit approval,
follow docs/workflow/release.md pipeline completely; production only through allowed
wrappers. MigrationVersion20261106183000 adds6tables; down aborts to preserve data.
Rollback code only, retain financial history. Never merge old docs-only PR2899.

Finance P&L itself never creates debt automatically; fullFinanceMoney independent.
Cash is actual-money source, manual PAYMENT correction forbidden. Shared Money only,
RUB; six buckets separate, event orderdate+sequence; maintenance primary invariants
and active source checks mandatory. Company→sortedCash→Finance→book→sorteditems;
maintenance exclusivecompany. All future historical intervals validated.
