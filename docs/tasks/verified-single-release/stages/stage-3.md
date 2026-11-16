# Stage 3 — публикация скрытых артефактов

Base: 13e657577c176f044de43a2719487f073ba98832. Risk HIGH-LOCAL.
Definition of Done: metadata uploads work with upload-artifact v4 while
preserving narrow path allowlists and if-no-files-found:error.

First master run built images but failed uploading .descriptor/*.json.
Official action.yml confirms include-hidden-files defaults false. Added explicit
true to all four metadata uploads; paths unchanged, no secrets uploaded.
Regression initially failed4 subtests; now GREEN.

Checks: 12 bundle +8 production +9 workflow +6 frontend +4 auth +6 runtime
contracts GREEN; deploy-workflow shell GREEN. Real temporary PostgreSQL1 GREEN.
site-accept10:8 passed,2 opt-in skipped (previous full acceptance3 runs GREEN).
actionlint/diff-check GREEN. PHP source unchanged, no repeated PHP suite needed;
required CI reruns all PHP gates.

Fresh internal B0/I0/M0. External Claude read-only full diff, one round:
REVIEW_GREEN B0/I0, static review without tools. Existing re import confirmed
in source and passing test. No open findings.

Stage implementation complete. Production acceptance pending successful fix-run.
Separate PR required because original PR3042 already merged. Owner approval
for same release is preserved (AGENTS §3.2); no additional question required.
