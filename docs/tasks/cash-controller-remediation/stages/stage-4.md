# Stage 4 — Durable import delivery and backend contracts

- Risk HIGH-LOCAL; stage_base_commit `967ac022d`.
- Scope: owner §4.1–4.5. No migration, dependency, production schedule or existing-ID transformation.

## Delivered
- StartCashFileImportJobAction owns one journal/job transaction with or without confirmation ID; repeat returns existing job, mismatched account/company rejected before writes.
- HTTP saves committed session before immediate dispatch. Expected transport outage leaves queued intent, shows warning, repeat cannot create another job. Shared Dispatch Action logs only company/job IDs and exception class.
- `app:cash:import:recover-delivery [companyId] [--execute]`: default dry-run, queued older120s only, optional company/all candidate companies, dispatcher session lease per company, keyset100 advances through skipped rows, one shared queue snapshot, no row locks/new DB transaction during network. Status/age/failed rechecked before send; processing/terminal/lost-closed excluded; duplicate Handler behavior retained.
- Redis stream bounded XRANGE500; delayed cursor ZSCAN COUNT500 avoids rank shifts under concurrent pops. Two existing transition-safe passes constant across candidate/company/page count.
- All remaining Cash controllers split into final __invoke with private readonly constructor DI; no flush/SQL/domain entity construction. Shared HTTP presentation helpers retain route/CSRF/access contracts. Domain drafts in existing Save Actions, new IDs UUIDv7 only. Range enqueue orchestration moved to Action, same message/routing; existing matching service composes modal preview.
- Create-from JSON errors via explicit listener route: stable error.code/message,404/403/422; successful response unchanged. Existing HTML routes unaffected.
- Accounts/funds/profiles/calendar scoped Pagerfanta page1,limit50,max200; invalid/nested/out-of-range422; ID tie-breaker. Calendar forecast and form choices remain full-period/unbounded; to-one list joins avoid lazy row lookups. Templates deferred to Stage5, no intermediate deploy.
- Removed obsolete PHPStan ignores for deleted controller classes, no baseline growth.

## Verification
- RED→GREEN: no-confirmation orphan journal after job insert failure; transport failure500 after saved job; JSON old shape; pagination ignores invalid parameters/returns all rows; bounded delayed reading (old single ZRANGE fails); cursor scan requirement (rank paging fails).
- Initial module1030 had3 existing exception-type expectations to migrate; refusal/document count assertions retained. Additional draft test fixture corrected to valid actor UUID.
- Final pre-review module **1036 tests /3949 assertions PASS**. Includes real PostgreSQL rollback/concurrency, existing Handler duplicate/idempotency tests, company lease on independent connection, network operation nesting check, status race,202 candidates/2companies/200skipped-head/oneprobe; real1201stream+1201delayed entries.
- Review-focused suite and focused PHPStan: results appended below after completion.
- 74 original route URL/method/requirements unchanged; git diff --check clean. Focused CS fixer applied; final dry-run below.

## Internal review
- Full Stage diff checked: company isolation before data, source/job atomicity, sorted company leases and release, cursor progress, fail-closed unavailable queue, sanitized logging, financial formula/sign/normalization compatibility, CSRF/access preservation, bounded lists/full forecasts.
- BLOCKER0, IMPORTANT1 fixed (real eager Redis transport initialization can throw raw RedisException before Symfony wraps it; Dispatch Action now catches concrete Redis transport errors, eager/lazy refused-connect tests RED→GREEN); no open must-fix findings.

## External review — one round
- Claude read-only; no BLOCKER. One reported IMPORTANT rejected with technical evidence: RequestEvent::setResponse() calls stopPropagation() in installed Symfony; Cash listener precedes ErrorListener at equal priority in this application. Functional403 test asserts zero ERROR+ records and passes without config change. No unnecessary framework exception override added.
- MINOR fixed: delayed rank paging replaced by cursor scan (real-load test RED on rank reads→GREEN on ZSCAN); duplicate casts removed; obsolete Money import removed.
- Reviewer could not inspect worktree vendor. Installed Symfony wraps lazy connection/send errors, but eager transport initialization throws raw RedisException; confirmed by a real bus/sender regression and fixed. Both initialization modes tested, not just a mock.
- Accepted under AGENTS§7.2 without re-run: no BLOCKER, no confirmed/open IMPORTANT; fixes verified.

## Risks / follow-ups
- Scheduler intentionally not enabled. Proposed once/minute production --execute requires named approval; this PR supplies the command only.
- Guarantee covers missing queued delivery. Lost processing recovery, recurring materialization-on-GET and historical reconciliation remain explicit follow-ups.
- Continue Stage5 automatically after Stage commit/push/Draft PR update.

## Accepted checks
- Post-review module **1038 tests /3956 assertions PASS** (`tests/Unit/Cash tests/Integration/Cash tests/Functional/Cash tests/Unit/Shared/Domain/ValueObject/MoneyTest.php`).
- Review-focused suite **73 tests /126 assertions PASS**, including cursor scan, independent dispatcher lease/status race, real eager/lazy Redis refused-connect and no false403 incident log. Focused PHPStan no errors; focused CS clean after fixes; route comparison74/74.
- Metrics: internal BLOCKER0/IMPORTANT1 fixed; external BLOCKER0/IMPORTANT1 reported,0 confirmed (rejected with installed-code+functional evidence). No open BLOCKER/IMPORTANT. External1 round, accepted without re-run.
