# Stage 5 — offline-safe holdout runner specification

Owner: Python eval preparation only. No live call or production credential access.
Existing categorization evaluator assumes complete labels and cannot score this
partial corpus. Reuse unchanged Pipeline, TaskSpec registry, gateway and masking.

Plan: implement a standalone module command, manifest of all 41 case IDs grouped
by existing families, and a serial bounded adapter. Default only builds metadata
and never constructs adapters. Explicit execute requires selected single route,
call/input/output maxima, Decimal prices/budget estimate and timeout; no fallback
or gateway retry. A separate local test credential variable is the only token read.

Before every delegated call reserve configured input/output maxima. Call count
and output token cap are enforced; UTF-8-size input guard and cost reservation
remain conservative estimates rather than a tokenizer or provider billing guarantee.
Stop before the next call when any budget/limit is exhausted.

Score only annotated monetary mention coverage and literal amounts with known
scale, explicitly labelled directions and correction/question-presence constraints.
Partial gold cannot establish overall accuracy, precision, analytics or question
quality. Failed/rejected drafts count as missing verified candidates. Persist
only IDs, hashes, counters, stable errors, tokens, cost estimates and latency.
Actual LlmResponse model alias and response bytes are hashed; provider weights
identity is unavailable from existing adapters and must be reported unavailable.

Acceptance:
- [x] Default with credentials present still makes zero network calls.
- [x] All 41 cases and existing families appear in metadata-only manifest.
- [x] Stub proves limits apply to Pipeline's second schema attempt and serial cases.
- [x] No raw source, output, trace, token value or personal fields in saved report.
- [x] Missing budget/route/price/token prevents live adapter construction.
- [x] Partial labels never yield fabricated overall precision/accuracy.
- [x] Targeted tests, Python lint/test/evals green; live approval remains pending.
