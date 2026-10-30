"""Сравнение моделей на eval-наборах: «модель × точность × стоимость × задержка» (ТЗ §8).

Каждая модель гоняется отдельно (маршрут single), наборы — пачками по лимиту контракта, через
тот же pipeline, что в проде: маскирование, промпт, проверки, вердикт. Пачка без результата
(сбой модели или блокирующая проверка) засчитывается как ошибка по всем её операциям.
"""

import statistics
from collections.abc import Iterator
from dataclasses import dataclass, field
from typing import Any

from ai_service.core.pipeline import Pipeline
from ai_service.evals.sets import EvalSet
from ai_service.llm.adapters import build_adapters
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.tasks.registry import build_registry

TASK = "categorize_transactions"
BATCH_SIZE = 50
EVAL_COMPANY = "0192f0a0-0000-7000-8000-0000000000c1"


@dataclass(slots=True)
class Score:
    cases: int = 0
    correct: int = 0
    # Не ловушки: ответ со статьёй и верный ответ со статьёй.
    labelled: int = 0
    answered: int = 0
    answered_correct: int = 0
    traps: int = 0
    traps_ok: int = 0
    # Ответы с уверенностью не ниже порога задачи — то, что пойдёт в подсказки и автоприменение.
    confident: int = 0
    confident_correct: int = 0
    batches: int = 0
    failed_batches: int = 0
    input_tokens: int = 0
    output_tokens: int = 0
    latencies_ms: list[int] = field(default_factory=list)

    def add(self, other: "Score") -> None:
        for name in self.__slots__:
            value = getattr(other, name)
            if isinstance(value, list):
                getattr(self, name).extend(value)
            else:
                setattr(self, name, getattr(self, name) + value)


@dataclass(frozen=True, slots=True)
class Price:
    """₽ за 1 млн токенов."""

    input: float
    output: float


def single_model_pipeline(raw_routes: dict[str, Any], model: str, env: dict[str, str], timeout: float) -> Pipeline:
    provider = model.split("/", 1)[0]
    raw = {
        "providers": {provider: raw_routes["providers"][provider]},
        "tasks": {TASK: {"mode": "single", "models": [model]}},
    }
    routing = parse_routing(raw, env)
    return Pipeline(build_registry(), LlmGateway(routing, build_adapters(routing, timeout), retries_per_model=1))


def available_models(raw_routes: dict[str, Any], env: dict[str, str]) -> list[str]:
    routing = parse_routing(raw_routes, env)
    return [ref.key for ref in routing.routes[TASK].models if routing.providers[ref.provider].available]


def evaluate(pipeline: Pipeline, eval_set: EvalSet) -> Score:
    spec = build_registry().get(TASK)
    if spec is None:
        raise LookupError(TASK)
    threshold = spec.confidence_threshold
    score = Score()
    for chunk in _chunks(eval_set.cases, BATCH_SIZE):
        outcome = pipeline.run(
            TASK, EVAL_COMPANY, {"categories": eval_set.categories, "operations": [c["operation"] for c in chunk]}
        )
        score.batches += 1
        score.input_tokens += outcome.input_tokens
        score.output_tokens += outcome.output_tokens
        score.latencies_ms.append(int(outcome.trace.get("latency_ms", 0)))
        predicted: dict[str, tuple[str | None, float]] = {}
        if outcome.output is None:
            score.failed_batches += 1
        else:
            predicted = {i["operation_id"]: (i["category_id"], float(i["confidence"])) for i in outcome.output["items"]}
        for case in chunk:
            _score_case(score, case, predicted.get(case["id"]), threshold)
    return score


def render(rows: list[tuple[str, Score]], prices: dict[str, Price]) -> str:
    columns = (
        "модель",
        "кейсов",
        "точность",
        "покрытие",
        "точность ответов",
        "точность ≥ порога (доля)",
        "ловушки",
        "сбои пачек",
        "токены вход/выход",
        "₽ на 1000 операций",
        "задержка пачки p50 / p95, с",
    )
    lines = ["| " + " | ".join(columns) + " |", "|" + "---|" * len(columns)]
    for model, s in rows:
        price = prices.get(model)
        cost = "—"
        if price is not None and s.cases:
            rub = (s.input_tokens * price.input + s.output_tokens * price.output) / 1_000_000
            cost = f"{rub / s.cases * 1000:.2f}"
        lines.append(
            f"| {model} | {s.cases} | {_pct(s.correct, s.cases)} | {_pct(s.answered, s.labelled)} "
            f"| {_pct(s.answered_correct, s.answered)} "
            f"| {_pct(s.confident_correct, s.confident)} ({_pct(s.confident, s.labelled)}) "
            f"| {_pct(s.traps_ok, s.traps)} | {s.failed_batches}/{s.batches} | {s.input_tokens}/{s.output_tokens} "
            f"| {cost} | {_latency(s.latencies_ms)} |"
        )
    return "\n".join(lines)


def parse_prices(raw: Any) -> dict[str, Price]:
    prices: dict[str, Price] = {}
    for model, value in (raw or {}).items():
        if isinstance(value, dict) and value.get("input") is not None and value.get("output") is not None:
            prices[model] = Price(float(value["input"]), float(value["output"]))
    return prices


def _score_case(score: Score, case: dict[str, Any], answer: tuple[str | None, float] | None, threshold: float) -> None:
    expected = case["expected_category_id"]
    category, confidence = answer if answer is not None else (None, 0.0)
    score.cases += 1
    score.correct += int(category == expected)
    if expected is None:
        score.traps += 1
        score.traps_ok += int(category is None)
        return
    score.labelled += 1
    if category is not None:
        score.answered += 1
        score.answered_correct += int(category == expected)
        if confidence >= threshold:
            score.confident += 1
            score.confident_correct += int(category == expected)


def _chunks(items: list[dict[str, Any]], size: int) -> Iterator[list[dict[str, Any]]]:
    for start in range(0, len(items), size):
        yield items[start : start + size]


def _pct(part: int, whole: int) -> str:
    return "—" if whole == 0 else f"{100 * part / whole:.0f}%"


def _latency(values: list[int]) -> str:
    if not values:
        return "—"
    ordered = sorted(values)
    p95 = ordered[min(len(ordered) - 1, round(0.95 * (len(ordered) - 1)))]
    return f"{statistics.median(ordered) / 1000:.1f} / {p95 / 1000:.1f}"
