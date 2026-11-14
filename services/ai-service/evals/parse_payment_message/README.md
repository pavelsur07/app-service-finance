# Payment message verification evaluation

`cases.jsonl`: 24 synthetic verifier replies and explicit expected check outcomes.
These are adversarial fixtures, not model-produced results. Tests report pass/N
by dimension and exercise structured Pipeline separately. No live calls or model
accuracy claim. Model/prompt: fixture replies / current version from TaskSpec (v2).

`corpus-review.jsonl`: review of all 41 source records; partial monetary facts,
questions and exclusions only. `tg-016`–`tg-040` stay in event-list family,
including list fragments/near duplicates. Salary siblings form one family.
No source or synthetic case is in prompt. Entire corpus is reserved for future
live holdout; live evaluation must split by these families and approve budget.
Raw source corpus remains in docs; no per-company historical saved money used.

`contexts.json`: independent A/B companies with same names/different IDs,
forbidden pair, archived item omitted from allowed directory, missing-account
Symfony obligation, independent correction/personal-funds dialogs.

Live semantic extraction quality, candidate recall and pilot thresholds remain
PENDING. Stub/fixture green only proves deterministic integration and checks.
