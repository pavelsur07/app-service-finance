"""Metadata-only by default; explicit, serial, bounded payment holdout execution."""

import argparse
import hashlib
import json
import os
import re
import time
from dataclasses import dataclass, replace
from decimal import Decimal
from pathlib import Path
from typing import Any, Literal

import yaml
from pydantic import ValidationError

from ai_service.core.pipeline import SCHEMA_CHECK, Pipeline, PipelineOutcome, _strip_fences
from ai_service.evals import payment_intake_semantics as semantics
from ai_service.evals.compare import EVAL_COMPANY
from ai_service.llm.adapters import build_adapters
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import assert_via_gateway, parse_routing
from ai_service.llm.types import LlmAdapter, LlmError, LlmRequest, LlmResponse, LlmUnavailableError, ModelRef
from ai_service.tasks.parse_payment_message.checks import normalize_money
from ai_service.tasks.parse_payment_message.schemas import PaymentItem, PaymentOutput
from ai_service.tasks.parse_payment_message.task import TASK_TYPE, build
from ai_service.tasks.registry import build_registry

Dataset = Literal["source", "semantic"]

ROOT = Path(__file__).resolve().parents[3]
CORPUS = ROOT.parents[1] / "docs/tasks/telegram-payment-intake/examples/messages.jsonl"
REVIEW = ROOT / "evals/parse_payment_message/corpus-review.jsonl"


def digest(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def load_holdout() -> list[dict[str, Any]]:
    cases: list[dict[str, Any]] = [json.loads(line) for line in CORPUS.read_text().splitlines() if line]
    families = {c["id"]: c["family"] for c in map(json.loads, REVIEW.read_text().splitlines())}
    if len(cases) != 41 or len({c["id"] for c in cases}) != 41 or set(families) != {c["id"] for c in cases}:
        raise ValueError("invalid_holdout")
    for case in cases:
        if not re.fullmatch(r"tg-[0-9]{3}", case["id"]) or not re.fullmatch(
            r"[a-z][a-z_-]{0,63}", families[case["id"]]
        ):
            raise ValueError("invalid_holdout")
        case["family"] = families[case["id"]]
    return cases


def manifest(cases: list[dict[str, Any]], dataset: Dataset = "source") -> dict[str, Any]:
    families: dict[str, list[str]] = {}
    for case in cases:
        families.setdefault(case["family"], []).append(case["id"])
    spec = build()
    package = Path(__file__).resolve().parents[1]
    task = package / "tasks/parse_payment_message"
    return {
        "N": len(cases),
        "families": families,
        "dataset": dataset,
        "corpus_sha256": digest((CORPUS if dataset == "source" else semantics.SEMANTIC_CORPUS).read_text()),
        "review_sha256": digest(REVIEW.read_text()) if dataset == "source" else None,
        "prompt": spec.prompt.id,
        "prompt_sha256": digest(spec.prompt.text),
        "runner_sha256": digest(Path(__file__).read_text()),
        "pipeline_sha256": digest((package / "core/pipeline.py").read_text()),
        "task_spec_sha256": digest((task / "task.py").read_text()),
        "verification_sha256": digest((task / "checks.py").read_text()),
        "preparation_sha256": digest((task / "preparation.py").read_text()),
        "semantic_scorer_sha256": digest(Path(semantics.__file__).read_text()) if dataset == "semantic" else None,
        "gold": "partial_annotations" if dataset == "source" else "synthetic_explicit_choices",
        "schema_sha256": digest((ROOT.parent / "_contracts/ai-parse-payment-message/v1/schema.json").read_text()),
    }


@dataclass(frozen=True, slots=True)
class Limits:
    max_calls: int
    max_input_tokens: int
    max_output_tokens: int
    budget_rub: Decimal
    input_price: Decimal
    output_price: Decimal

    def __post_init__(self) -> None:
        if not 1 <= self.max_calls <= 82 or self.max_input_tokens < 1 or not 1 <= self.max_output_tokens <= 16000:
            raise ValueError("invalid_limits")
        if any(not n.is_finite() or n < 0 for n in (self.budget_rub, self.input_price, self.output_price)):
            raise ValueError("invalid_prices")
        if self.budget_rub <= 0:
            raise ValueError("invalid_budget")

    @property
    def reservation(self) -> Decimal:
        return (self.max_input_tokens * self.input_price + self.max_output_tokens * self.output_price) / Decimal(
            1000000
        )


class BoundedAdapter:
    def __init__(self, delegate: LlmAdapter, limits: Limits, configured_model: str) -> None:
        self.delegate, self.limits = delegate, limits
        self.configured_model = configured_model
        self.calls: list[dict[str, Any]] = []
        self.reserved = Decimal(0)
        self.stop_reason: str | None = None

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        if self.stop_reason:
            raise LlmError(self.stop_reason, retryable=False, code=self.stop_reason)
        # UTF-8 bytes + framing allowance is a conservative guard, NOT a tokenizer/billing proof.
        reason = None
        if len(self.calls) >= self.limits.max_calls:
            reason = "eval_call_limit"
        elif len(request.system.encode()) + len(request.user.encode()) + 512 > self.limits.max_input_tokens:
            reason = "eval_input_limit"
        elif self.reserved + self.limits.reservation > self.limits.budget_rub:
            reason = "eval_budget_limit"
        if reason:
            self.stop_reason = reason
            raise LlmError(reason, retryable=False, code=reason)
        self.reserved += self.limits.reservation
        call: dict[str, Any] = {
            "prompt_sha256": digest(request.system),
            "response_sha256": None,
            "adapter_model": None,
            "adapter_model_sha256": None,
            "input_tokens": 0,
            "output_tokens": 0,
            "latency_ms": 0,
            "error": None,
        }
        self.calls.append(call)
        started = time.monotonic()
        try:
            response = self.delegate.complete(
                model, replace(request, max_tokens=min(request.max_tokens, self.limits.max_output_tokens))
            )
        except LlmError as exc:
            call["error"] = exc.code if re.fullmatch(r"[a-z0-9_]{1,64}", exc.code) else "provider_error"
            raise
        finally:
            call["latency_ms"] = int((time.monotonic() - started) * 1000)
        call.update(
            adapter_model=self.configured_model,
            adapter_model_sha256=digest(self.configured_model),
            response_model_sha256=digest(response.model),
            response_model_matches_configured_alias=response.model == self.configured_model,
            response_sha256=digest(response.text),
            input_tokens=response.input_tokens,
            output_tokens=response.output_tokens,
        )
        if (
            response.input_tokens > self.limits.max_input_tokens
            or response.output_tokens > self.limits.max_output_tokens
        ):
            self.stop_reason = "provider_usage_exceeded_declared_maxima"
        return response


def _payload(case: dict[str, Any], dataset: Dataset = "source") -> dict[str, Any]:
    if dataset == "semantic":
        return dict(case["payload"])
    context = case["context"]
    return {
        "text": case["text"],
        "reference_at": context["reference_at"],
        "timezone": context["timezone"],
        "currency": context["currency"],
        "categories": [],
        "projects": [],
        "cfos": [],
        "allowed_pairs": [],
        "draft_items": [],
    }


def _score(
    cases: list[dict[str, Any]], answers: dict[str, dict[str, Any]], dataset: Dataset = "source"
) -> dict[str, Any]:
    if dataset == "semantic":
        return semantics.score(cases, answers)
    scores = {
        name: {"numerator": 0, "denominator": 0}
        for name in (
            "verified_candidate_recall",
            "literal_amount_agreement",
            "explicit_direction_agreement",
            "scale_abstention",
            "correction_intent_agreement",
            "marked_question_presence",
            "payment_intent_agreement",
        )
    }
    for case in cases:
        gold, answer = case["annotations"], answers.get(case["id"], {})
        items = answer.get("items", [])
        questions = bool(answer.get("questions") or any(item["questions"] for item in items))
        if any(mention["role"] == "candidate_amount" for mention in gold["money_mentions"]):
            scores["payment_intent_agreement"]["denominator"] += 1
            scores["payment_intent_agreement"]["numerator"] += int(answer.get("intent") == "payments")
        if answer.get("intent") != "payments":
            items = []
        if gold.get("clarification_topics"):
            scores["marked_question_presence"]["denominator"] += 1
            scores["marked_question_presence"]["numerator"] += int(questions)
        if gold.get("must_not_create_new_payment"):
            scores["correction_intent_agreement"]["denominator"] += 1
            scores["correction_intent_agreement"]["numerator"] += int(answer.get("intent") == "correction")
        used: set[int] = set()
        for mention in gold["money_mentions"]:
            if mention["role"] != "candidate_amount":
                continue
            expected = mention["literal_amount"]
            match = next(
                (
                    n
                    for n, item in enumerate(items)
                    if n not in used
                    and normalize_money(item["amount_evidence"] or item["text_evidence"]) == expected
                    and (
                        mention["surface"] in item["text_evidence"]
                        or (item["amount_evidence"] or item["text_evidence"]) in mention["surface"]
                    )
                ),
                None,
            )
            item = items[match] if match is not None else None
            if match is not None:
                used.add(match)
            scores["verified_candidate_recall"]["denominator"] += 1
            scores["verified_candidate_recall"]["numerator"] += int(item is not None)
            ambiguous_scale = "amount_scale" in gold["clarification_topics"]
            target = "scale_abstention" if ambiguous_scale else "literal_amount_agreement"
            scores[target]["denominator"] += 1
            correct = item is not None and (
                (item["amount"] is None and questions) if ambiguous_scale else item["amount"] == expected
            )
            scores[target]["numerator"] += int(correct)
            if "explicit_direction" in gold:
                scores["explicit_direction_agreement"]["denominator"] += 1
                scores["explicit_direction_agreement"]["numerator"] += int(
                    item is not None and item["direction"] == gold["explicit_direction"]
                )
    return scores | {
        "overall_accuracy": None,
        "precision": None,
        "analytics_accuracy": None,
        "financial_date_accuracy": None,
        "question_quality": None,
    }


def _diagnostics(outcome: PipelineOutcome | None) -> dict[str, Any]:
    failed: dict[str, list[str]] = {}
    schema_errors: list[dict[str, str]] = []
    if outcome is not None:
        for check in outcome.checks:
            if check.passed:
                continue
            if check.name == SCHEMA_CHECK:
                failed[SCHEMA_CHECK] = ["schema_validation_failed"]
                responses = outcome.trace.get("responses", [])
                text = responses[-1].get("text") if responses and isinstance(responses[-1], dict) else None
                if isinstance(text, str):
                    try:
                        PaymentOutput.model_validate_json(_strip_fences(text))
                    except ValidationError as error:
                        fields = PaymentOutput.model_fields.keys() | PaymentItem.model_fields.keys()
                        for detail in error.errors(include_input=False, include_context=False, include_url=False):
                            parts = [
                                "[]" if isinstance(part, int) else part if part in fields else "extra_field"
                                for part in detail["loc"]
                            ]
                            kind = detail["type"]
                            schema_errors.append(
                                {
                                    "path": ".".join(parts),
                                    "type": kind if re.fullmatch(r"[a-z_]{1,64}", kind) else "validation_error",
                                }
                            )
            elif check.name == "payment_evidence_and_context":
                # This task's verifier emits static comma-separated machine codes, never model text.
                codes = check.details.split(",")
                failed[check.name] = (
                    codes if all(re.fullmatch(r"[a-z][a-z0-9_]{0,63}", c) for c in codes) else ["check_failed"]
                )
            else:
                failed["other"] = ["check_failed"]
    output = outcome.output if outcome is not None else None
    items = output["items"] if output is not None else None
    return {
        "intent": output["intent"] if output is not None else None,
        "item_count": len(items) if items is not None else None,
        "amount_null_count": sum(item["amount"] is None for item in items) if items is not None else None,
        "direction_null_count": sum(item["direction"] is None for item in items) if items is not None else None,
        "failed_checks": failed,
        "schema_errors": schema_errors,
        "output_prepared": outcome is not None and outcome.trace.get("output_prepared") is True,
        "output_prepare_failed": outcome is not None and outcome.trace.get("output_prepare_failed") is True,
        "error_code": outcome.error_code if outcome is not None else "provider_unavailable",
    }


def evaluate(
    cases: list[dict[str, Any]], adapter: LlmAdapter, model: str, limits: Limits, *, dataset: Dataset = "source"
) -> dict[str, Any]:
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.:/-]{1,128}", model):
        raise ValueError("invalid_model_alias")
    ref = ModelRef.parse(model)
    routing = parse_routing(
        {"providers": {ref.provider: {"type": "stub"}}, "tasks": {TASK_TYPE: {"mode": "single", "models": [model]}}}, {}
    )
    bounded = BoundedAdapter(adapter, limits, model)
    pipeline = Pipeline(build_registry(), LlmGateway(routing, {ref.provider: bounded}, retries_per_model=0))
    answers, rows = {}, []
    for case in cases:
        if bounded.stop_reason:
            break
        started = time.monotonic()
        outcome: PipelineOutcome | None = None
        try:
            completed = pipeline.run(TASK_TYPE, EVAL_COMPANY, _payload(case, dataset))
        except LlmUnavailableError:
            verdict, output = "failed", None
        else:
            outcome = completed
            verdict, output = completed.verdict.value, completed.output
        if output is not None:
            answers[case["id"]] = output
        rows.append(
            {
                "id": case["id"],
                "family": case["family"],
                "verdict": verdict,
                "latency_ms": int((time.monotonic() - started) * 1000),
                "diagnostics": _diagnostics(outcome),
                "metrics": _score([case], answers, dataset),
            }
        )
    input_tokens = sum(c["input_tokens"] for c in bounded.calls)
    output_tokens = sum(c["output_tokens"] for c in bounded.calls)
    return {
        "mode": "executed",
        "manifest": manifest(cases, dataset),
        "network_calls": len(bounded.calls),
        "complete": len(rows) == len(cases) and bounded.stop_reason is None,
        "stop_reason": bounded.stop_reason,
        "cases": rows,
        "calls": bounded.calls,
        "metrics": _score(cases, answers, dataset),
        "reserved_cost_rub_estimate": str(bounded.reserved),
        "known_usage_cost_rub_estimate": str(
            (input_tokens * limits.input_price + output_tokens * limits.output_price) / Decimal(1000000)
        ),
        "input_tokens": input_tokens,
        "output_tokens": output_tokens,
        "limits": {
            "max_calls": limits.max_calls,
            "max_input_tokens": limits.max_input_tokens,
            "max_output_tokens": limits.max_output_tokens,
            "budget_rub_estimate": str(limits.budget_rub),
        },
        "requested_model": model,
        "requested_model_sha256": digest(model),
        "usage_may_be_missing_for_failures": True,
        "cost_is_hard_billing_guarantee": False,
        "provider_weights_hash": None,
        "model_identity_source": "existing_adapter_alias_not_provider_weight_identity",
    }


def _main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--dataset", choices=("source", "semantic"), default="source")
    parser.add_argument("--case-id", action="append")
    parser.add_argument("--routes", type=Path)
    parser.add_argument("--model")
    parser.add_argument("--gateway-url")
    parser.add_argument("--max-calls", type=int)
    parser.add_argument("--max-input-tokens", type=int)
    parser.add_argument("--max-output-tokens", type=int)
    parser.add_argument("--budget-rub", type=Decimal)
    parser.add_argument("--input-price", type=Decimal)
    parser.add_argument("--output-price", type=Decimal)
    parser.add_argument("--timeout", type=float, default=30)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args(argv)
    dataset: Dataset = args.dataset
    cases = load_holdout() if dataset == "source" else semantics.load_cases()
    if args.case_id is not None:
        by_id = {case["id"]: case for case in cases}
        if len(set(args.case_id)) != len(args.case_id) or any(case_id not in by_id for case_id in args.case_id):
            print(json.dumps({"error": "invalid_case_selection"}))  # noqa: T201
            return 2
        cases = [by_id[case_id] for case_id in args.case_id]
    if not args.execute:
        report = {
            "mode": "offline_manifest",
            "manifest": manifest(cases, dataset),
            "network_calls": 0,
            "live_approval": "pending_provider_model_budget",
            "metrics": None,
        }
    elif any(
        value is None
        for value in (
            args.routes,
            args.model,
            args.max_calls,
            args.max_input_tokens,
            args.max_output_tokens,
            args.budget_rub,
            args.input_price,
            args.output_price,
        )
    ):
        print(json.dumps({"error": "explicit_limits_and_route_required"}))  # noqa: T201
        return 2
    else:
        try:
            if not 0 < args.timeout <= 120:
                raise ValueError("invalid_timeout")
            limits = Limits(
                args.max_calls,
                args.max_input_tokens,
                args.max_output_tokens,
                args.budget_rub,
                args.input_price,
                args.output_price,
            )
            ref = ModelRef.parse(args.model)
            raw = yaml.safe_load(args.routes.read_text())
            provider = dict(raw["providers"][ref.provider])
            provider["api_key_env"] = "PAYMENT_INTAKE_EVAL_TOKEN"
            provider["folder_id_env"] = "PAYMENT_INTAKE_EVAL_FOLDER"
            if provider["type"] != "stub" and (not args.gateway_url or not os.environ.get("PAYMENT_INTAKE_EVAL_TOKEN")):
                raise ValueError("local_test_credential_and_gateway_required")
            routing = parse_routing(
                {
                    "providers": {ref.provider: provider},
                    "tasks": {TASK_TYPE: {"mode": "single", "models": [args.model]}},
                },
                {
                    "PAYMENT_INTAKE_EVAL_TOKEN": os.environ.get("PAYMENT_INTAKE_EVAL_TOKEN", ""),
                    "PAYMENT_INTAKE_EVAL_FOLDER": os.environ.get("PAYMENT_INTAKE_EVAL_FOLDER", ""),
                },
            )
            assert_via_gateway(routing, args.gateway_url)
            report = evaluate(
                cases, build_adapters(routing, args.timeout)[ref.provider], args.model, limits, dataset=dataset
            )
            report["route_sha256"] = digest(
                json.dumps(
                    {
                        "provider": ref.provider,
                        "type": provider["type"],
                        "base_url": provider.get("base_url"),
                        "extra_body": provider.get("extra_body"),
                    },
                    sort_keys=True,
                )
            )
        except (KeyError, OSError, ValueError):
            print(json.dumps({"error": "invalid_configuration_or_missing_test_credential"}))  # noqa: T201
            return 2
    encoded = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        args.output.write_text(encoded)
    print(encoded, end="")  # noqa: T201
    return 0


def main(argv: list[str] | None = None) -> int:
    try:
        return _main(argv)
    except Exception as exc:  # noqa: BLE001 — CLI must not expose SDK/config payloads or credential traces.
        print(json.dumps({"error": "runner_error", "error_class": type(exc).__name__}))  # noqa: T201
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
