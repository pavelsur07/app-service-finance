# Stage 5 — live evaluation preparation report

Prepared the reviewable action in ../live-eval.md and an offline-only default
runner, ai_service.evals.payment_intake. Existing categorization scorer assumes
complete gold; the payment runner instead scores only legitimate partial-label
constraints. Core Pipeline, masking, adapters, task checks and contracts are unchanged.

The metadata-only delivered manifest contains all 41 IDs: event-list 25, misc 13,
salary 3. It records source/review/schema/prompt/runner hashes, no source text, and
network_calls=0. Rejected/failed results count as missing verified candidates;
precision, overall/analytics/date accuracy and question quality remain null.
Model alias provenance is explicit; provider weight identity is unavailable.

Default execution cannot construct a network adapter, even with credentials
present. Real execution requires explicit route, model, call/token maxima,
prices, timeout and budget estimate. Only PAYMENT_INTAKE_EVAL_TOKEN is used for
a provider; AI_GATEWAY_TOKEN is never read. Single provider, zero gateway retries;
Pipeline's second schema attempt consumes the same global call cap. Before each
call configured maxima are reserved with Decimal prices; failures retain their
reservation. Calls and output cap are enforced. UTF-8 input guard and monetary
ceiling are conservative estimates, not exact tokenizer/provider billing guarantees.
Unknown usage and provider model IDs are not fabricated. Saved reports contain
metadata/counters/hashes only; exceptions print stable error/class, never details.

Verification (Docker, serial):
- Baseline payment evals: 3 passed.
- New tests observed missing-runner import failure, then targeted 8 passed.
- make ai-service-lint: ruff/format green, mypy 55 source files green.
- make ai-service-test: 295 passed, 1 skipped, two existing Sentry warnings.
- make ai-service-evals: 9 passed, 1 skipped (live categorization has no key).
- Actual default CLI manifest generation completed with 41 cases and 0 calls.
- Internal review: 0 BLOCKER; 1 IMPORTANT found and fixed, 0 IMPORTANT open. Whole-task independent/external
  review belongs to coordinator; no review marker claimed by this preparer.

Proposed action for named approval: deepseek/deepseek-flash on 41 anonymized
cases, max 82 calls, input byte/framing guard 65536, output 16000, timeout 60 s,
budget estimate 300 RUB using explicitly dated configured rates. Current tariff
verification and separate local test-token provisioning remain prerequisites.
No live approval, provider call, production credential access or production
change occurred. Stage 5 live quality and pilot readiness remain pending.

Late self-review I1: a provider response above declared usage maxima plus malformed
JSON allowed the immediate schema retry to delegate again. The new regression
failed RED (2 calls instead of 1); a two-line early stop_reason guard made it
GREEN (8 runner tests). The refreshed manifest hashes the fixed runner.
External handoff MINOR2: separator minus sign conflicting with a single explicit
verb now requires unknown direction plus a question. Three regressions failed
RED and are GREEN; corresponding prompt text is aligned. Combined targeted
review+runner suite: 64 passed. No additional API call or contract change.
