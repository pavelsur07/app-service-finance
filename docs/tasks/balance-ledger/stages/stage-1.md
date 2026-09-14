# Stage 1 — Autonomous ledger backend

Status: complete. Risk: HIGH-LOCAL. Task base `238dd997`; implementation checkpoint `e380f4ca`. Consolidates approved backend work packages1–3; frontend verification is separate Stage2.

## Result

- Additive ledger schema, same-company constraints, legacy tables preserved, no destructive down.
- Two balance sides, four article levels, terminal accounts, immutable used classification, durable reference history, safe deletion and archive, audited changes and sorting.
- Configurable book and balanced opening; exact minor-unit documents, draft versions, immutable posting/reversal/correction, request idempotency surviving deletion.
- Serialized company → book mutations and fresh financial permissions; separate prepare/post/close/reopen grants. Backdated validation checks suffix balances; append does not replay journal prefix.
- Owner state recovery validates journal, restores all states including zeros, reconciles and audits. Every supported writer preserves state=posted-journal atomically.
- Date-consistent reports, explicit effective opening date, SQL-paginated cards and journal, full-period totals, audit and exact oversized turnover display.
- Old external Balance amount providers removed. Existing Company tree/seed/report infrastructure retained and adapted; no financial integrations added.

## Verification

Baseline14tests18assertions green. Final backend module112tests307assertions green; new reference/sort and later corrections have focused evidence in review-findings.md. Company onboarding and root structure/access adaptations also passed. Migration applied from scratch through all260versions in dedicated local final DB. Doctrine mapping validation --skip-sync green; full schema synchronization not claimed because archival tables and composite migration-managed constraints are intentional.

Full project gate results belong to Stage3. Frontend evidence belongs to Stage2.

## Review

Early internal review fixed active-company permission leakage, archived-ancestor posting, stale target calculation, archived-descendant rename, historical aggregate bounds, atomic group cards, oversized turnover, nested consistent snapshots, audit pagination and ORM in-memory changes surviving rollback. All known application-triggered findings resolved with focused regressions.

External round1 B1/I2 fixed: selected target account shadow, overly broad/log-suppressed exceptions, full-history posting scan. Round2 exhausted40turns without a conclusion. Corrected retry returned REVIEW_GREEN; two safe UI MINOR findings fixed. Whole-task fresh internal review/fix cycles and remaining follow-ups are recorded in review-findings.md. No confirmed open BLOCKER/IMPORTANT remains at Stage closure.

Backend/frontend were prepared in one isolated worktree and checkpointed together to avoid an intermediate revision with removed providers but old controllers. Stage scopes and checks remained distinct; one task branch and one Draft PR.
