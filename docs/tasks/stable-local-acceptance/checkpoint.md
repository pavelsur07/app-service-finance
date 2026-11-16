# Current checkpoint
Phase: Stage 2 / acceptance. Status: stopped (environment).
Stage base commit: 5a6456c03. Branch feat/stable-local-acceptance; PR #3040, master, Draft.

Completed: runner/Make/config/docs; all review fixes; 10 regression tests with
real PHP/config probe; existing workflow/frontend tests; Unit4928,
Integration3037, Functional1497 separately GREEN. Measurements: measurements.md.
Internal fresh final B0/I0/M0. External round1 REVIEW_GREEN (B0/I0), MINOR fixed.

Blocking command:
DOCKER_COMPOSE='docker compose -p vf-acceptance -f docker-compose.yml -f site/var/accept-compose.yml' make site-accept
First full cold worker1: exit1 after host OOM. RCA kernel proves same container.
Different approach workers0/main-only: exit137 after second host OOM. Source
fingerprint stable in both; cleanup successful. No automatic retries, no RAM,
swap, privileges or other sessions' processes/containers changed.

Exact next: after host has enough free RAM, use existing ignored Compose override
site/var/accept-compose.yml, verify own DB/Redis availability; clear ONLY this
worktree's site/var/cache, execute cold then two warm site-accept runs sequentially
on unchanged source (including task docs). First error stops. Record all three
fingerprints/results; then update docs/PR and Ready, ask merge+deploy once.
Do not redo implementation or reviews on unchanged code. Draft PR needs the
latest code/docs push; verify git status/log before any resume action.
