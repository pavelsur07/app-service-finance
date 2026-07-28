## Stage 1: Read-only WB loaded-data financial control report

Risk: HIGH-LOCAL
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: b1a49db20b1f3d907b914703f3931cd39875787d

Definition of Done:
- Authenticated users can open a tenant-scoped WB report for a selected date range and optional `reportId`.
- The report reads only completed raw WB documents linked from daily sync statuses and never reads or writes `ingest_financial_transactions`.
- Monetary aggregation uses exact `Money` arithmetic, exposes the source field/formula, and separates sales, returns, deductions, compensations, and the calculated payout.
- The page shows coverage/loading status and data-quality warnings, including duplicate or missing `rrdId`, missing `reportId`, invalid money values, and row-count mismatches.
- Summary data can be downloaded as CSV with the same filters and calculations as the page.
- Unit and functional tests cover calculations, input validation, tenant isolation, and the no-data state.
- Relevant PHP, Twig, container, and test checks pass; internal and external reviews are green.
- No schema, migration, queue, sync, external API, transaction, or production behavior is changed.

Work items:
- 1.1 — Add the tenant-scoped raw-document query and exact report builder.
- 1.2 — Add the GET report/CSV controller, Twig report page, and marketplace navigation entry.
- 1.3 — Add unit/functional coverage and concise report documentation.

Stage checks:
- Targeted PHPUnit tests for the report builder and controller.
- Existing WB sync-status controller regression test.
- Symfony container and Twig lint.
- PHP CS check for the complete Stage diff.
- Internal review of the complete Stage diff from the recorded base.
- Read-only Claude Code review ending with `REVIEW_GREEN`.

Reviewer focus:
- No dependency on or mutation of the transaction model.
- Company isolation in every query path.
- Exact decimal parsing, sign handling, and no double subtraction from `forPay`.
- Stable handling of malformed/partial raw rows and bounded date ranges.
- CSV escaping and formula-injection safety.

Release Gate:
- Owner decision after checks, both reviews, commit, push, known CI status when available, and Draft PR update.

Production Gate:
- Merge, release, deployment, production checks, and production mutations are excluded and require separate explicit approval.
