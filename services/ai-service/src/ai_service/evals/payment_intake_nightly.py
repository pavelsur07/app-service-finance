"""Durable, independently scored model canary; no automatic business changes."""

from __future__ import annotations

import hashlib
import json
from collections.abc import Callable
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from decimal import ROUND_CEILING, Decimal
from pathlib import Path
from typing import TYPE_CHECKING, Any
from uuid import uuid4

import yaml

from ai_service.evals import payment_intake as runner
from ai_service.evals.payment_intake_assets import load_pack
from ai_service.llm.routes import RoutingConfig
from ai_service.llm.types import LlmAdapter, LlmError, LlmRequest, LlmResponse
from ai_service.tasks.parse_payment_message.task import TASK_TYPE, build

if TYPE_CHECKING:
    from psycopg import Connection

    from ai_service.store.evals import EvalClaim, EvalStore

Prices = dict[str, tuple[Decimal, Decimal]]


def utcnow() -> datetime:
    return datetime.now(UTC)


def _hash(value: Any) -> str:
    return runner.digest(json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")))


def load_prices(path: Path) -> Prices:
    """BaseLoader keeps YAML decimal lexemes exact, including unquoted prices."""
    # BaseLoader constructs strings/collections only; unlike Loader it has no object constructors.
    try:
        raw = yaml.load(path.read_text(encoding="utf-8"), Loader=yaml.BaseLoader)  # noqa: S506
    except yaml.YAMLError:
        raise ValueError("invalid_eval_prices") from None
    if not isinstance(raw, dict):
        raise ValueError("invalid_eval_prices")
    prices: Prices = {}
    for model, values in raw.items():
        if not isinstance(model, str) or not isinstance(values, dict):
            raise ValueError("invalid_eval_prices")
        pair = (values.get("input"), values.get("output"))
        if any(value in (None, "null", "~", "") for value in pair):
            continue
        try:
            parsed = (Decimal(str(pair[0])), Decimal(str(pair[1])))
        except (TypeError, ValueError, ArithmeticError):
            raise ValueError("invalid_eval_prices") from None
        if any(not price.is_finite() or price <= 0 for price in parsed):
            raise ValueError("invalid_eval_prices")
        prices[model] = parsed
    return prices


def configuration_metadata(
    routing: RoutingConfig, prices: Prices, *, assets_root: Path | None = None
) -> dict[str, Any]:
    """One server/worker identity: verified pack, deployed code, prompt, route and prices."""
    pack = load_pack(assets_root)
    route = routing.routes[TASK_TYPE]
    package = Path(__file__).resolve().parents[1]
    code = {
        str(path.relative_to(package)): hashlib.sha256(path.read_bytes()).hexdigest()
        for path in sorted(package.rglob("*.py"))
    }
    models = []
    for model in route.models:
        provider = routing.providers[model.provider]
        models.append(
            {
                "model": model.key,
                "adapter_type": provider.type.value,
                "base_url_sha256": _hash(provider.base_url),
                "extra_body_sha256": _hash(provider.extra_body),
            }
        )
    return {
        "pack": pack["manifest"],
        "runtime_code_sha256": _hash(code),
        "runtime_files": code,
        "prompt_sha256": runner.digest(build().prompt.text),
        "route": {"task": TASK_TYPE, "mode": route.mode.value, "models": models},
        "prices": {model: [str(pair[0]), str(pair[1])] for model, pair in sorted(prices.items())},
        "price_sha256": _hash({model: [str(p) for p in pair] for model, pair in sorted(prices.items())}),
        "prices_are_estimates": True,
        "provider_weights_hash": None,
    }


def configuration_fingerprint(routing: RoutingConfig, prices: Prices, *, assets_root: Path | None = None) -> str:
    return _hash(configuration_metadata(routing, prices, assets_root=assets_root))


@dataclass(frozen=True, slots=True)
class EvalLimits:
    day_limit: Decimal = Decimal("30")
    month_limit: Decimal = Decimal("900")
    max_calls: int = 164
    max_model_calls: int = 82
    max_input_tokens: int = 65536
    max_output_tokens: int = 16000
    deadline_seconds: int = 900
    lease_seconds: int = 900

    def __post_init__(self) -> None:
        if (
            any(not p.is_finite() or p <= 0 for p in (self.day_limit, self.month_limit))
            or self.day_limit > 30
            or self.month_limit > 900
            or not 1 <= self.max_calls <= 164
            or not 1 <= self.max_model_calls <= 82
            or not 1 <= self.max_input_tokens <= 65536
            or not 1 <= self.max_output_tokens <= 16000
            or not 1 <= self.deadline_seconds <= 900
            or not 1 <= self.lease_seconds <= 900
        ):
            raise ValueError("invalid_eval_limits")


@dataclass(frozen=True, slots=True)
class EvalOutcome:
    status: str
    report: dict[str, Any]
    error_code: str | None = None


class DurableAdapter:
    """Reserve committed allowance before network; settle only provider-known usage."""

    def __init__(
        self,
        evaluator: NightlyEvaluator,
        claim: EvalClaim,
        delegate: LlmAdapter,
        model: str,
        prices: tuple[Decimal, Decimal],
        deadline: datetime,
    ) -> None:
        self.evaluator, self.claim, self.delegate = evaluator, claim, delegate
        self.model, self.prices, self.deadline = model, prices, deadline
        self.stop_reason: str | None = None
        self.calls = 0
        self.reserved = Decimal(0)
        self.known_cost = Decimal(0)

    def _stop(self, code: str) -> None:
        self.stop_reason = code
        raise LlmError(code, retryable=False, code=code)

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        context = self.evaluator
        now = context.clock()
        if self.stop_reason:
            self._stop(self.stop_reason)
        if now >= self.deadline:
            self._stop("eval_deadline")
        if not context.store.heartbeat(context.conn, self.claim, now=now, lease_seconds=context.limits.lease_seconds):
            self._stop("eval_lost_lease")
        reserve = (
            (context.limits.max_input_tokens * self.prices[0] + context.limits.max_output_tokens * self.prices[1])
            / Decimal(1000000)
        ).quantize(Decimal("0.00000001"), rounding=ROUND_CEILING)
        call_id = uuid4()
        if not context.store.reserve(
            context.conn,
            self.claim,
            call_id=call_id,
            model=self.model,
            amount=reserve,
            day_limit=context.limits.day_limit,
            month_limit=context.limits.month_limit,
            max_calls=context.limits.max_calls,
            max_model_calls=context.limits.max_model_calls,
            now=now,
        ):
            self._stop("eval_budget_limit")
        self.calls += 1
        self.reserved += reserve
        try:
            response = self.delegate.complete(model, request)
        except LlmError as error:
            # Gateway logs error codes; never forward arbitrary provider strings.
            self._stop("model_refusal" if error.code == "refusal" else "provider_error")
        # An unexpected adapter exception also retains its unknown reservation.
        if response.input_tokens < 0 or response.output_tokens < 0:
            self._stop("provider_invalid_usage")
        # Existing adapters default missing usage components to zero. A nonempty
        # completion necessarily consumed both input and output; zero is unknown,
        # and cannot authorize releasing a committed reservation.
        if response.input_tokens == 0 or response.output_tokens == 0:
            return response
        cost = (response.input_tokens * self.prices[0] + response.output_tokens * self.prices[1]) / Decimal(1000000)
        if not context.store.settle(
            context.conn,
            call_id,
            actual_cost=cost,
            input_tokens=response.input_tokens,
            output_tokens=response.output_tokens,
            now=context.clock(),
        ):
            self._stop("eval_settlement_failed")
        self.reserved -= reserve
        self.known_cost += cost
        if (
            response.input_tokens > context.limits.max_input_tokens
            or response.output_tokens > context.limits.max_output_tokens
        ):
            self.stop_reason = "provider_usage_exceeded_declared_maxima"
        return response


class NightlyEvaluator:
    def __init__(
        self,
        conn: Connection[Any],
        store: EvalStore,
        *,
        routing: RoutingConfig,
        adapters: dict[str, LlmAdapter],
        prices: Prices,
        limits: EvalLimits,
        assets_root: Path | None = None,
        clock: Callable[[], datetime] = utcnow,
    ) -> None:
        self.conn, self.store, self.routing = conn, store, routing
        self.adapters, self.prices, self.limits = adapters, prices, limits
        self.assets_root, self.clock = assets_root, clock

    def run(self, claim: EvalClaim) -> EvalOutcome:
        report: dict[str, Any] = {"network_calls": 0, "models": {}, "complete": False}
        try:
            pack = load_pack(self.assets_root)
            metadata = configuration_metadata(self.routing, self.prices, assets_root=self.assets_root)
        except (ValueError, OSError, KeyError):
            return EvalOutcome("incomplete", report, "invalid_runtime_pack")
        report["configuration"] = metadata
        fingerprint = _hash(metadata)
        report["configuration_fingerprint"] = fingerprint
        if claim.configuration_fingerprint != fingerprint:
            return EvalOutcome("incomplete", report, "configuration_changed")
        datasets = pack["datasets"]
        if not datasets or any(not cases for cases in datasets.values()):
            return EvalOutcome("incomplete", report, "empty_dataset")
        deadline = self.clock() + timedelta(seconds=self.limits.deadline_seconds)
        states: list[str] = []
        route = self.routing.routes[TASK_TYPE]
        for ref in route.models:
            model_report: dict[str, Any] = {"datasets": {}, "complete": False, "network_calls": 0}
            report["models"][ref.key] = model_report
            pair = self.prices.get(ref.key)
            provider = self.routing.providers[ref.provider]
            if (
                pair is None
                or any(not p.is_finite() or p <= 0 for p in pair)
                or not provider.available
                or ref.provider not in self.adapters
            ):
                model_report["status"] = "unavailable"
                model_report["error_code"] = "model_or_price_unavailable"
                model_report["uncovered"] = {name: len(cases) for name, cases in datasets.items()}
                states.append("unavailable")
                continue
            adapter = DurableAdapter(self, claim, self.adapters[ref.provider], ref.key, pair, deadline)
            model_state = "passed"
            for name, cases in datasets.items():
                if adapter.stop_reason:
                    break
                limits = runner.Limits(
                    self.limits.max_model_calls,
                    self.limits.max_input_tokens,
                    self.limits.max_output_tokens,
                    self.limits.day_limit,
                    pair[0],
                    pair[1],
                    settle_known_usage=True,
                    stop_on_provider_error=True,
                )
                previous_calls, previous_reserved, previous_known = adapter.calls, adapter.reserved, adapter.known_cost
                result = runner.evaluate(cases, adapter, ref.key, limits, dataset=name, assets_root=self.assets_root)
                # The shared runner also records gateway attempts denied by the
                # durable guard. Only the durable adapter knows which reached HTTP.
                result["gateway_attempts"] = result["network_calls"]
                result["network_calls"] = adapter.calls - previous_calls
                unsettled = adapter.reserved - previous_reserved
                known_cost = adapter.known_cost - previous_known
                result["unsettled_reservation_rub"] = str(unsettled)
                result["reserved_cost_rub_estimate"] = str(unsettled + known_cost)
                result["known_usage_cost_rub_estimate"] = str(known_cost)
                model_report["datasets"][name] = result
                reason = adapter.stop_reason or result["stop_reason"]
                if reason:
                    model_report["error_code"] = reason
                    model_state = (
                        "skipped_budget"
                        if reason in ("eval_budget_limit", "eval_call_limit")
                        else "failed"
                        if reason == "model_refusal"
                        else "incomplete"
                        if reason.startswith("eval_")
                        else "unavailable"
                    )
                    break
                if not result["complete"]:
                    model_state = "incomplete"
                    break
                metrics = [metric for metric in result["metrics"].values() if isinstance(metric, dict)]
                if (
                    not metrics
                    or not any(metric["denominator"] for metric in metrics)
                    or any(metric["numerator"] != metric["denominator"] for metric in metrics)
                    or any(row["verdict"] in ("failed", "rejected") for row in result["cases"])
                ):
                    model_state = "failed"
            covered = {
                name: sum(row["verdict"] != "failed" for row in result["cases"])
                for name, result in model_report["datasets"].items()
            }
            model_report.update(
                status=model_state,
                network_calls=adapter.calls,
                complete=all(covered.get(name, 0) == len(cases) for name, cases in datasets.items()),
                uncovered={name: len(cases) - covered.get(name, 0) for name, cases in datasets.items()},
                unsettled_reservation_rub=str(adapter.reserved),
                known_usage_cost_rub_estimate=str(adapter.known_cost),
            )
            states.append(model_state)
            report["network_calls"] += adapter.calls
        report["complete"] = bool(states) and all(model["complete"] for model in report["models"].values())
        report["budget"] = self.store.budget(self.conn, now=self.clock())
        # A quality failure must not conceal missing model/dataset coverage.
        status = next(
            (state for state in ("incomplete", "skipped_budget", "unavailable", "failed") if state in states), "passed"
        )
        if not states:
            return EvalOutcome("incomplete", report, "empty_route")
        return EvalOutcome(status, report, None if status == "passed" else "eval_" + status)
