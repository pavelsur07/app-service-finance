# Stage 2 report — Python recognizer and result pull

Review base: `dbfc6d5ff` (Stage 1 commit). Initial implementation base:
`8a1f51cac4dacac4d49dd6b5cf72e0940d26a895`. Stage 2 specification was
written before Stage 2 code; implementation overlapped coordinator's selective
Stage 1 commit in the shared workspace. No Python commit/push by implementer.

Implemented strict PaymentInput/Output, TaskSpec registration, v1 prompt,
HIGH risk and existing gateway routes/retries. Decimal-only normalization
verifies source money, including Unicode spaces and explicit thousands.
Verifier checks literal unaffected evidence, full number boundaries, scale,
context totals/quantities/list markers, relative dates anchored to original
reference_at, category/project/CFO membership and pairs, correction targets,
execution status and obvious direction contradictions. Negative/future payment
cannot become a paid fact. No finance writes or privacy-core changes.

Minimal evidence can be just an unchanged money phrase. PII masking stays
active; names are omitted from generated descriptions/questions. Mask tokens
in any visible generated field or evidence are rejected. Extremely long amounts masked as PII cannot be recovered;
the user must clarify using a safe expression. Original source remains Symfony's.

Added authenticated company-scoped targeted pull:
GET /internal/tasks/{task_id}/result?company_id=<UUID>. Same existing results_page
shape; unknown/pending/foreign/acked return empty page. Existing ai-task/v1 is
unchanged. Real PostgreSQL integration covers pending→ready→acked, cross-company
query and wrong token. A read does not ack or consume the result.

Verification evidence:
- Baseline `make ai-service-test`: 192 passed, 1 skipped, 7 deselected.
- Final `make ai-service-test`: 284 passed, 1 skipped, 10 deselected (46.16 s),
  including 16 PostgreSQL integration tests and 84 task/review unit tests across two files.
- `make ai-service-lint`: ruff check/format green; mypy 54 source files green.
- `make ai-service-evals`: 9 passed, 1 skipped (live categorization has no keys).
- Final targeted eval with stdout: 24/24 fixture check outcomes correct;
  amount 10/10, date 7/7, direction 6/6, evidence 3/3, analytics 4/4,
  company isolation 3/3, currency 1/1, clarification 8/8, execution 1/1,
  privacy 1/1, intent 4/4; negative fixtures assert their specific expected error. Complete 41-case corpus-span review passes.
- Two existing Sentry deprecation warnings and existing source-mount skip remain.

TDD: missing task/schema tests failed first; schema tests 7 green. Targeted
endpoint failed with 404 first, then PostgreSQL flow green. Future-paid,
direction contradiction, negated-paid, partial-number and numeric-date-coercion
regressions were each observed red before their fixes; final suite green.
Lint findings were fixed locally (formatting, nested if, print suppression).

Internal review: 0 BLOCKER, 5 IMPORTANT found and fixed (future/negated paid
anchoring; direction contradiction; partial number/scale anchor; strict contract
date coercion). Subsequent independent/external findings and their fix evidence
are mapped in stage-2-review.md. External round 1 has no BLOCKER; five confirmed
IMPORTANT findings are fixed without re-run under AGENTS.md §7.2. No marker from
a second external run is claimed. No open BLOCKER/IMPORTANT remains.

Limits: offline fixtures validate the verifier and integration, not semantic
extraction recall or live model accuracy. All 41 source cases are partial facts,
not full expected model outputs. No approved live run occurred. Pilot quality,
latency/cost, candidate recall and critical scenario extraction by the actual
model remain unverified. Multi-item paid/date interpretations are deliberately
conservative and may request clarification; Symfony owns required Cash fields,
current permissions, archive/period checks and final user confirmation.

Review fixes bind values to one complete source occurrence and punctuation clause;
shared quotes must admit distinct monetary occurrences. Multiple unresolved dates
or conflicting directions within a clause require null plus a question. Common
context across clause boundaries stays unknown until clarified. See stage-2-review.md.
