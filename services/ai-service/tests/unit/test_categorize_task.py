import json
from typing import Any

import pytest

from ai_service.core.pipeline import Pipeline
from ai_service.core.task import TaskRegistry
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.llm.types import LlmRequest, LlmResponse
from ai_service.tasks.categorize_transactions import task as categorize
from ai_service.verification.checks import Verdict
from tests.contract import example

COMPANY = "0192f0a0-0000-7000-8000-0000000000c1"
OP1 = "0192f0a0-0000-7000-8000-0000000000e1"
OP2 = "0192f0a0-0000-7000-8000-0000000000e2"
RENT = "0192f0a0-0000-7000-8000-00000000ca02"
REVENUE = "0192f0a0-0000-7000-8000-00000000ca01"


class FixedAdapter:
    def __init__(self, output: dict[str, Any]) -> None:
        self.output = output
        self.requests: list[LlmRequest] = []

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        self.requests.append(request)
        return LlmResponse(json.dumps(self.output), f"stub/{model}", 10, 5, 0)


def run(output: dict[str, Any], payload: dict[str, Any] | None = None) -> tuple[Any, FixedAdapter]:
    adapter = FixedAdapter(output)
    routing = parse_routing(
        {
            "providers": {"stub": {"type": "stub"}},
            "tasks": {"categorize_transactions": {"mode": "single", "models": ["stub/x"]}},
        },
        {},
    )
    gateway = LlmGateway(routing, {"stub": adapter}, retries_per_model=0)  # type: ignore[dict-item]
    pipeline = Pipeline(TaskRegistry([categorize.build()]), gateway)
    return pipeline.run("categorize_transactions", COMPANY, payload or example("categorize_payload")), adapter


def items(*pairs: tuple[str, str | None, float]) -> dict[str, Any]:
    return {"items": [{"operation_id": o, "category_id": c, "confidence": p} for o, c, p in pairs]}


def test_confident_answers_are_accepted() -> None:
    outcome, _ = run(items((OP1, RENT, 0.95), (OP2, None, 0.0)))
    assert outcome.verdict is Verdict.ACCEPTED
    assert outcome.confidence == 0.95


def test_low_confidence_needs_review() -> None:
    outcome, _ = run(items((OP1, RENT, 0.6), (OP2, None, 0.0)))
    assert outcome.verdict is Verdict.NEEDS_REVIEW


def test_no_answers_at_all_needs_review() -> None:
    outcome, _ = run(items((OP1, None, 0.0), (OP2, None, 0.0)))
    assert outcome.verdict is Verdict.NEEDS_REVIEW


@pytest.mark.parametrize(
    ("output", "check"),
    [
        (items((OP1, RENT, 0.9)), "completeness"),
        (items((OP1, RENT, 0.9), (OP2, None, 0.0), (OP2, None, 0.0)), "completeness"),
        (items((OP1, RENT, 0.9), (OP2, None, 0.0), ("other", None, 0.0)), "completeness"),
        (items((OP1, "invented", 0.9), (OP2, None, 0.0)), "category_in_tree"),
    ],
)
def test_block_checks_reject(output: dict[str, Any], check: str) -> None:
    outcome, _ = run(output)
    assert outcome.verdict is Verdict.REJECTED
    assert check in [c.name for c in outcome.checks if not c.passed]
    assert outcome.output is None


def test_category_of_other_direction_needs_review() -> None:
    outcome, _ = run(items((OP1, REVENUE, 0.99), (OP2, None, 0.0)))
    assert outcome.verdict is Verdict.NEEDS_REVIEW
    assert "direction_matches" in [c.name for c in outcome.checks if not c.passed]


def test_model_sees_masked_purpose_but_intact_ids_and_amounts() -> None:
    payload = example("categorize_payload")
    payload["operations"][0]["description"] = "Зарплата Иванов Иван Иванович, тел +79123456789"
    payload["operations"][0]["amount"] = "123456789012.00"
    _, adapter = run(items((OP1, RENT, 0.9), (OP2, None, 0.0)), payload)
    user = adapter.requests[0].user
    assert "Иванов" not in user
    assert "+79123456789" not in user
    assert "[PERSON_1]" in user
    assert OP1 in user
    assert RENT in user
    assert "123456789012.00" in user
