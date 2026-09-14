# Current checkpoint
Phase: Stage 0 / baseline
Status: checking
Stage base commit: 238dd997b27ecca5a1ae205fb417534e79de3439
Completed: repository/API discovery and approved plan recorded; branch feat/moysklad-integration.
Checks: Shared security unit baseline PASS (18 tests, 45 assertions).
Review: not started; no findings classified yet.
Next: functional/Twig baseline, then Stage 1 work items.

## Work items 1.1 / 1.2
API checks: red missing client, green 30 tests/165 assertions incl token validation and safe HTTP logging.
Storage: red missing methods, green 10 tests/35 assertions. Focused PHPStan/style pass.
Test migration Version20260914143000 applied.
Baseline: legacy Marketplace replacement functional 25 tests/100 assertions PASS; 5 Twig syntax PASS.
Environment: Docker bind /app does not see workspace changes. Isolated /tmp/moysklad-task in PHP container, source streamed via /tmp/moysklad-sync.py; vendor copied inside container, no local secrets copied. Use docker compose exec -T -w /tmp/moysklad-task site-php-cli. Make DOCKER_COMPOSE=/tmp/moysklad-compose.py runs canonical gates there.
Internal Stage review: 1 BLOCKER (Doctrine deletion ignores version) fixed via row-lock transaction, regression pending; 1 IMPORTANT (HTTP observability) fixed with 4 red/green privacy tests. Final Stage check/review pending.
Current: 1.3 controller/action integration tests; Stage2 templates prepared concurrently but not yet committed/closed. Stage3 command tests 5/28 PASS, command --company required dry-run/--execute.

Checkpoint before upstream integration: full module tests PASS 69/342; frontend lint/build PASS (build warns missing UX Turbo metadata in isolated frontend copy). Review fixes deletion locking and HTTP logs confirmed; pagination overflow fixed. Source is implementation-complete, formal Stage/handoff gates pending. Migration renamed to Version20260914143000 because upstream balance migration owns Version20260914120000.
Upstream master 0b4206db merged without conflicts (merge c1ce6e5c). Same-number upstream migration collision resolved by renaming ours 20260914143000; isolated test migration metadata remap pending before applying upstream.
Fresh handoff review found BLOCKER: non-ASCII token can reach Symfony header validation exception containing credentials. Strict printable ASCII + max length validation and regression in progress. No external review yet.
