# Current checkpoint
Phase: handoff assembled. Status: verified; owner release decision pending.
Branch feat/verified-single-release; PR #3042, base master. Source code241c3c655.

Completed: implementation; 45 workflow-contract tests including real temporary
PostgreSQL GREEN; actionlint/diff-check GREEN; source-code CI241c3c655 GREEN.
Fresh final internal B0/I0/M0. Internal I4 fixed (malformed manifest, enabled
nightly health, registry404 fallback, legacy auth-gate test parser). External
B2/I4 fixed, 3 rounds, round3 REVIEW_GREEN; final stdin MINOR fixed RED→GREEN.

PHP application files unchanged between both task branches. First task's full
cold site-accept passed after owner-approved persistent swap8GiB: all style,
strict types, level8/baseline/paths/2GiB PHPStan and Unit/Integration/Functional
GREEN in one sequential run. Two required warm runs of part1 are still running;
see its ignored journal/site/var/accept-swap-runs.log and checkpoint, do not start
a duplicate runner. Delivery order remains #3040 then #3042.

Rulings: app+services push migration-free with exact source SQL inventory gates;
installed infra RepoDigests pinned/rechecked; app-only --no-deps prevents restart;
no new global mapping gate; preserve image prune336h. Backup retention/restore
and failed-staging cleanup remain explicit follow-ups, no extra permission granted.

Exact next: once part1 cold+warm+warm succeeds, mark both PR Ready, verify master
base and checks, ask the owner for merge+deploy in delivery order. The owner's
latest "Делай" approved persistent swap only; it is not merge/deploy permission.
No production actions performed. Do not redo finished implementation/reviews.
