# Payment message verification evaluation

`cases.jsonl`: 24 synthetic verifier replies and explicit expected check outcomes.
These are adversarial fixtures, not model-produced results. Tests report pass/N
by dimension and exercise structured Pipeline separately. No live calls or model
accuracy claim. Model/prompt: fixture replies / current version from TaskSpec (v4).

`corpus-review.jsonl`: review of all 41 source records; partial monetary facts,
questions and exclusions only. `tg-016`–`tg-040` stay in event-list family,
including list fragments/near duplicates. Salary siblings form one family.
No source or synthetic case is in prompt. The corpus has now been used for approved live evaluation and prompt tuning;
it is a development/regression set, not an independent holdout. Future unseen
evaluation must preserve family isolation and have an approved call budget.
Raw source corpus remains in docs; no per-company historical saved money used.

`contexts.json`: independent A/B companies with same names/different IDs,
forbidden pair, archived item omitted from allowed directory, missing-account
Symfony obligation, independent correction/personal-funds dialogs.

Approved full v3 evaluation:41cases/41calls,49/49annotated candidates,
47/47known-scale amounts,5/7explicit directions. All41needs_review with HIGH risk;
this is not overall accuracy. tg008returns2items vs1annotatedcandidate,oneunknown
amount; precision remains unmeasured. Total50items,3unknownamounts,39unknown
directions. Analytics/date/question quality have no complete gold. Stage5/pilot
acceptance remainpending; see docs/tasks/telegram-payment-intake/live-eval-result-2026-11-14.md.
Stub/fixture green proves deterministic integration/checks only.

Current local v4 adds a clause-local reference-total guard for unknown amounts
and explicit direction wording. v1–v3/schema unchanged. v3numbers above remain
historical v3measurements; current v4 has not yet been measured live.
