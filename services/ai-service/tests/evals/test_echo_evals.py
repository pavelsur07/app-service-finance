"""Evals задачи echo (ТЗ §8): кейсы evals/echo/cases.jsonl, порог — доля верных вердиктов и ответов."""

import json
from pathlib import Path
from typing import Any

import pytest

from ai_service.core.pipeline import Pipeline
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import load_routing
from ai_service.tasks.registry import build_registry

pytestmark = pytest.mark.evals

ROOT = Path(__file__).resolve().parents[2]
CASES = [json.loads(line) for line in (ROOT / "evals" / "echo" / "cases.jsonl").read_text().splitlines() if line]
THRESHOLD = 1.0  # заглушка детерминирована: любой промах — регресс ядра


def test_echo_eval_accuracy() -> None:
    routing = load_routing(ROOT / "config" / "routes.yaml", env={})
    pipeline = Pipeline(build_registry(), LlmGateway(routing, {"stub": StubAdapter()}, retries_per_model=0))
    misses: list[dict[str, Any]] = []
    for case in CASES:
        outcome = pipeline.run("echo", "0192f0a0-0000-7000-8000-0000000000c1", case["payload"])
        if outcome.verdict.value != case["expected_status"] or outcome.output != case["expected_output"]:
            misses.append({"id": case["id"], "status": outcome.verdict.value, "output": outcome.output})
    accuracy = 1 - len(misses) / len(CASES)
    assert accuracy >= THRESHOLD, misses
