# Stage5 continuation — live failure diagnostics

First approved run completed41cases/45calls; verified candidate recall26/49,
amount agreement24/47, explicit direction agreement1/7,5rejected. No raw model
outputs/traces were persisted. These aggregate metrics cannot distinguish safe
abstention, omitted candidates, schema failure and verifier failure.

Before changes: retain immutable first-run receipt and source hash. Add only
metadata diagnostics to eval runner: per-case verdict, stable failed-check codes,
validated intent and field-presence counts, per-case partial-gold counts. No raw
source/output/evidence/questions/descriptions, no financial values or credentials.
Allow explicitly selected IDs from the same41-case corpus, rejecting unknown or
duplicate IDs before adapter creation. Default remains zero-network manifest.
Do not change task prompt, extraction or verifier before identifying root cause.

Acceptance:
- [x] Rejected-schema and verifier reasons visible without raw text/trace.
- [x] Null/omitted candidates distinguishable from rejected output.
- [x] Per-case labels have the same partial-gold limitations as aggregate metrics.
- [x] Subset only accepts unique existing corpus IDs; no accidental paid execution.
- [x] Regression tests proven RED→GREEN; lint/mypy/tests/evals pass.
- [ ] Total campaign calls remain ≤82: first45, remaining37; no other provider/data.
- [ ] Each disposable diagnostic execution preserves app/config/existing containers.

Next diagnostic subset: existing critical direction/scale/long-list/multi-amount
and rejected IDs, maximum18additional calls. This stays within the explicitly
approved82-call trial and same existing-key/root-SSH exception solely for this
experiment; it grants no general or persistent production access. Remaining-call
ledger is checked before each invocation. Any full new acceptance run beyond82
requires another call-budget approval; no metrics from distinct versions are
combined into a claimed single-run accuracy.

Confirmed scorer bug: valid null amount_evidence + numeric text_evidence +
scale question previously scored0/1, now1/1. Numeric normalization is required
for fallback; arbitrary broad quote does not become an amount. Extraction,
verifier, prompt and public task contract are unchanged. The first raw metric
26/49 is retained as emitted by the old scorer; responses were not retained,
so no corrected full-run score is fabricated.14targeted tests pass,301unit/
integration tests pass(1skip), eval9pass/1skip, lint/format/mypy55green.
Fresh targeted reviewer B0/I0/M0 confirms scoring/privacy/subset guards.
