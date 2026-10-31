"""Evals categorize_transactions (ТЗ §8): все наборы задачи — синтетический и из истории ручных категоризаций.

На stub проверяется только путь (полнота, статьи из справочника, пачки по лимиту контракта).
Точность — по каждой модели маршрута из config/routes.yaml при AI_GATEWAY_TOKEN в env (отвечает
только с app-сервера); без токена такие прогоны пропускаются (живые вызовы — отдельное одобрение,
ТЗ §12). Таблица для выбора модели — `python -m ai_service evals-compare`.
"""

import os
from pathlib import Path
from typing import Any

import pytest
import yaml

from ai_service.core.pipeline import Pipeline
from ai_service.evals import compare
from ai_service.evals.sets import EvalSet, load_sets
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.tasks.registry import build_registry

pytestmark = pytest.mark.evals

ROOT = Path(__file__).resolve().parents[2]
SETS = load_sets(ROOT / "evals" / compare.TASK)
ACCURACY_THRESHOLD = 0.8
# Ловушки «недостаточно данных»: уверенная статья там хуже пропуска.
TRAP_THRESHOLD = 0.75


@pytest.mark.parametrize("eval_set", SETS, ids=[s.name for s in SETS])
def test_set_is_consistent(eval_set: EvalSet) -> None:
    assert len(eval_set.cases) >= 20
    known = {c["id"] for c in eval_set.categories}
    assert len(known) == len(eval_set.categories)
    ids = [c["id"] for c in eval_set.cases]
    assert len(ids) == len(set(ids))
    assert all(c["operation"]["id"] == c["id"] for c in eval_set.cases)
    assert all(c["expected_category_id"] in known | {None} for c in eval_set.cases)


def test_synthetic_set_has_traps() -> None:
    synthetic = next(s for s in SETS if s.name == "synthetic")
    assert any(c["expected_category_id"] is None for c in synthetic.cases)


@pytest.mark.parametrize("eval_set", SETS, ids=[s.name for s in SETS])
def test_pipeline_on_stub_answers_every_operation_from_the_tree(eval_set: EvalSet) -> None:
    routing = parse_routing(
        {
            "providers": {"stub": {"type": "stub"}},
            "tasks": {compare.TASK: {"mode": "single", "models": ["stub/categorize"]}},
        },
        {},
    )
    pipeline = Pipeline(build_registry(), LlmGateway(routing, {"stub": StubAdapter()}, retries_per_model=0))
    score = compare.evaluate(pipeline, eval_set)
    assert score.failed_batches == 0
    assert score.cases == len(eval_set.cases)


def _raw_routes() -> dict[str, Any]:
    raw: dict[str, Any] = yaml.safe_load((ROOT / "config" / "routes.yaml").read_text(encoding="utf-8"))
    return raw


@pytest.mark.parametrize(
    "model",
    compare.available_models(_raw_routes(), dict(os.environ))
    or [pytest.param("none", marks=pytest.mark.skip("нет ключей"))],
)
def test_model_accuracy(model: str) -> None:
    pipeline = compare.single_model_pipeline(_raw_routes(), model, dict(os.environ), 120.0)
    total = compare.Score()
    for eval_set in SETS:
        total.add(compare.evaluate(pipeline, eval_set))
    print(compare.render([(model, total)], {}))  # noqa: T201
    assert total.correct / total.cases >= ACCURACY_THRESHOLD
    assert total.traps == 0 or total.traps_ok / total.traps >= TRAP_THRESHOLD
