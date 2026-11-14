import json
from typing import Any

import pytest
from pydantic import ValidationError as PydanticValidationError

from ai_service.core.pipeline import Pipeline
from ai_service.core.task import TaskRegistry
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.llm.types import LlmRequest, LlmResponse
from ai_service.tasks.marketplace_source_type_draft import task as draft
from ai_service.tasks.marketplace_source_type_draft.schemas import DraftInput, DraftOutput
from ai_service.verification.checks import Verdict
from tests.contract import example, validate

COMPANY = "0192f0a0-0000-7000-8000-0000000000c1"


class FixedAdapter:
    def __init__(self, output: dict[str, Any]) -> None:
        self.output = output
        self.requests: list[LlmRequest] = []

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        self.requests.append(request)
        return LlmResponse(json.dumps(self.output, ensure_ascii=False), f"stub/{model}", 10, 5, 0)


def run(output: dict[str, Any]) -> tuple[Any, FixedAdapter]:
    adapter = FixedAdapter(output)
    routing = parse_routing(
        {"providers": {"stub": {"type": "stub"}}, "tasks": {draft.TASK_TYPE: {"mode": "single", "models": ["stub/x"]}}},
        {},
    )
    gateway = LlmGateway(routing, {"stub": adapter}, retries_per_model=0)  # type: ignore[dict-item]
    return Pipeline(TaskRegistry([draft.build()]), gateway).run(
        draft.TASK_TYPE, COMPANY, example("source_type_draft_payload")
    ), adapter


def answer(kind: str | None, new_kind: dict[str, str] | None = None, confidence: float = 0.9) -> dict[str, Any]:
    return {
        "kind": kind,
        "new_kind": new_kind,
        "title": "Кросс-докинг",
        "reason": "логистика",
        "confidence": confidence,
    }


def test_examples_match_schema_and_models() -> None:
    validate("source_type_draft_payload", example("source_type_draft_payload"))
    validate("source_type_draft_output", example("source_type_draft_output"))
    DraftInput.model_validate(example("source_type_draft_payload"))
    DraftOutput.model_validate(example("source_type_draft_output"))


def test_high_risk_draft_always_needs_review() -> None:
    outcome, adapter = run(answer("logistics_to"))
    assert outcome.verdict is Verdict.NEEDS_REVIEW
    assert outcome.output["kind"] == "logistics_to"
    assert "Кросс-докинг" in adapter.requests[0].user


def test_unknown_kind_is_rejected() -> None:
    outcome, _ = run(answer("teleport"))
    assert outcome.verdict is Verdict.REJECTED


def test_new_kind_without_kind_passes_checks() -> None:
    outcome, _ = run(answer(None, {"code": "inbound_logistics", "title": "Логистика до склада", "level": "md1"}, 0.4))
    assert outcome.verdict is Verdict.NEEDS_REVIEW
    assert all(c.passed for c in outcome.checks)


def test_kind_and_new_kind_together_is_flagged() -> None:
    outcome, _ = run(answer("logistics_to", {"code": "x_y", "title": "x", "level": "md1"}))
    assert not next(c for c in outcome.checks if c.name == "kind_or_new_kind").passed


def test_example_with_unknown_kind_is_invalid_input() -> None:
    payload = example("source_type_draft_payload")
    payload["examples"].append({"source_type": "ozon:service:1", "name": "Эквайринг", "kind": "acquiring"})
    with pytest.raises(PydanticValidationError):
        DraftInput.model_validate(payload)
