# Stage 5 — Twig acceptance and final handoff fixes

- Status: complete, local acceptance and review fixes verified.
- stage_base_commit `b67af3f0b`; baseline Cash1038tests/3956assertions PASS.
- Risk MEDIUM for UI; HIGH-LOCAL for bounded import-contract fixes found at handoff. No redesign, new frontend stack, inline script/handler, dependency or migration.

## Delivered
- Existing pagination partial on accounts/funds/profiles/calendar; scoped backend page results retained. Explicit calendar list route works when the same template renders edit/new forms. Filters/limit survive links and form navigation; filter submission omits page. Total footer accurately labels the page total; full-period forecast remains unchanged.
- Calendar empty result distinguishes existing plans outside filters from no plans. Filters remain available. Counterparty choices display shownName matching existing facade ordering.
- Table-responsive wrappers and existing wrapping pager/card classes; READ-only members see data and no mutation controls, server WRITE/CSRF gates preserved.
- Multi-split edit notice links to allocation/category editing without promising total/currency changes on that screen. Server guards retained. Fund amount label contains actual currency; invalid/overflow input/error retained on form, raw string Money path unchanged.
- Handoff source corrections keep existing normalizer as financial authority: either directional column supported; mapping form must preserve its priority over amount. Date/source completeness and structural draft rules retained. Profile decoding uses explicit validation intent, independent of display wording. Missing plan expected_at reports a safe error without inventing a date or mutating data.

## Checks / evidence
- Initial UI9failures RED→GREEN: pager wiring, filtered empty state, READ-only controls, split notice. Initial focused30tests/180assertions PASS; additional handoff regressions below.
- Fresh internal review:2IMPORTANT+1MINOR fixed. Calendar edit with2plans/limit1 originally500 MissingMandatoryParameters; corrected list links preserve filters. One-sided profiles originally302 refusal; actual apply→preview→confirm→Handler persists expected amount/direction. Hint corrected.
- External handoff: IMPORTANT UI mapping form still discarded lone direction when amount selected; regression now uses actual mapping form POST. MINOR explicit validation flag and nullable expected_at guarded. Verification appended after acceptance.
- Main PHPStan passed after17task-owned test type/annotation fixes; no baseline growth. Main CS rules differed from focused dist config:8task files normalized (global constant qualification/doc alignment); main CS and strict-types pass, Twigcs no violations and396Twig files syntax-valid.
- Frontend source unchanged. Vite production build PASS with existing dependencies and `--configLoader runner`; initial default loader hit root-owned shared .vite-temp cache, avoided without permission changes. Temporary node_modules symlink removed.
- Existing apiFetch source transpile smoke:403/404/422 preserve structured status/code/message. No client changes needed.
- Playwright browser on actual rendered synthetic fixtures with current built CSS:1440/390, four lists plus fund error/split edit (12cases), native next-page link/caption, contained horizontal tables, no page overflow/JS errors. Initial early scroll check failed transiently; diagnostic run with measurements completed, screenshots inspected. Artifacts under ignored site/var and /tmp/vf-cash-remediation-ui; no production data.

## Review / scope
- Complete task fresh-context review and read-only Claude external review performed per AGENTS, independent of implementation history; no review delegated to owner.
- Explicit deferred scope: recurring materialization on GET, missing processing delivery, historical discrepancy diagnosis. Production schedule remains disabled and requires named approval.
- Full handoff gates, final metrics and exact commit/PR acceptance recorded in handoff.md after completion.

## Accepted handoff evidence
- Final Cash module + Money1053tests/4090assertions PASS; final unit4611/25546 PASS; fullproject8725/48824 PASS before bounded external fixes, fresh complete Cash regression afterwards. Main PHPStan/CS/strict-types/Twig targets PASS;396Twig files valid;74original route contracts retained.
- Handoff external1round: IMPORTANT mapping-form priority fixed by matching existing normalizer; 4real UI/Handler cases preserve100.00INFLOW/200.00OUTFLOW with/without Amount. MINOR label-independent validation and NULL-date safe refusal both RED→GREEN. No new financial-date default, no row parser change. Accepted fixed without re-run perAGENTS.
- Final source self-review: no open BLOCKER/IMPORTANT. Fresh internal review0/2 findings fixed; external0/1 confirmed fixed, minor fixes verified. Full metrics inhandoff.md.
- Small presentation fix after screenshot inspection: existing d-block utility keeps multi-split notice/link readable on narrow screens; notice functional test and focusedTwig lint pass.
- Owner instructions select existing PR delivery over the finishing skill menu; no extra approval requested before commit/push/Ready. Worktree retained for review/release, no branch deletion.
