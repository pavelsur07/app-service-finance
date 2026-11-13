# Reviewable live-eval action (not executed)

The runner is `python -m ai_service.evals.payment_intake`. It uses the existing
TaskSpec, gateway, masking and Pipeline; it does not boot Symfony, open a database,
write payments or read production credentials. Its default is an offline manifest
of all 41 anonymized source cases, grouped by existing families. All families stay
in holdout; no source example is injected into the prompt.

```bash
docker compose --profile services run --rm services-ai-service-dev \
  python -m ai_service.evals.payment_intake \
  --output evals/parse_payment_message/holdout-manifest.json
```

This command makes zero network calls even if credentials exist. The delivered
manifest records source/review/schema/prompt/runner hashes and IDs, with no text.
To inspect integration offline, explicitly choose `stub/payment-intake`,
`config/routes.dev.yaml`, positive call/token/budget limits and zero token prices.
Stub output proves wiring and limits, not recognition quality.

## Approval inputs

A live run requires a separately named approval covering provider/model, gateway,
41 anonymized cases, at most 82 calls, declared input/output maxima, timeout and
budget estimate. Neither model nor budget is selected by the current implementation.
The token must be a separately provisioned **local test credential** in
`PAYMENT_INTAKE_EVAL_TOKEN`; the runner never uses `AI_GATEWAY_TOKEN` or service
Settings. Pass the variable by name, without exposing its value in command/chat.
Availability or permissions of such a token have not been assumed or checked.

One concrete option for the owner's approval is `deepseek/deepseek-flash` through
the existing gateway, 82 calls maximum, 65536 declared input tokens and 16000
output tokens per call, 60-second timeout, budget estimate 300 RUB. At the archived
rates in config/prices.yaml (24.17/96.67 RUB per million input/output tokens), the
configured reservation ceiling for 82 calls is 256.72 RUB. These are recorded
31 October rates, **not a newly verified quote**; the owner must approve rates
or supply corrected values. Current tariff verification and test-credential
provisioning remain prerequisites after approval; neither has been performed. The alternative existing `openai/gpt-4.1-mini` route
requires its own explicit choice and price/budget approval.

Only after that named approval and test-token provisioning:

```bash
docker compose --profile services run --rm -e PAYMENT_INTAKE_EVAL_TOKEN \
  services-ai-service-dev python -m ai_service.evals.payment_intake \
  --execute --routes config/routes.yaml --model deepseek/deepseek-flash \
  --gateway-url https://ai.vashfindir.ru \
  --max-calls 82 --max-input-tokens 65536 --max-output-tokens 16000 \
  --timeout 60 --budget-rub 300 --input-price 24.17 --output-price 96.67 \
  --output /repo/site/var/payment-intake-live-eval.json
```

No fallback provider or gateway retry runs. The selected DeepSeek route uses
OpenAiCompatibleAdapter and one _http.post_json/client.post per delegation,
without an SDK retry loop. The existing Anthropic adapter also sets max_retries=0. Pipeline's existing second schema
attempt counts against the same cap. Before each call the runner reserves the
configured input/output maxima, including failed calls; reservation is not refunded.
The call count and transmitted output-token cap are enforced. The input guard
uses UTF-8 request bytes plus framing allowance; it is **not an exact tokenizer**.
Unknown provider accounting, hidden input overhead or incorrect declared prices
can exceed the estimate. The report explicitly marks cost as **not a hard provider
billing guarantee**; gateway/account-side enforcement is needed for a monetary
hard ceiling. A returned usage count exceeding declared maxima stops further calls.

## What the report establishes

The report contains only IDs/families, stable errors/verdicts, counters,
response/prompt/model-alias hashes, token usage, measured latency and Decimal cost
estimates. No source text, response text, trace, questions, descriptions or token
value is persisted. Failed/rejected cases are missing **verified** candidates;
this is not a raw pre-verification extraction recall claim.

Partial labels support annotated monetary-mention coverage, literal amounts where
scale is unambiguous, explicitly labelled directions, correction intent, structural
question presence and scale abstention. They do not establish overall accuracy,
precision, financial date accuracy, analytics accuracy or question quality; these
fields remain null. Unannotated extra predictions cannot be called false positives.
Repeated event fragments are grouped, not treated as independent training examples.

Existing adapters discard provider-returned model IDs and expose only their
configured alias. The report prints that safe configured alias, hashes the actual
LlmResponse model field and response bytes, and states this provenance limitation.
`provider_weights_hash` remains null; no alias hash is represented as a hash of
model weights. Missing usage on provider failures can make known-usage cost partial.
Live model quality and pilot readiness remain pending until the approved run and
its separately agreed acceptance criteria have been reviewed.
