# ai-eval-run/v1

Internal technical quality-run API. It does not submit business AI tasks and
never accepts company IDs, payment messages, models, prices, limits or paths.
Authentication uses the existing Symfony → ai-service Bearer token.

- `POST /internal/eval-runs`: `EvalRunRequest`, `Idempotency-Key` equals body
  `idempotency_key`; returns 202 `EvalAccepted`.
- `GET /internal/eval-runs/latest[?suite=payment_intake]`: `EvalLatest`, including the currently
  deployed configuration fingerprint and the latest retained run, if any.
  Unsupported query suites are rejected with422.
- `GET /internal/eval-runs/{run_id}`: `EvalStatus`; unknown run returns404.

The supported suite is `payment_intake`. Enqueue date is the current calendar
date in Europe/Moscow. One suite/date has one canonical idempotency key.
The same key and configuration with a new run UUID returns the original run;
a changed key/content/configuration for an occupied date returns409. The
service computes the configuration fingerprint, not the caller.

States: queued, running, passed, failed, unavailable, incomplete,
skipped_budget. Pending states expose no result (`report:null`). Terminal
reports contain metadata, metrics, coverage and estimated model resource
costs; no raw inputs/outputs, payment values or credentials.

`passed` requires all declared model/dataset coverage and all annotated
expectations to match. An incomplete, unavailable or skipped run is never
equivalent to a successful check. A previous run's fingerprint need not equal
the current deployed fingerprint after a rollout; callers must preserve both.

The technical run and budget ledger are independent of company AI access and
usage. The current owner-approved caps are30RUB/day and900RUB/month for the
whole job scope, with at most164 calls/job and82/model. Pricing is an estimate
from the pinned server configuration, not proof of the provider invoice.
Unknown usage retains its reservation. No retries replay an expired paid run.

Feature disabled returns503 for enqueue/status; latest reports enabled=false
without reading assets. Invalid requests return422; configuration unavailable
returns503 without leaking exception content. No endpoint triggers a live LLM
request synchronously: the dedicated eval-worker consumes the durable queue.

Published schema definitions are in `schema.json`. The `ai-task/v1` business
contract remains unchanged. This contract is not a public browser endpoint.
