"""Real durable ledger, pipeline and scoring; only the paid provider boundary is replaced."""

import json
from collections import Counter
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any
from uuid import uuid4

import psycopg
import pytest
from psycopg.pq import TransactionStatus

from ai_service.core.prompt import extract_data_block
from ai_service.evals.payment_intake_assets import load_pack
from ai_service.evals.payment_intake_nightly import (
    EvalLimits,
    NightlyEvaluator,
    configuration_fingerprint,
)
from ai_service.evals.worker import EvalWorker
from ai_service.llm.routes import RoutingConfig, parse_routing
from ai_service.llm.types import LlmError, LlmRequest, LlmResponse
from ai_service.store.evals import EvalClaim, EvalStore
from ai_service.tasks.parse_payment_message.checks import _currency
from tests.unit.test_payment_intake_semantics import answer

pytestmark = pytest.mark.integration
NOW = datetime(2026, 11, 14, 1, tzinfo=UTC)


def routes() -> RoutingConfig:
    return parse_routing(
        {
            "providers": {"stub": {"type": "stub"}},
            "tasks": {"parse_payment_message": {"models": ["stub/primary", "stub/fallback"]}},
        },
        {},
    )


def prices(value: str = "1") -> dict[str, tuple[Decimal, Decimal]]:
    return {model: (Decimal(value), Decimal(value)) for model in ("stub/primary", "stub/fallback")}


def enqueue(
    conn: psycopg.Connection[Any], store: EvalStore, routing: RoutingConfig, pricing: dict[str, tuple[Decimal, Decimal]]
) -> EvalClaim:
    run_id = uuid4()
    store.enqueue(
        conn,
        run_id=run_id,
        suite="payment_intake",
        run_date=NOW.date(),
        trace_id=uuid4(),
        idempotency_key=str(run_id),
        configuration_fingerprint=configuration_fingerprint(routing, pricing),
        now=NOW,
    )
    claimed = store.claim(conn, now=NOW, lease_seconds=900)
    assert claimed is not None
    return claimed


class Replies:
    def __init__(self, conn: psycopg.Connection[Any], *, broken: bool = False, unknown: bool = False) -> None:
        self.conn, self.broken, self.unknown = conn, broken, unknown
        self.calls: list[str] = []
        pack = load_pack()
        self.cases = {
            case["payload"]["text"]: case
            for name, cases in pack["datasets"].items()
            if name != "source"
            for case in cases
        }
        self.source = {case["text"]: case for case in pack["datasets"]["source"]}

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        assert self.conn.info.transaction_status is TransactionStatus.IDLE
        # Verify the reservation is committed and visible before the network boundary.
        assert self.conn.execute("SELECT count(*) FROM ai_eval_call").fetchone()[0] == len(self.calls) + 1
        self.calls.append(model)
        if self.broken:
            raise LlmError("sensitive-error", retryable=True, code="sensitive-provider-code")
        text = json.loads(extract_data_block(request.user))["text"]
        if text in self.cases:
            result = answer(self.cases[text])
        else:
            # Valid abstention intentionally misses positive source gold.
            result = {"intent": "no_payment", "items": [], "target_ref": None, "questions": ["Уточните?"]}
        return LlmResponse(json.dumps(result), f"stub/{model}", 0 if self.unknown else 10, 0 if self.unknown else 20, 1)


def test_models_are_independent_and_source_gold_misses_fail_with_complete_coverage(
    conn: psycopg.Connection[Any],
) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    claimed = enqueue(conn, store, routing, pricing)
    provider = Replies(conn)
    outcome = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(),
        clock=lambda: NOW,
    ).run(claimed)
    assert outcome.status == "failed"
    assert outcome.report["complete"]
    assert Counter(provider.calls) == {"primary": 53, "fallback": 53}
    for result in outcome.report["models"].values():
        assert set(result["datasets"]) == {"source", "semantic"}
        assert result["datasets"]["semantic"]["metrics"]["category_id_agreement"] == {
            "numerator": 13,
            "denominator": 13,
        }
        assert result["uncovered"] == {"source": 0, "semantic": 0}
    budget = store.budget(conn, now=NOW)
    assert Decimal(budget["day_spent"]) == Decimal("0.00318")
    assert Decimal(budget["day_reserved"]) == 0
    encoded = json.dumps(outcome.report)
    assert "sensitive-error" not in encoded
    assert "expected_items" not in encoded
    assert "literal_anchor" not in encoded


def test_provider_failure_stops_each_model_once_and_holds_unknown_cost(
    conn: psycopg.Connection[Any], capsys: pytest.CaptureFixture[str]
) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    claimed = enqueue(conn, store, routing, pricing)
    provider = Replies(conn, broken=True)
    outcome = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(),
        clock=lambda: NOW,
    ).run(claimed)
    assert outcome.status == "unavailable"
    assert provider.calls == ["primary", "fallback"]
    assert not outcome.report["complete"]
    budget = store.budget(conn, now=NOW)
    assert Decimal(budget["day_reserved"]) == Decimal("0.163072")
    assert Decimal(budget["day_spent"]) == 0
    assert "sensitive-provider-code" not in capsys.readouterr().out


def test_success_without_usage_holds_full_reserve_and_shared_budget_stops_second_model(
    conn: psycopg.Connection[Any],
) -> None:
    store, routing, pricing = EvalStore(), routes(), prices("10")
    claimed = enqueue(conn, store, routing, pricing)
    provider = Replies(conn, unknown=True)
    outcome = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(day_limit=Decimal("1")),
        clock=lambda: NOW,
    ).run(claimed)
    assert outcome.status == "skipped_budget"
    assert provider.calls == ["primary"]
    assert Decimal(store.budget(conn, now=NOW)["day_reserved"]) == Decimal("0.81536")
    assert Decimal(store.budget(conn, now=NOW)["day_spent"]) == 0


def test_durable_model_and_run_call_caps_cover_datasets_together(conn: psycopg.Connection[Any]) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    claimed = enqueue(conn, store, routing, pricing)
    provider = Replies(conn)
    outcome = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(max_calls=55, max_model_calls=42),
        clock=lambda: NOW,
    ).run(claimed)
    assert outcome.status == "skipped_budget"
    assert Counter(provider.calls) == {"primary": 42, "fallback": 13}
    assert outcome.report["network_calls"] == 55
    for model in outcome.report["models"].values():
        assert sum(dataset["network_calls"] for dataset in model["datasets"].values()) == model["network_calls"]
        assert sum(Decimal(dataset["unsettled_reservation_rub"]) for dataset in model["datasets"].values()) == Decimal(
            model["unsettled_reservation_rub"]
        )


def test_deadline_fences_before_any_network_call(conn: psycopg.Connection[Any]) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    claimed = enqueue(conn, store, routing, pricing)
    provider = Replies(conn)
    moments = iter([NOW, NOW + timedelta(seconds=2), NOW + timedelta(seconds=2), NOW + timedelta(seconds=2)])
    outcome = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(deadline_seconds=1),
        clock=lambda: next(moments),
    ).run(claimed)
    assert outcome.status == "incomplete"
    assert provider.calls == []


def test_worker_finishes_and_never_replays_terminal_run(conn: psycopg.Connection[Any]) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    run_id = uuid4()
    store.enqueue(
        conn,
        run_id=run_id,
        suite="payment_intake",
        run_date=NOW.date(),
        trace_id=uuid4(),
        idempotency_key=str(run_id),
        configuration_fingerprint=configuration_fingerprint(routing, pricing),
        now=NOW,
    )
    provider = Replies(conn, broken=True)
    evaluator = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(),
        clock=lambda: NOW,
    )
    worker = EvalWorker(conn, store, evaluator, lease_seconds=900, poll_seconds=1, clock=lambda: NOW)
    assert worker.run_once()
    assert store.status(conn, run_id)["status"] == "unavailable"
    assert not worker.run_once()
    assert len(provider.calls) == 2


def test_lost_lease_never_delegates_network(conn: psycopg.Connection[Any]) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    claimed = enqueue(conn, store, routing, pricing)
    provider = Replies(conn)
    expired = NOW + timedelta(seconds=901)
    outcome = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(),
        clock=lambda: expired,
    ).run(claimed)
    assert outcome.status == "incomplete"
    assert provider.calls == []
    assert conn.execute("SELECT count(*) FROM ai_eval_call").fetchone()[0] == 0


def test_worker_crash_does_not_repeat_unknown_paid_call(conn: psycopg.Connection[Any]) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    run_id = uuid4()
    store.enqueue(
        conn,
        run_id=run_id,
        suite="payment_intake",
        run_date=NOW.date(),
        trace_id=uuid4(),
        idempotency_key=str(run_id),
        configuration_fingerprint=configuration_fingerprint(routing, pricing),
        now=NOW,
    )

    class Crashes:
        calls = 0

        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            self.calls += 1
            raise RuntimeError("private-payload-and-credential")

    provider = Crashes()
    evaluator = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": provider},
        prices=pricing,
        limits=EvalLimits(),
        clock=lambda: NOW,
    )
    worker = EvalWorker(conn, store, evaluator, lease_seconds=900, poll_seconds=1, clock=lambda: NOW)
    assert worker.run_once()
    assert store.status(conn, run_id)["status"] == "incomplete"
    assert store.status(conn, run_id)["report"]["network_calls"] is None
    assert store.status(conn, run_id)["report"]["coverage_unknown"] is True
    assert Decimal(store.budget(conn, now=NOW)["day_reserved"]) == Decimal("0.081536")
    assert not worker.run_once()
    assert provider.calls == 1


def test_all_gold_metrics_and_coverage_are_required_for_pass(conn: psycopg.Connection[Any]) -> None:
    store, routing, pricing = EvalStore(), routes(), prices()
    claimed = enqueue(conn, store, routing, pricing)
    provider = Replies(conn)
    for case in provider.source.values():
        gold = case["annotations"]
        items = []
        for mention in gold["money_mentions"]:
            if mention["role"] != "candidate_amount":
                continue
            ambiguous = "amount_scale" in gold["clarification_topics"]
            items.append(
                {
                    "ref": f"item-{len(items)}",
                    "text_evidence": mention["surface"],
                    "amount_evidence": mention["surface"],
                    "amount": None if ambiguous else mention["literal_amount"],
                    "currency": None
                    if ambiguous
                    else _currency(mention["surface"], case["text"].split(mention["surface"], 1)[-1]),
                    "direction": gold.get("explicit_direction"),
                    "execution_status": "unknown",
                    "date": None,
                    "category_id": None,
                    "project_id": None,
                    "cfo_id": None,
                    "description": "",
                    "questions": [],
                }
            )
        reply = {
            "intent": "correction"
            if gold.get("must_not_create_new_payment")
            else "payments"
            if items
            else "no_payment",
            "items": items,
            "target_ref": None,
            "questions": ["Уточните?"],
        }
        provider.cases[case["text"]] = {"reply": reply}
    original = provider.complete

    class GoldReplies:
        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            text = json.loads(extract_data_block(request.user))["text"]
            if "reply" not in provider.cases[text]:
                return original(model, request)
            assert conn.info.transaction_status is TransactionStatus.IDLE
            provider.calls.append(model)
            return LlmResponse(json.dumps(provider.cases[text]["reply"]), f"stub/{model}", 10, 20, 1)

    outcome = NightlyEvaluator(
        conn,
        store,
        routing=routing,
        adapters={"stub": GoldReplies()},
        prices=pricing,
        limits=EvalLimits(),
        clock=lambda: NOW,
    ).run(claimed)
    assert outcome.status == "passed", {
        model: {
            name: [
                (row["id"], row["diagnostics"]["failed_checks"])
                for row in result["cases"]
                if row["verdict"] == "rejected"
            ]
            for name, result in data["datasets"].items()
        }
        for model, data in outcome.report["models"].items()
    }
    assert outcome.report["complete"]
    assert outcome.report["network_calls"] == 106
