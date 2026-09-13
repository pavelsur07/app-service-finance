### Stage 1: backend API management — DONE

Risk: HIGH-LOCAL. stage_base_commit:22399152. Work items1.1,1.2,1.3.

Implemented generated company public IDs and CompanyFacade resolution/actual-owner check, company-scoped independent key lifecycle and atomic allowlist Shared audit, separate stateless firewall/principal, request provenance, auth/check, Problem Details, key/IP quotas, no-store/profiler protection/log redaction, OpenAPI and generated TypeScript. API resources/scopes and UI deliberately remain for next Stages.

Migration evidence: existing company count0 on local test schema; isolated schemas exercise populated backfill, uniqueness/NOT NULL, concurrent physical PostgreSQL connections, old inserts and sequence consumption through rollback. UUIDs are unchanged; sequences never cycle. New API table contains hashes only. Timestamp fields timezone-aware. Both migrations applied only to local test database; no production actions. Down migrations require forward-fix to preserve published public IDs/revocations. New column ADD DEFAULT nextval rewrites existing companies under schema lock; production table size/backup/dispatch verification at handoff.

Internal review: root verification found UTC hydration expiry defect (BLOCKER1 fixed); key implementer found IMPORTANT2 for constructor invariants and debug/time handling, fixed. Independent reviewer found IMPORTANT2 (company-header quota bypass; concurrent revoke) and MINOR1 (Problem media type), all fixed; scoped re-review open0. Regression tests demonstrated red/green for UTC boundary, invalid-header quota, stale entity revoke; audit rollback test throws after audit SQL insertion.

Checks: final module set PASS381 tests/1676 assertions,1 pre-existing deprecation; focused PHPStan across changed production/tests/migrations PASS; Doctrine mapping/YAML/API generated types PASS. Focused unit/integration/external tests pass as recorded in checkpoint. Initial module run379 tests1575 assertions had only1 assertion comparing naive timezone strings; corrected to actual epoch comparison; final run green. Scope/classification YAML and targeted style pass. OpenAPI types regenerated.

External: round1 Claude:0 BLOCKER,2 IMPORTANT,3 MINOR; all confirmed and fixed without re-run per AGENTS §7.2. Fixed throwable monitoring, documented DBAL public-ID schema drift, version default mapping, AuthenticationException401 and sequence lower-bound naming. Fresh focused internal review confirmed all5 resolved. Regression tests red→green8 tests; integration/HTTP after fixes22 tests457 assertions PASS; focused PHPStan/style PASS.

Review metrics: internal found BLOCKER1 / IMPORTANT4, external found BLOCKER0 / IMPORTANT2; all fixed, open0. External rounds1, fixed without re-run. No financial endpoints or production actions.

Next: continue Stage2 automatically.
