"""Проверка устойчивости предложений на синтетических назначениях, без сетевых вызовов."""

import json
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator

from ai_service.core.pipeline import Pipeline
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import load_routing
from ai_service.tasks.cash_rule_phrase.schemas import PhraseInput, PhraseOutput
from ai_service.tasks.cash_rule_phrase.task import build
from ai_service.tasks.registry import build_registry
from ai_service.verification.checks import CheckContext, Verdict

pytestmark = pytest.mark.evals
ROOT = Path(__file__).resolve().parents[2]
CASES = [json.loads(line) for line in (ROOT / "evals/cash_rule_phrase/cases.jsonl").read_text().splitlines()]


@pytest.mark.parametrize("case", CASES, ids=[case["id"] for case in CASES])
def test_proposed_span(case: dict) -> None:
    contract = ROOT.parent / "_contracts/ai-cash-rule-phrase/v1/schema.json"
    schema = json.loads(contract.read_text())
    for definition, value in [("input", case["input"]), ("output", case["reply"])]:
        Draft202012Validator(schema | {"$ref": "#/$defs/" + definition}).validate(value)
    check = build().checks[0](
        PhraseInput.model_validate(case["input"]),
        PhraseOutput.model_validate(case["reply"]),
        CheckContext("A", "cash_rule_phrase"),
    )
    assert check.passed == case["supported"]


def test_fixture_coverage() -> None:
    assert len({case["id"] for case in CASES}) == len(CASES) >= 20


def test_offline_route_produces_reviewable_phrase() -> None:
    gateway = LlmGateway(load_routing(ROOT / "config/routes.dev.yaml", {}), {"stub": StubAdapter()})
    contract = ROOT.parent / "_contracts/ai-cash-rule-phrase/v1/examples"
    result = Pipeline(build_registry(), gateway).run(
        "cash_rule_phrase", "A", json.loads((contract / "input.json").read_text())
    )
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output == json.loads((contract / "output.json").read_text())
