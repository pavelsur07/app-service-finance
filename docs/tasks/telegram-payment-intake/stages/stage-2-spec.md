# Stage 2 — Python recognizer specification and acceptance

Review base: `dbfc6d5ff` (Stage 1 commit). Implementation began in the shared
workspace before that commit while Stage 1 files were selected separately.
Initial implementation base: `8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`. Risk HIGH-LOCAL.
Depends on standalone ai-parse-payment-message/v1. No automatic financial writes.

Implement TaskSpec using existing queue/gateway/retry/masking and HIGH risk.
Only exact Decimal normalization is deterministic; the model extracts candidates.
Prompts treat all source/context strings as untrusted data. Minimal PII-free
literal source fragments anchor evidence; names can remain masked in description.
Masking must never be bypassed for source text. Fail closed if required evidence
was transformed. Relative dates use original reference_at + supplied timezone.
Directory membership, direction compatibility, refs, correction target and pair
constraints are verified. Structural truncation cannot become a successful draft.

Acceptance checklist:
- [x] New type registered and routed, existing tasks unchanged.
- [x] Exact money normalization: 20 тысяч, 6 тыс, 1700р, 1 018; no floats.
- [x] Guessed scale, absent quotes, list/quantity/context-total amounts rejected.
- [x] Short amounts/grouping/unknown execution produce clarification.
- [x] Inflow, frozen relative dates, correction target, directory/pair checks covered.
- [x] Invalid JSON/truncated reply and injection are tested through real Pipeline.
- [x] Named source remains usable with minimal unmasked money evidence.
- [x] >=20 family-separated synthetic eval cases; report numerator/denominator.
- [x] Live model eval explicitly pending; stub proves integration only.

Checks: targeted RED/GREEN tests, make ai-service-lint/test/evals.
