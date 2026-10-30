## Current checkpoint
Phase: Stage 3 / final verification
Status: checking
Stage 3 base commit: 8f44048dd
Completed: backend + UI; 53 module tests/240 assertions; 23 functional/170 assertions;
Twig lint; modal JS smoke conflict/normal/error; frontend lint/build/API types green.
Build initially failed because read-only node_modules blocked Vite temporary config;
fixed isolated writable mount for .vite-temp, build passed in 8.13s.
Prepared guarded repair.sql/rollback.sql; local synthetic SQL verifies repair(2), repeat(0),
rollback(2), precondition rejection leaves legacy unchanged. Not executed on prod.
Running: full PHPStan/CS/strict/unit; full make site-test (existing installed dependencies reused,
site-composer-install skipped to preserve owner's shared vendor).
Internal final review + follow-up: REVIEW_GREEN (0 B/I). External round 1 REVIEW_GREEN (0 B/I,2 MINOR fixed); filter regression red then fixed.
Exact next action: inspect full gate output, fix real regressions; external review after internal green.
Production: stale active September revenue document REV_SPP_SALES=2632144.00;
1031/1061 sales,82/86 returns posted;0/1064 realizations posted. Historical repair not authorized.
PR #2827 Draft master; branch fix/ozon-pl-spp-conflict; main owner worktree untouched.

CI note: first push static analysis flagged assertCount(2) after an earlier same-query assertSame([]);
PHPStan memoizes pure-method calls across em->flush. Test now creates a fresh query after DB mutation;
production behavior unchanged. CI integration/functional/unit/style/API checks passed.
