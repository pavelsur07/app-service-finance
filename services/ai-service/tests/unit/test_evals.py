import json
from pathlib import Path
from typing import Any

import pytest

from ai_service import __main__ as cli
from ai_service.core.pipeline import Pipeline
from ai_service.evals import compare
from ai_service.evals.anonymize import ExportFormatError, anonymize_history
from ai_service.evals.sets import EvalSet, load_sets, write_set
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.tasks.registry import build_registry

RENT = "0192f0a0-0000-7000-8000-00000000c001"
SALES = "0192f0a0-0000-7000-8000-00000000c002"


def _operation(
    op_id: str, description: str | None, counterparty: str | None = None, direction: str = "outflow"
) -> dict[str, Any]:
    return {
        "id": op_id,
        "direction": direction,
        "amount": "85000.00",
        "currency": "RUB",
        "date": "2026-10-01",
        "description": description,
        "counterparty": counterparty,
    }


def _export(cases: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "format": "cash-categorize-history/v1",
        "exported_at": "2026-10-30T12:00:00+03:00",
        "categories": [
            {"id": RENT, "path": "Расходы / Аренда", "direction": "outflow"},
            {"id": SALES, "path": "Выручка", "direction": "inflow"},
        ],
        "cases": cases,
    }


def test_anonymize_masks_personal_data_and_long_numbers_and_renumbers_ids() -> None:
    export = _export(
        [
            {
                "operation": _operation(
                    "0192f0a0-0000-7000-8000-0000000000e1",
                    "Аренда по договору 2024001234 от Иванов Иван Иванович, тел. +7 (912) 345-67-89",
                    "ИП Петров П.П.",
                ),
                "expected_category_id": RENT,
            },
        ]
    )

    categories, cases = anonymize_history(export)

    assert categories == [
        {"id": "c-01", "path": "Расходы / Аренда", "direction": "outflow"},
        {"id": "c-02", "path": "Выручка", "direction": "inflow"},
    ]
    op = cases[0]["operation"]
    assert cases[0]["id"] == op["id"] == "h-0001"
    assert cases[0]["expected_category_id"] == "c-01"
    text = json.dumps(cases, ensure_ascii=False)
    for secret in ("2024001234", "Иванов", "912", "Петров", "0192f0a0"):
        assert secret not in text
    assert "[NUMBER]" in op["description"]
    assert op["amount"] == "85000.00"
    assert op["date"] == "2026-10-01"


def test_anonymize_drops_duplicates_and_cases_outside_the_tree() -> None:
    export = _export(
        [
            {
                "operation": _operation("0192f0a0-0000-7000-8000-0000000000e1", "Аренда офиса"),
                "expected_category_id": RENT,
            },
            {
                "operation": _operation("0192f0a0-0000-7000-8000-0000000000e2", "Аренда офиса"),
                "expected_category_id": RENT,
            },
            {
                "operation": _operation("0192f0a0-0000-7000-8000-0000000000e3", "Аренда офиса", direction="inflow"),
                "expected_category_id": SALES,
            },
            {
                "operation": _operation("0192f0a0-0000-7000-8000-0000000000e4", "Прочее"),
                "expected_category_id": "0192f0a0-0000-7000-8000-00000000c0ff",
            },
        ]
    )

    _, cases = anonymize_history(export)

    assert [c["id"] for c in cases] == ["h-0001", "h-0002"]


def test_anonymize_rejects_unknown_format() -> None:
    with pytest.raises(ExportFormatError):
        anonymize_history({"format": "other", "categories": [], "cases": []})


def test_import_command_writes_a_loadable_set(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    export = tmp_path / "export.json"
    export.write_text(
        json.dumps(
            _export(
                [
                    {
                        "operation": _operation("0192f0a0-0000-7000-8000-0000000000e1", "Аренда"),
                        "expected_category_id": RENT,
                    }
                ]
            )
        ),
        encoding="utf-8",
    )
    monkeypatch.setattr(cli, "_EVALS", tmp_path / "evals")

    assert cli.main(["evals-import", str(export), "--name", "acme-1"]) == 0
    assert cli.main(["evals-import", str(export), "--name", "../escape"]) == 2

    [loaded] = load_sets(tmp_path / "evals")
    assert loaded.name == "history/acme-1"
    assert loaded.cases[0]["expected_category_id"] == "c-01"


def _stub_pipeline() -> Pipeline:
    routing = parse_routing(
        {
            "providers": {"stub": {"type": "stub"}},
            "tasks": {compare.TASK: {"mode": "single", "models": ["stub/categorize"]}},
        },
        {},
    )
    return Pipeline(build_registry(), LlmGateway(routing, {"stub": StubAdapter()}, retries_per_model=0))


def test_evaluate_splits_large_sets_into_contract_sized_batches_and_scores_answers() -> None:
    categories = [{"id": "rent", "path": "Расходы / Аренда", "direction": "outflow"}]
    cases: list[dict[str, Any]] = [
        {"id": f"h-{n:04d}", "operation": _operation(f"h-{n:04d}", "Оплата аренды"), "expected_category_id": "rent"}
        for n in range(1, 121)
    ]
    cases.append({"id": "trap", "operation": _operation("trap", None), "expected_category_id": None})

    score = compare.evaluate(_stub_pipeline(), EvalSet("big", categories, cases))

    assert score.batches == 3
    assert score.failed_batches == 0
    assert score.cases == 121
    assert score.labelled == 120
    assert score.traps == 1
    assert score.traps_ok == 1
    assert score.answered_correct == score.answered == 120
    assert score.correct == 121


def test_render_shows_cost_only_with_known_prices() -> None:
    score = compare.Score(
        cases=1000,
        correct=900,
        labelled=1000,
        answered=950,
        answered_correct=900,
        batches=20,
        input_tokens=2_000_000,
        output_tokens=500_000,
        latencies_ms=[1000, 2000, 3000],
    )

    table = compare.render(
        [("a/m", score), ("b/m", score)],
        compare.parse_prices({"a/m": {"input": 10, "output": 40}, "b/m": {"input": None, "output": None}}),
    )

    rows = table.splitlines()
    assert "| a/m | 1000 | 90% | 95% | 95% |" in rows[2]
    # (2 млн × 10 + 0,5 млн × 40) / 1 млн = 40 ₽ на 1000 операций.
    assert "| 40.00 |" in rows[2]
    assert "| — |" in rows[3]
    assert "2.0 / 3.0" in rows[2]


def test_write_and_load_round_trip(tmp_path: Path) -> None:
    write_set(
        tmp_path / "history" / "x",
        [{"id": "c-01", "path": "А", "direction": "any"}],
        [{"id": "h-0001", "operation": {}, "expected_category_id": None}],
    )
    [loaded] = load_sets(tmp_path)
    assert loaded.name == "history/x"
