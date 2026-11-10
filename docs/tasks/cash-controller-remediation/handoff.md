# Cash controller remediation — handoff

Implementation, local acceptance and reviews complete. Final PR-head CI and Ready status are tracked in the PR; merge/deploy requires the owner decision.

Branch `fix/cash-controller-remediation`; base `22b07f6ba`; PR https://github.com/pavelsur07/app-service-finance/pull/2964 (master).

## Outcome and files
- Financial/source invariants: `site/src/Cash/Application/{UpdateCashTransactionAction,SaveCashTransactionSplitsAction,CreateDocumentFromTransactionAction,CreatePnlDocumentFromTransactionAction}.php`, shared source lifecycle; actual source refresh/locks, multi-split guards, atomic split/projection/audit/plan match. `MoneyFundMovementType` and existing movement Action preserve raw-string shared Money/half-up, zero/negatives and overflow refusal.
- Atomic accounts/restore: existing SaveMoneyAccountAction + AccountBalanceService/repository share source/recalc transaction, lock before source save, refresh DBAL balance. Restore emits CashTransactionsChanged only after successful transaction.
- Import/session/contracts: `Cash/Http`, Prepare/Confirm/Upload Actions, shared structural mapping validator, scoped company/account state before storage, expected file/date/mapping errors. Existing normalizer remains financial authority; either directional mapping supported and takes priority over amount through the real form path.
- Delivery: atomic journal/job Start Action, transport-safe Dispatch Action, `Command/Import/RecoverCashImportDeliveryCommand.php`, company dispatcher lease/query and paged Redis probe. queued older120s, dry-run default, keyset100, shared two-pass queue inspection, cursor delayed scan/XRANGE500, failed exclusions/status recheck, duplicate-safe existing Handler.
- HTTP/UI: all Cash controllers final single __invoke/constructor DI, domain drafts/UUIDv7 in Actions, explicit create-from JSON listener404/403/422, four scoped Pagerfanta lists. Existing Twig pager, preserved filters/limit/full forecast, accurate page total, empty/error/READ-only/responsive states. Calendar edit pager targets list; null expected_at reports an error without guessing or writing a date.
- Architecture contracts updated; stage reports, original74route-contract snapshot and focused regression tests live in this task folder / Cash tests.

## Acceptance / verification
Baseline and stage evidence:
- Fresh implementation baseline25tests/74assertions; original Cash routes captured before code.
- Stage1:906/3608; Stage2:915/3699; Stage3:984/3800; Stage4:1038/3956 (tests/assertions), all PASS after fixes.
- Bug regressions proven RED→GREEN: multi-split update/P&L and independent-process lock orders; exact fund input; account rollback/stale recalc and restore events; company-switch storage guard/malformed mapping/files/date filters; no-confirmation orphan log; dispatch outage/session retry; real eager/lazy Redis failures; queue pages/cursor/status/lease; four-list page traversal; calendar edit pagination; directional profiles through actual UI form and Handler; null-date refusal.
- Full `make site-stan` PASS, no baseline growth. First full pass found17task-owned test type/annotation issues; corrected without suppression.
- `make site-cs-check`, `site-cs-strict-types` PASS. Focused checks initially used dist config; main gate identified8task files needing global-constant/doc formatting; corrected using `.php-cs-fixer.php`.
- `make site-cs-twig` PASS: no twigcs violations,396Twig templates syntax-valid.
- `make site-test-unit` final PASS4611tests/25546assertions.
- `make site-test` PASS8725tests/48824assertions. After bounded external-review fixes, the complete Cash module + Money suite passed1053tests/4090assertions; final whole-project CI runs on the delivered PR head. Full unrelated local suites were not repeated for Cash-only review fixes.
- Existing frontend dependencies only: Vite build PASS with `--configLoader runner` after default shared-cache EACCES; no permission changes, no asset source changes. Temporary symlink removed. Existing apiFetch source smoke preserves structured403/404/422 status/code/message.
- Actual rendered synthetic-fixture browser smoke:1440/390, four lists plus fund error and split notice (12cases), next-page captions/links, horizontal contained tables, no page overflow/JS errors; screenshots inspected. Initial scroll automation failure investigated; diagnostic run completed. No production/browser data used.

## Reviews
| Point | Internal BLOCKER/IMPORTANT found | External BLOCKER/IMPORTANT reported | Resolution |
|---|---:|---:|---|
| Stage1 | 0/2 | 0/3 | Internal2 + external2 fixed; one external scope objection rejected: owner explicitly requires closed-period checks under source lock. |
| Stage2 | 0/1 | 0/0 | Advisory/source lock inversion fixed with independent-process regression; external REVIEW_GREEN. |
| Stage3 | 0/4 | 0/1 | Internal4 fixed. Original external half-pair advice reclassified as rejected at handoff: existing normalizer intentionally supports either direction; owner requires preserved financial behavior. |
| Stage4 | 0/1 | 0/1 | Eager Redis transport failure fixed; reported CRITICAL-log finding rejected by installed Symfony setResponse/stopPropagation + real403 noERROR+ test. Minor cursor/cast/import fixes verified. |
| Handoff | 0/2 | 0/1 | Fresh independent context: calendar edit links and one-sided profile compatibility fixed; hint corrected. External: actual mapping form priority fixed, explicit validation intent and null-date refusal covered. |

Metrics: internal BLOCKER0/IMPORTANT10 found and fixed; external BLOCKER0/IMPORTANT6 reported,3 confirmed/fixed,3 rejected with evidence. No open BLOCKER/IMPORTANT at final acceptance. Five external review points, one round each; accepted via REVIEW_GREEN or verified fixes without re-run per AGENTS§7.2. No BLOCKER requiring another round.

Fresh internal review used only owner spec, complete task diff and checklists, no implementation history. Its declined items are resolved as follows: recurrence-on-GET, lost processing delivery and historical repair remain explicit owner follow-ups; implementer supplied browser/real queue/DB evidence that read-only reviewer could not execute. No owner-conducted review required.

## Rulings and practical limits
- Existing row normalizer is the owner-specified financial authority. One-sided profiles stay valid; mapping form now preserves directional-over-amount precedence too. This intentionally corrects the ambiguous UI combination amount + one directional column; it imports the directional value, matching direct normalization. No signs/formulas/row parser changed.
- Draft mappings remain structurally permissive. Actual execution needs a date and at least one amount source; row errors come from existing normalizer.
- Closed-period checks under source lock are required scope; no historical IDs/data transformed.
- Recovery guarantees missing queued delivery when invoked. processing recovery remains deferred. No automatic production schedule installed by this PR.
- Existing shared legacy XLS conversion resource limits remain; upload validation enforces32MiB and supported formats without weakening converter safeguards.

## Release and follow-ups
No migrations/new dependencies, production configuration/scheduler/queues/credentials/permissions changed; no production mutations or historical repair performed.

Only after owner `merge and deploy #2964`: merge into master, wait CI/automatic deploy, perform read-only post-deploy acceptance through approved wrappers and report the result. Production scheduling is a separate named approval under AGENTS§3.3: proposed once/minute `app:cash:import:recover-delivery --execute`. A merge approval alone does not enable it.

Explicit follow-ups: recurring payment materialization on GET; recovery for lost processing messages; diagnosis/reconciliation of historical financial discrepancies. No automatic backfill/recalc.

Stage commits:97c0d7495,32ba6693e,967ac022d,b67af3f0b; Stage5/handoff commit is final task PR head.

## Final local acceptance
- Main PHPStan, CS, strict-types, Twig and unit targets PASS on final code; no baseline growth. Last shared notice presentation adjustment passed its functional regression and focused Twig lint.
- Final Cash suite1053tests/4090assertions PASS; full unit4611/25546 PASS; whole-project suite8725/48824 PASS plus fresh Cash-only review-fix verification. Original74route contracts unchanged. No migrations/locks/dependencies/CI or production configuration diff.
- Handoff external:1IMPORTANT+2MINOR verified RED→GREEN, accepted fixed without re-run; no BLOCKER, no open IMPORTANT. UI mapping tests now follow profile apply→mapping form POST→preview→confirm→real Handler. NULL-date test clears stale ORM fixture state and proves old500→safe redirect, no date mutation.
- Task-owned working diff self-reviewed after fixes; all Stage5/handoff findings closed. CI outcome is authoritative at the PR checks link; no merge or production scheduling performed.
