# Balance ledger checkpoint
Phase: Delivery Stage 1 backend integration; Stage 2 UI work prepared in parallel
Status: integrating; implementation NOT handed off
Stage base commit: 238dd997
Branch: feat/balance-ledger
Worktree: /home/deploy/projects/app-service-finance/.worktrees/balance-ledger
Container: vf-balance-dev, worktree /app and original vendor mount
Dedicated local test DB: balance_ledger_test (260 migrations applied, including Version20260914120000)
Test runner: /tmp/vf-balance-run php bin/phpunit ... safely forwards ignored .env.test.local URL (phpunit env defaults otherwise win)

Completed:
- TASK/plan/schema captured; original master untouched except local git exclude .worktrees.
- Schema agent implemented new entities, migration/builders; now same agent owns all Controllers/Forms/templates/Stimulus/UI functional tests.
- Root implemented categories/structure/seed/read report/exception listener, exact shared formatter + Company member choices.
- Posting agent completed ledger, amounts, target draft corrections, DB concurrency tests. Done.
- Queries agent completed reports/permissions/periods/grants, pagination/UUID checks, turnover regression. Done.
- Removed unused legacy Balance provider/link classes and six obsolete baseline entries. Old DB tables preserved.
- Architecture contracts updated.

Checks:
- Baseline 14 tests/18 assertions green.
- Schema 15 tests/43 assertions green.
- Core 27 tests/68 assertions across full and targeted runs; focused stan green before subsequent legacy deletion.
- Read/period/access 10 tests/33 + later UUID8/8 + turnover1/9 green.
- Root structure5/19, member+old create3/8, structure policy8/11, formatter2/5 green.
- Prior combined backend run85/208 had8 interim UUID test failures (asserted Throwable code instead of statusCode); agent fixed. Rerun after fixes green89 tests220 assertions.
- UI initial4/18 + Twig22 green; expanded tests/stan in progress with schema agent.
- No full handoff gates run yet. Temporary compose override /tmp/vf-balance-compose.yaml mounts task worktree, existing vendor/node_modules and dedicated local test env. Make gates: make DOCKER_COMPOSE="docker compose -f /home/deploy/projects/app-service-finance/docker-compose.yml -f /tmp/vf-balance-compose.yaml" <target>.

Review:
- Internal agent review found/fixed active-company authorization crossing (BLOCKER), archived ancestor posting, stale target snapshot, archive descendants rename, aggregate historical overflow, article-card intermediate atomic balance, turnover overflow; page overflow/UUID validation fixed.
- Need verify total metrics from agent reports and document final counts. Stage1 external review round1 running via shell session55450; output site/var/external-review/238dd997/review.txt. Reviewer scope backend; frontend reviewed at handoff.
- Fresh whole-diff internal final review still required (new read-only CLI session; no fresh agent slots in tool tree).

Exact next actions:
1. Wait for expanded UI tests; independently run backend unit/integration subset after fixes, focused lint/stan.
2. Review entire backend diff, external review Stage1 (>500 lines), fix; commit/push backend with docs and create Draft PR master; root determines snapshot scope while UI prepared.
3. Review/commit UI Stage2 after UI gates and tests.
4. Full handoff gates once, fresh internal + external review; handoff Ready PR with migration approval request. No production commands.

Rulings:
- Delivery stages consolidated 1–3 backend, Stage2 frontend, Stage3 acceptance; no feature removed.
- Legacy tables retained without ORM runtime, standalone new articles schema.
- Account/current/side/article balances bound to BIGINT; cumulative turnovers/date deltas remain arbitrary precision strings and shared formatter supports these exactly.
- Only local feature access implementation; no actual production grant changes.

## Latest checkpoint

Core external performance correction complete30tests74assertions. Expected validation typed for narrowed listener; new listener2tests7assertions, combined16/38green. Company onboarding updated for autonomous seeded tree3/27green. Owner seed now explicit audit actor; system company-creation seed remains null.

External round1 B1/I2 confirmed and fixed; round2 complete-scope running shell97478, output site/var/external-review/balance-round2. Fresh Codex internal full-diff review running shell93920, output site/var/balance-internal-final/review.txt. No commits yet; integrated commit ruling in stage-1.

Handoff gates launched: strict typesGREEN; unitGREEN2866tests15971assertions,4deprecations; full stan shell24197; full cs shell75928. ESLintGREEN, Vite buildGREEN (existing missing ux-turbo package warning), global mapping47 known existing errors with unchanged input tree; UI Kit global8965 baseline errors, new templates narrow verification by UI agent. UI agent browser fixture currently holds dedicated balance_ledger_test DB; wait release before full suite. No production access.

Next: complete browser/scoped UI checks, full make site-test with dedicated env (dependencies can be marked already prepared if safe and documented), review findings/fixes, stage docs, commit/push DraftPR master, Ready+handoff migration approval. Do not leave runnable git/review steps to owner.

## Integrated checkpoint before Draft PR

Fresh internal final round1 found B2/I6, all accepted and implemented (review-findings.md). Core now frozen38tests98assertions; queries/access frozen28tests88assertions; UI26tests170assertions, Twig23, narrowUIkit23files312uses, real built-JS browser artifact desktop/mobile green. Real login E2E not asserted (test session configuration bounced); auth covered functional HTTP tests. Root post/lock API updates13tests60assertionsgreen.

Added CompanyFacade financialAccess fresh scalar rights/company FOR SHARE, Balance lockForMutation before book; owner rebuild route with CSRF+reason; separate reopen permission; post expected version; tombstones; sparse-index fix; SQL cards pagination.

Full stanGREEN on initial integrated snapshot. Full cs failed13taskfiles due scoped formatter using wrong .dist config; correct .php-cs-fixer.php now applied, focused final check pending. Full unitGREEN2866/15971 with4deprecations, strictGREEN. Final fresh balance_ledger_handoff DB all260migrations succeeded with updated grant column. make site-test running shell48825, log site/var/balance-gate-full.log (2 early errors/failures to inspect on completion). Existing installed dependencies reused by make -o site-composer-install; no dependency change.

External round2 failed tool completion: Reached max turns40; no green claimed. One corrected retry narrowed exact critical file/read guidance running shell92511 output site/var/external-review/balance-final. Fresh independent internal corrected full-diff round2 running shell79578 output site/var/balance-internal-final2. Do not repeat failed reviewer unchanged or claim complete until results.

Next: checkpoint commit/push single Draft PR(master), finish full test diagnostics and final focused stan/style after fixes, resolve fresh reviews, update stages/handoff, Ready and named migration+deploy request. No production mutations or access performed.
