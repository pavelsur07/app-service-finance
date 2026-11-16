# Current checkpoint

2026-11-16: owner authorized Ready → merge → production for #3040 and #3042.
#3040 merged 15562ad3e7e87127c2d012d1937599ef288144e1, production SUCCESS,
19 running containers, no unhealthy/OOM, source migrations323/executed327/missing0.
All three site-accept runs passed: 1499s cold, 667s warm1, 640s warm2; swap8GiB permanent.

#3042 merged 2e8d5217c2bf197fea2b3bd5a36741f3dab7474f. First master run
43930147386 failed before production: upload-artifact v4 excludes hidden files
by default, so .descriptor/*.json was not uploaded despite successful builds.
Four uploads affected, including resolved bundle and manifest.

Stage3 branch fix/release-hidden-artifacts based on current master13e657577.
Regression RED4 → GREEN, four narrow include-hidden-files flags.
Related release/worker/SSH/SPA/runtime contracts GREEN, real temporary PostgreSQL
GREEN, actionlint/diff-check GREEN. Fresh internal B0/I0/M0; external one round
REVIEW_GREEN B0/I0. No PHP application changes or migrations.

Next: commit/push single fix PR, Ready, merge under existing owner approval,
wait automatic workflow to completion, verify production manifest digest/SHA,
schema, health, unchanged infrastructure and timings. No manual production commands.
Evidence/journal: ignored site/var/release/pipeline-journal.json.
