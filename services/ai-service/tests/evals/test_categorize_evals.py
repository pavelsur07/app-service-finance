"""Evals categorize_transactions (ТЗ §8): синтетический обезличенный набор, пачкой как в проде.

На stub проверяется только путь (полнота, статьи из справочника, вердикт не rejected).
Точность — по каждой модели маршрута из config/routes.yaml с ключом в env; без ключей
такие прогоны пропускаются (живые вызовы — отдельное одобрение, ТЗ §12).
"""

import json
import os
from pathlib import Path
from typing import Any

import pytest
import yaml

from ai_service.core.pipeline import Pipeline
from ai_service.llm.adapters import build_adapters
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.tasks.registry import build_registry
from ai_service.verification.checks import Verdict

pytestmark = pytest.mark.evals

ROOT = Path(__file__).resolve().parents[2]
SET_DIR = ROOT / "evals" / "categorize_transactions"
CATEGORIES = json.loads((SET_DIR / "categories.json").read_text(encoding="utf-8"))
CASES = [json.loads(line) for line in (SET_DIR / "cases.jsonl").read_text(encoding="utf-8").splitlines() if line]
COMPANY = "0192f0a0-0000-7000-8000-0000000000c1"
TASK = "categorize_transactions"
ACCURACY_THRESHOLD = 0.8
# Ловушки «недостаточно данных»: уверенная статья там хуже пропуска.
TRAP_THRESHOLD = 0.75


def payload() -> dict[str, Any]:
    return {"categories": CATEGORIES, "operations": [c["operation"] for c in CASES]}


def score(output: dict[str, Any]) -> tuple[float, float, list[str]]:
    predicted = {i["operation_id"]: i["category_id"] for i in output["items"]}
    misses = [c["id"] for c in CASES if predicted.get(c["id"]) != c["expected_category_id"]]
    traps = [c for c in CASES if c["expected_category_id"] is None]
    traps_ok = sum(1 for c in traps if predicted.get(c["id"]) is None)
    return 1 - len(misses) / len(CASES), traps_ok / len(traps), misses


def test_set_is_large_enough_and_consistent() -> None:
    assert len(CASES) >= 20
    known = {c["id"] for c in CATEGORIES}
    assert all(c["expected_category_id"] in known | {None} for c in CASES)
    assert any(c["expected_category_id"] is None for c in CASES)


def test_pipeline_on_stub_answers_every_operation_from_the_tree() -> None:
    routing = parse_routing(
        {"providers": {"stub": {"type": "stub"}}, "tasks": {TASK: {"mode": "single", "models": ["stub/categorize"]}}},
        {},
    )
    pipeline = Pipeline(build_registry(), LlmGateway(routing, {"stub": StubAdapter()}, retries_per_model=0))
    outcome = pipeline.run(TASK, COMPANY, payload())
    assert outcome.verdict in (Verdict.ACCEPTED, Verdict.NEEDS_REVIEW), outcome.checks
    assert outcome.output is not None
    assert len(outcome.output["items"]) == len(CASES)


def _raw_routes() -> dict[str, Any]:
    raw: dict[str, Any] = yaml.safe_load((ROOT / "config" / "routes.yaml").read_text(encoding="utf-8"))
    return raw


def _live_models() -> list[str]:
    routing = parse_routing(_raw_routes(), dict(os.environ))
    return [ref.key for ref in routing.routes[TASK].models if routing.providers[ref.provider].available]


@pytest.mark.parametrize("model", _live_models() or [pytest.param("none", marks=pytest.mark.skip("нет ключей"))])
def test_model_accuracy(model: str) -> None:
    provider = model.split("/", 1)[0]
    raw = {
        "providers": {provider: _raw_routes()["providers"][provider]},
        "tasks": {TASK: {"mode": "single", "models": [model]}},
    }
    single = parse_routing(raw, dict(os.environ))
    gateway = LlmGateway(single, build_adapters(single, 120.0), retries_per_model=1)
    outcome = Pipeline(build_registry(), gateway).run(TASK, COMPANY, payload())
    assert outcome.output is not None, (outcome.verdict, outcome.checks)
    accuracy, traps, misses = score(outcome.output)
    # Строка для таблицы «модель × точность × токены» в PR (ТЗ §8).
    print(f"{model}: accuracy={accuracy:.2f} traps={traps:.2f} tokens={outcome.input_tokens}+{outcome.output_tokens}")  # noqa: T201
    assert accuracy >= ACCURACY_THRESHOLD, misses
    assert traps >= TRAP_THRESHOLD, misses
