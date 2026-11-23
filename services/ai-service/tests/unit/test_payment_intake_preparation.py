"""Синтетические ответы модели: подготовка никогда не подставляет финансовые факты."""

import json
from typing import Any

import pytest

from ai_service.core.pipeline import Pipeline, PipelineOutcome
from ai_service.core.task import TaskRegistry
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.tasks.parse_payment_message.task import build
from ai_service.verification.checks import Verdict
from tests.unit.test_parse_payment_message import output, payload
from tests.unit.test_pipeline import COMPANY, SequenceAdapter


def proposal(source: str, **changes: Any) -> tuple[dict[str, Any], dict[str, Any]]:
    inp, out = payload(), output()
    inp["text"] = source
    out["items"][0].update(
        text_evidence="53000 руб.",
        amount_evidence="53000 руб.",
        amount="53000.00",
        date=None,
        direction="inflow",
        category_id=None,
        project_id=None,
        cfo_id=None,
        execution_status="paid",
        questions=[],
    )
    out["items"][0].update(changes)
    return inp, out


def run(inp: dict[str, Any], out: dict[str, Any]) -> PipelineOutcome:
    adapter = SequenceAdapter([json.dumps(out)])
    routing = parse_routing(
        {
            "providers": {"stub": {"type": "stub"}},
            "tasks": {"parse_payment_message": {"mode": "single", "models": ["stub/x"]}},
        },
        {},
    )
    return Pipeline(TaskRegistry([build()]), LlmGateway(routing, {"stub": adapter}, retries_per_model=0)).run(
        "parse_payment_message",
        COMPANY,
        inp,
    )


@pytest.mark.parametrize("source", ["+ 53000 руб.\nПартнёр отдал наличными", "Завтра оплачу 53000 руб."])
def test_unanchored_paid_becomes_unknown_question_without_losing_amount(source: str) -> None:
    inp, out = proposal(source)
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    row = result.output["items"][0]
    assert row["amount"] == "53000.00"
    assert row["date"] is None
    assert row["execution_status"] == "unknown"
    assert row["questions"]
    assert result.trace["output_prepared"] is True


@pytest.mark.parametrize("source", ["На днях получила 53000 руб.", "Вчера и сегодня получила 53000 руб."])
def test_unanchored_or_ambiguous_date_becomes_question(source: str) -> None:
    inp, out = proposal(source, date="2026-11-13")
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    row = result.output["items"][0]
    assert row["amount"] == "53000.00"
    assert row["date"] is None
    assert row["execution_status"] == "paid"
    assert row["questions"]


@pytest.mark.parametrize("source", ["Получила 53000 руб.", "Получил - 53000 руб."])
def test_wrong_or_conflicting_direction_becomes_unknown_question(source: str) -> None:
    inp, out = proposal(source, direction="outflow")
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    row = result.output["items"][0]
    assert row["amount"] == "53000.00"
    assert row["direction"] is None
    assert row["execution_status"] == "paid"
    assert row["questions"]


def test_known_but_incompatible_category_becomes_unknown() -> None:
    inp, out = proposal("Получила 53000 руб.", category_id="rent-a")
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    row = result.output["items"][0]
    assert row["direction"] == "inflow"
    assert row["category_id"] is None
    assert row["questions"]


@pytest.mark.parametrize("direction", ["inflow", "outflow"])
def test_guessed_direction_becomes_question_without_changing_money(direction: str) -> None:
    inp, out = proposal(
        "Такси 1700р",
        text_evidence="1700р",
        amount_evidence="1700р",
        amount="1700.00",
        direction=direction,
        execution_status="unknown",
        questions=["Оплата уже совершена?"],
    )
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    row = result.output["items"][0]
    assert row["direction"] is None
    for field in ("ref", "text_evidence", "amount_evidence", "amount", "currency", "execution_status"):
        assert row[field] == out["items"][0][field]
    assert row["questions"][0] == "Оплата уже совершена?"
    assert any("приход" in question and "расход" in question for question in row["questions"])
    assert result.trace["output_prepared"] is True


def test_guessed_direction_also_clears_direction_dependent_category() -> None:
    inp, out = proposal("Такси 53000 руб.", direction="outflow", category_id="rent-a")
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    row = result.output["items"][0]
    assert row["amount"] == "53000.00"
    assert row["direction"] is None
    assert row["category_id"] is None
    assert row["questions"]


@pytest.mark.parametrize("verb", ["Оплачу", "Заплачу", "Оплатить"])
def test_explicit_future_outflow_is_preserved_without_preparation(verb: str) -> None:
    inp, out = proposal(
        verb + " 53000 руб.",
        direction="outflow",
        execution_status="planned",
        questions=["Подтвердите оплату после её совершения"],
    )
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output == out
    assert not result.trace.get("output_prepared", False)


@pytest.mark.parametrize("change", [{"amount": "54000.00"}, {"category_id": "foreign-category"}])
def test_unanchored_direction_does_not_hide_critical_money_or_id_error(change: dict[str, Any]) -> None:
    inp, out = proposal("Такси 53000 руб.", **change)
    result = run(inp, out)
    assert result.verdict is Verdict.REJECTED
    assert result.output is None
    assert not result.trace.get("output_prepared", False)


@pytest.mark.parametrize("total_amount", [None, "9700.00"])
def test_reference_total_is_excluded_with_notice_current_payment_survives(total_amount: str | None) -> None:
    inp, out = proposal("Отдала 53000 руб., итого мы ей 9700 за месяц отдали", direction="outflow")
    out["items"].append(
        out["items"][0]
        | {
            "ref": "item-2",
            "text_evidence": "9700",
            "amount_evidence": "9700" if total_amount else None,
            "amount": total_amount,
            "execution_status": "unknown",
            "questions": ["Уточните сумму"],
        }
    )
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    assert len(result.output["items"]) == 1
    assert result.output["items"][0]["amount"] == "53000.00"
    assert result.output["questions"]


def test_paid_is_downgraded_before_deciding_same_number_is_only_reference_total() -> None:
    inp, out = proposal("Начисление 53000 руб.; итого 53000 руб. отдали", direction=None)
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    assert len(result.output["items"]) == 1
    assert result.output["items"][0]["execution_status"] == "unknown"
    assert result.output["items"][0]["amount"] == "53000.00"


@pytest.mark.parametrize(
    "change",
    [
        {"amount": "54000.00"},
        {"category_id": "foreign-category"},
        {"project_id": "foreign-project"},
        {"text_evidence": "[PHONE_1]"},
        {"currency": "USD"},
    ],
)
def test_preparation_never_hides_critical_errors(change: dict[str, Any]) -> None:
    inp, out = proposal("+ 53000 руб.\nПартнёр отдал наличными", **change)
    result = run(inp, out)
    assert result.verdict is Verdict.REJECTED
    assert result.output is None
    assert not result.trace.get("output_prepared", False)


def test_duplicate_refs_are_not_hidden_by_total_exclusion() -> None:
    inp, out = proposal("Отдала 53000 руб., итого 9700 руб.", direction="outflow")
    out["items"].append(
        out["items"][0]
        | {
            "text_evidence": "9700 руб.",
            "amount_evidence": "9700 руб.",
            "amount": "9700.00",
        }
    )
    result = run(inp, out)
    assert result.verdict is Verdict.REJECTED
    assert any("duplicate_ref" in c.details for c in result.checks)


@pytest.mark.parametrize(("count", "can_prepare"), [(49, True), (50, False)])
def test_question_limit_is_not_satisfied_by_truncating_original_questions(count: int, can_prepare: bool) -> None:
    inp, out = proposal("+ 53000 руб.\nПартнёр отдал наличными", questions=[f"Вопрос {n}" for n in range(count)])
    result = run(inp, out)
    if can_prepare:
        assert result.verdict is Verdict.NEEDS_REVIEW
        assert result.output is not None
        assert result.output["items"][0]["questions"][:count] == out["items"][0]["questions"]
        assert len(result.output["items"][0]["questions"]) == 50
    else:
        assert result.verdict is Verdict.REJECTED
        assert result.output is None
        assert not result.trace.get("output_prepared", False)


def test_preparation_does_not_mutate_original_output() -> None:
    from ai_service.tasks.parse_payment_message.preparation import prepare_output
    from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
    from ai_service.verification.checks import CheckContext

    inp, out = proposal("+ 53000 руб.\nПартнёр отдал наличными")
    original = PaymentOutput.model_validate(out)
    before = original.model_dump(mode="json")
    prepared = prepare_output(
        PaymentInput.model_validate(inp), original, CheckContext(COMPANY, "parse_payment_message")
    )
    assert original.model_dump(mode="json") == before
    assert prepared is not original
    assert prepared.items[0].execution_status == "unknown"


def test_current_valid_output_is_not_changed_or_given_extra_questions() -> None:
    inp, out = proposal("Получила 53000 руб.")
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output == out
    assert not result.trace.get("output_prepared", False)


def test_independent_monetary_occurrence_collision_remains_rejected() -> None:
    inp, out = proposal("+ 53000 руб.\nПартнёр отдал наличными")
    out["items"].append(out["items"][0] | {"ref": "item-2"})
    result = run(inp, out)
    assert result.verdict is Verdict.REJECTED
    assert any("ambiguous_occurrence" in check.details for check in result.checks)


def test_critical_error_in_another_row_is_not_hidden_by_total_exclusion() -> None:
    inp, out = proposal("Отдала 53000 руб., итого 9700 руб.", direction="outflow", category_id="foreign-category")
    out["items"].append(
        out["items"][0]
        | {
            "ref": "item-2",
            "text_evidence": "9700 руб.",
            "amount_evidence": "9700 руб.",
            "amount": "9700.00",
            "category_id": None,
        }
    )
    result = run(inp, out)
    assert result.verdict is Verdict.REJECTED
    assert any("unknown_category" in check.details for check in result.checks)


def test_forbidden_pair_is_not_cleared_to_hide_critical_error() -> None:
    inp, out = proposal("+ 53000 руб.\nПартнёр отдал наличными", project_id="north-a", cfo_id="main-a")
    inp["allowed_pairs"] = []
    result = run(inp, out)
    assert result.verdict is Verdict.REJECTED
    assert any("forbidden_pair" in check.details for check in result.checks)


def test_downgraded_direction_does_not_leave_an_incompatible_category() -> None:
    inp, out = proposal("Получила 53000 руб.", direction="outflow", category_id="rent-a")
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    row = result.output["items"][0]
    assert row["direction"] is None
    assert row["category_id"] is None
    assert row["questions"]


def test_multiple_payments_get_explicit_grouping_question() -> None:
    inp, out = proposal("Получила 53000 руб. и получила 9700 руб.")
    out["items"].append(
        out["items"][0]
        | {
            "ref": "item-2",
            "text_evidence": "9700 руб.",
            "amount_evidence": "9700 руб.",
            "amount": "9700.00",
        }
    )
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    assert len(result.output["items"]) == 2
    assert result.output["questions"]


def test_only_reference_totals_never_become_payment_items() -> None:
    inp, out = proposal("Итого 53000 руб.", execution_status="unknown", questions=["Нужен платёж?"])
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    assert result.output["items"] == []
    assert result.output["questions"]


def test_excluded_total_notice_does_not_hide_another_rows_missing_execution_question() -> None:
    inp, out = proposal("Итого 9700 руб.; начисление 53000 руб.", execution_status="unknown", direction=None)
    current = out["items"][0]
    total = current | {
        "ref": "item-2",
        "text_evidence": "9700 руб.",
        "amount_evidence": "9700 руб.",
        "amount": "9700.00",
        "questions": ["Уточните текущий платёж"],
    }
    out["items"] = [total, current]
    result = run(inp, out)
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    assert len(result.output["items"]) == 1
    assert result.output["items"][0]["execution_status"] == "unknown"
    assert result.output["items"][0]["questions"]


def test_prepared_payload_shared_with_cash_preserves_financial_fields() -> None:
    from pathlib import Path

    fixture_path = Path(__file__).resolve().parents[4] / "site/tests/Fixtures/Cash/payment-intake-prepared.json"
    fixture = json.loads(fixture_path.read_text())
    inp = payload()
    inp["text"] = fixture["source_text"]
    result = run(inp, fixture["raw_output"])
    assert result.verdict is Verdict.NEEDS_REVIEW
    assert result.output is not None
    expected = fixture["prepared_output"]
    assert len(result.output["items"]) == len(expected["items"]) == 1
    actual_row, expected_row = result.output["items"][0], expected["items"][0]
    for field in (
        "ref",
        "text_evidence",
        "amount_evidence",
        "amount",
        "currency",
        "date",
        "direction",
        "execution_status",
        "category_id",
        "project_id",
        "cfo_id",
    ):
        assert actual_row[field] == expected_row[field]
    assert actual_row["questions"]
    assert expected_row["questions"]
