# Stage 1 report

Specification: stage-1-spec.md. Base 8a1f51cac4dacac4d49dd6b5cf72e0940d26a895.
Standalone strict schema published with payload/output examples. Existing
ai-task/v1 generic payload/output supports new task type unchanged. All 41
source records read separately; corpus-review.jsonl records monetary facts,
non-money spans, questions and forbidden inferences. Existing partial annotations
remain constraints, not full extraction truth. Families keep event-list siblings
together; prompt contains no corpus example or saved financial data.

Baseline make ai-service-test: 192 passed, 1 skipped, 7 deselected; two existing
Sentry deprecation warnings. New schema test first failed (missing new contract);
path root corrected to services; new schema examples/invalid cases then verified.
Conservative unresolved business decisions become runtime questions per spec.
No financial defaults or production/live calls added. Coordinator owns Stage
commit/review gate and PHP contract tests. Internal review: 0 BLOCKER, 0 IMPORTANT.
