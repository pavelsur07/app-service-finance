### Stage 7.1: Read-only default-project and data-shape preflight — DONE

**Risk:** LOW locally; production access is read-only
**Next action:** STOP; owner review and explicit approval required before creating the HIGH-risk Stage 7.2 migration

#### What was done

- Prepared one read-only SQL preflight for default-project candidates, fact coverage, and internal-transfer coverage.
- Anonymized exceptional companies with an eight-character hash; company names, transaction data, descriptions, and financial values are not selected.
- Wrapped the SQL in `BEGIN TRANSACTION READ ONLY` with a 30-second statement timeout and an explicit `ROLLBACK`.
- Validated the complete script against the current local test schema.
- Ran production access only through `vf-prod-codex` and the approved `codex-psql-ro` / `codex-docker-ps` wrappers.
- Refined the report to separate completely empty companies from companies with projects or financial facts, avoiding a 1333-row exception dump.
- Identified the exact additive migration cohorts without selecting company names, transaction data, descriptions, or financial values.

#### Files changed

- `docs/reviews/cash-auto-rules-stage-7-plan.md` — Stage 7.1 result and proposed Stage 7.2 migration cohorts.
- `docs/reviews/cash-auto-rules-stage-7-preflight.sql` — reviewed read-only production preflight.
- `docs/reviews/cash-auto-rules-stage-7-report.md` — completed preflight results and migration-review focus.

#### Self-review

- [x] Scope compliance
- [x] Project patterns followed
- [x] No forbidden actions
- [x] Security/company access checked
- [x] Local and production read-only SQL validation run
- [x] Documentation updated

No application, schema, company, project, transaction, document, P&L aggregate, queue, or production configuration was changed.

#### Checks

- `docker compose exec -T site-postgres psql ... -d app_test` — OK; all statements completed and rolled back.
- Restricted production `codex-docker-ps` — OK; application containers reported healthy.
- Restricted production `codex-psql-ro` — OK; all statements completed inside a read-only transaction and rolled back.

#### Production result

- 1343 companies total.
- 1332 companies are empty: no projects, active Cash facts, documents, or P&L totals.
- 11 companies are initialized.
- 10 initialized companies have exactly one recognizable system-project candidate: 2 named `Общий`, 8 named `Основной`.
- No company has multiple recognizable candidates.
- One initialized company has no project records and 2751 active Cash facts. It is referenced only by an eight-character hash in the raw output.
- Active Cash facts: 4166 total, 3290 without project, across 4 companies.
- Documents: 421 total, 10 without project, across 3 companies.
- Document operations: 758 total, 40 without project, across 3 companies.
- P&L daily totals: 433 total, none without project, across 3 companies.
- Active internal transfers: 138 total, 136 without project.

#### Proposed Stage 7.2 migration policy

- Assign stable code `PROJECT_GENERAL` only to the 10 unambiguous existing candidates.
- Create a new `PROJECT_GENERAL` for the 1332 empty companies.
- Create a new `PROJECT_GENERAL` for the one initialized company that has no project records; do not assign that project to its 2751 existing Cash facts.
- Create exactly one `CFO_GENERAL` and one allowed system pair per company.
- Do not update Cash, documents, document operations, P&L totals, internal transfers, or report aggregates.
- Abort the migration on any unexpected candidate ambiguity or uniqueness conflict.

This policy is additive and avoids guessing historical classification, but migration creation remains a mandatory HIGH-risk STOP point.

#### Risks / reviewer focus

- Confirm that the initialized company with zero projects should receive a new unused `PROJECT_GENERAL` while its existing 2751 Cash facts remain unchanged.
- Confirm that all 1332 empty legacy companies should receive the same system bootstrap records as new companies.

#### Open questions

- none beyond the required owner approval above
