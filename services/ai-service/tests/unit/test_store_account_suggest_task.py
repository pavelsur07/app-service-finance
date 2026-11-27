import json
from typing import Any

import pytest
from pydantic import ValidationError as PydanticValidationError

from ai_service.core.pipeline import Pipeline
from ai_service.core.task import TaskRegistry
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.llm.types import LlmRequest, LlmResponse
from ai_service.tasks.moysklad_store_account_suggest import task as suggest
from ai_service.tasks.moysklad_store_account_suggest.schemas import SuggestInput, SuggestOutput
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
        {
            "providers": {"stub": {"type": "stub"}},
            "tasks": {suggest.TASK_TYPE: {"mode": "single", "models": ["stub/x"]}},
        },
        {},
    )
    gateway = LlmGateway(routing, {"stub": adapter}, retries_per_model=0)  # type: ignore[dict-item]
    return Pipeline(TaskRegistry([suggest.build()]), gateway).run(
        suggest.TASK_TYPE, COMPANY, example("store_account_suggest_payload")
    ), adapter


def answer(code: str | None, excluded: bool = False, confidence: float = 0.9) -> dict[str, Any]:
    return {"account_code": code, "excluded": excluded, "reason": "оператор К2", "confidence": confidence}


def test_examples_match_schema_and_models() -> None:
    validate("store_account_suggest_payload", example("store_account_suggest_payload"))
    validate("store_account_suggest_output", example("store_account_suggest_output"))
    SuggestInput.model_validate(example("store_account_suggest_payload"))
    SuggestOutput.model_validate(example("store_account_suggest_output"))


def test_confident_known_account_is_accepted_and_store_name_is_not_masked() -> None:
    outcome, adapter = run(answer("ACC_INV_FF"))
    assert outcome.verdict is Verdict.ACCEPTED
    assert outcome.output["account_code"] == "ACC_INV_FF"
    assert "FBS К2 (РНД)" in adapter.requests[0].user


def test_low_confidence_needs_review() -> None:
    outcome, _ = run(answer("ACC_INV_FF", confidence=0.5))
    assert outcome.verdict is Verdict.NEEDS_REVIEW


def test_unknown_account_is_rejected() -> None:
    outcome, _ = run(answer("ACC_NEW_STORE"))
    assert outcome.verdict is Verdict.REJECTED


def test_account_and_excluded_together_is_rejected() -> None:
    outcome, _ = run(answer("ACC_INV_FF", excluded=True))
    assert outcome.verdict is Verdict.REJECTED


def test_dont_know_is_never_accepted() -> None:
    outcome, _ = run(answer(None, confidence=0.95))
    assert outcome.verdict is Verdict.NEEDS_REVIEW


def test_example_with_foreign_account_is_invalid_input() -> None:
    payload = example("store_account_suggest_payload")
    payload["examples"] = [{"name": "ФФ", "account_code": "ACC_OTHER", "excluded": False}]
    with pytest.raises(PydanticValidationError):
        SuggestInput.model_validate(payload)
