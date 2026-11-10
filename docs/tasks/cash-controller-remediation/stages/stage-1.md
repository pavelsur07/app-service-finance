### Stage 1: Финансовые инварианты и точность сумм — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `22b07f6ba`
**Work items:** 1.1–1.4

#### What was done
- Ordinary edits reject normalized total/currency/category changes on multi-splits with a concrete form-visible exception.
- Split composition/projection/audit/plan-match removal are atomic under the same source row lock as ordinary edits and both document Actions. Current company and composition are refreshed only for the two split mutation callers.
- Both document Actions reject multi-splits before either confirmation branch; closed-period checks run under the source lock.
- Fund form preserves strings; shared Money handles currency scale/half-up/overflow; empty input stays zero, negative/zero values allowed, invalid input stays on the form with 422.

#### Files changed
- Cash Actions/lifecycle, fund form/controller, transaction exception/HTML/JSON catches, architecture contracts.
- Integration/functional tests and independent-process race fixture; route inventory and task docs.

#### Definition of Done
- [x] Multi-split total/category mutation refused; normalized total and description edit allowed, including real HTML form.
- [x] Both P&L paths reject multi-splits; both JSON confirmation values return 422 and create no document.
- [x] Four real PostgreSQL concurrency orders preserve split/document invariants.
- [x] RUB/JPY/BHD, half-up, negative/zero/empty/invalid/overflow and locale-grouped inputs covered.

#### Checks
- Baseline: targeted Actions PASS 25 tests / 74 assertions.
- RED: multi-split ordinary-edit bypass, JSON confirmation bypass, stale total/closed period; four process races FAIL against original Actions; fund precision/scale/input errors FAIL on original implementation.
- GREEN: first Cash module run after fixes PASS 902 tests / 3586 assertions; final review-fix regressions PASS 38 tests / 128 assertions.
- Focused PHPStan: no errors; focused PHP CS Fixer: no pending changes; git diff --check: clean.
- Final module run (excluding not-yet-implemented Stage 2 tests): PASS **906 tests / 3608 assertions**.

#### Internal review
- Two IMPORTANT found and fixed: transfer-leg preflight must not close EM for unrelated subsequent work; closed period must use refreshed company state. No open BLOCKER/IMPORTANT. Final diff checked for company scope, locks/rollback, no amount formulas or IDs transformed.

#### External review
- Required: yes, HIGH-LOCAL diff around 500 lines. One Claude Code read-only round.
- Result: **fixed without re-run**, per AGENTS §7.2; no BLOCKER found.
- IMPORTANT confirmed/fixed (2): JSON create-from must handle new RuntimeException (HTTP regressions RED→GREEN); avoid children/company refresh on every bulk/import lock (composition refresh isolated to two callers).
- IMPORTANT rejected (1): closed-period assert considered scope expansion. Owner plan §1.2 explicitly requires current closed-period checks under lock for both P&L creation scenarios; retained within authorized scope.
- MINOR: dead catches removed; real HTML description-edit regression added (already worked); narrow locale grouping retained and tested RED→GREEN.
- Reviewer limitation: no environment/secrets/production access; closed-period scope finding explicitly did not inspect FinanceFacade.

#### Risks / follow-ups
- JSON error shape/listener and one-action controller refactors belong to Stages 2–4; templates/notice/pagination to Stage 5.
- No migration/dependency/production change.

#### Next
- Continue to Stage 2 automatically.
