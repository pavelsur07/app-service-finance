"""Offline preparation and bounded stub execution; no external API."""

import json
from decimal import Decimal
from typing import Any

import pytest

from ai_service.evals import payment_intake as runner
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.types import LlmRequest, LlmResponse


def limits(**changes: Any) -> runner.Limits:
    return runner.Limits(
        max_calls=changes.get("max_calls", 2),
        max_input_tokens=65536,
        max_output_tokens=16000,
        budget_rub=changes.get("budget_rub", Decimal("1")),
        input_price=changes.get("input_price", Decimal(0)),
        output_price=Decimal(0),
    )


def test_default_is_metadata_only_even_when_credentials_exist(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    def forbidden(*args: Any, **kwargs: Any) -> None:
        pytest.fail("dry-run constructed an adapter")

    monkeypatch.setattr(runner, "build_adapters", forbidden)
    monkeypatch.setenv("AI_GATEWAY_TOKEN", "must-not-be-used")
    monkeypatch.setenv("PAYMENT_INTAKE_EVAL_TOKEN", "local-test")
    assert runner.main([]) == 0
    report = json.loads(capsys.readouterr().out)
    assert report["mode"] == "offline_manifest"
    assert report["manifest"]["N"] == 41
    assert sum(len(ids) for ids in report["manifest"]["families"].values()) == 41
    assert report["network_calls"] == 0
    text = json.dumps(report, ensure_ascii=False)
    assert "Марии" not in text
    assert "local-test" not in text
    assert "must-not-be-used" not in text


def test_stub_limits_actual_calls_and_does_not_claim_complete_accuracy() -> None:
    report = runner.evaluate(runner.load_holdout(), StubAdapter(), "stub/payment-intake", limits())
    assert report["network_calls"] == 2
    assert report["complete"] is False
    assert report["metrics"]["overall_accuracy"] is None
    assert report["metrics"]["precision"] is None
    assert report["metrics"]["verified_candidate_recall"]["denominator"] > 41
    assert report["metrics"]["verified_candidate_recall"]["numerator"] == 0


def test_pre_reservation_stops_before_first_call() -> None:
    class Forbidden:
        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            pytest.fail("unreserved call")

    report = runner.evaluate(
        runner.load_holdout(),
        Forbidden(),
        "stub/payment-intake",
        limits(input_price=Decimal("1"), budget_rub=Decimal("0.01")),
    )
    assert report["network_calls"] == 0
    assert report["stop_reason"] == "eval_budget_limit"


def test_limit_covers_second_schema_attempt_without_extra_request() -> None:
    report = runner.evaluate(runner.load_holdout()[:1], StubAdapter(), "stub/invalid", limits(max_calls=1))
    assert report["network_calls"] == 1
    assert report["stop_reason"] == "eval_call_limit"
    assert len(report["calls"]) == 1


@pytest.mark.parametrize("amount_evidence", ["120", None])
def test_partial_short_amount_gold_is_not_financial_amount_oracle(amount_evidence: str | None) -> None:
    case = next(c for c in runner.load_holdout() if c["id"] == "tg-004")
    reply = {
        "intent": "payments",
        "items": [
            {
                "ref": "one",
                "text_evidence": "120",
                "amount_evidence": amount_evidence,
                "amount": None,
                "currency": None,
                "date": None,
                "direction": "outflow",
                "execution_status": "unknown",
                "description": "Зарплата",
                "category_id": None,
                "project_id": None,
                "cfo_id": None,
                "questions": ["Рубли или тысячи?"],
            }
        ],
        "target_ref": None,
        "questions": [],
    }

    class Reply:
        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            return LlmResponse(json.dumps(reply), "stub/actual-alias", 3, 4, 5)

    report = runner.evaluate([case], Reply(), "stub/requested", limits())
    metrics = report["metrics"]
    assert report["cases"][0]["diagnostics"] == {
        "intent": "payments",
        "item_count": 1,
        "amount_null_count": 1,
        "direction_null_count": 0,
        "failed_checks": {},
        "schema_errors": [],
        "output_prepared": False,
        "output_prepare_failed": False,
        "error_code": None,
    }
    assert metrics["verified_candidate_recall"] == {"numerator": 1, "denominator": 1}
    assert metrics["literal_amount_agreement"]["denominator"] == 0
    assert metrics["scale_abstention"] == {"numerator": 1, "denominator": 1}
    assert report["calls"][0]["adapter_model"] == "stub/requested"
    assert report["calls"][0]["response_model_sha256"] == runner.digest("stub/actual-alias")
    assert report["provider_weights_hash"] is None
    assert "Рубли" not in json.dumps(report, ensure_ascii=False)


def test_execute_without_explicit_route_budget_rejected_before_adapters(capsys: pytest.CaptureFixture[str]) -> None:
    assert runner.main(["--execute"]) == 2
    assert json.loads(capsys.readouterr().out)["error"] == "explicit_limits_and_route_required"


def test_unexpected_error_does_not_print_exception_text(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    def bad_holdout() -> list[dict[str, Any]]:
        raise RuntimeError("sensitive-token raw response")

    monkeypatch.setattr(runner, "load_holdout", bad_holdout)
    assert runner.main([]) == 2
    result = capsys.readouterr().out
    assert "sensitive-token" not in result
    assert json.loads(result) == {"error": "runner_error", "error_class": "RuntimeError"}


def test_usage_over_declared_maximum_stops_schema_retry_before_call() -> None:
    class UsageExceeded:
        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            return LlmResponse("invalid JSON", "stub/invalid", 65537, 0, 1)

    report = runner.evaluate(runner.load_holdout()[:1], UsageExceeded(), "stub/invalid", limits())
    assert report["network_calls"] == 1
    assert report["stop_reason"] == "provider_usage_exceeded_declared_maxima"


def test_rejected_reply_reports_safe_reason_without_response_text() -> None:
    report = runner.evaluate(runner.load_holdout()[:1], StubAdapter(), "stub/invalid", limits())
    case = report["cases"][0]
    assert case["verdict"] == "rejected"
    assert case["diagnostics"]["failed_checks"] == {"output_schema": ["schema_validation_failed"]}
    assert case["diagnostics"]["intent"] is None
    assert case["diagnostics"]["item_count"] is None
    assert case["metrics"]["verified_candidate_recall"] == {"numerator": 0, "denominator": 1}
    assert "trace" not in case
    assert "responses" not in case


def test_offline_subset_has_only_requested_known_id(capsys: pytest.CaptureFixture[str]) -> None:
    assert runner.main(["--case-id", "tg-004"]) == 0
    report = json.loads(capsys.readouterr().out)
    assert report["manifest"]["N"] == 1
    assert report["manifest"]["families"] == {"salary": ["tg-004"]}
    assert report["network_calls"] == 0


@pytest.mark.parametrize("ids", [["tg-999"], ["tg-004", "tg-004"]])
def test_invalid_subset_rejected_before_execution(ids: list[str], capsys: pytest.CaptureFixture[str]) -> None:
    args = [value for case_id in ids for value in ["--case-id", case_id]]
    assert runner.main(args) == 2
    assert json.loads(capsys.readouterr().out)["error"] == "invalid_case_selection"


def test_diagnostics_drop_unknown_check_text_and_names() -> None:
    from ai_service.core.pipeline import PipelineOutcome
    from ai_service.verification.checks import CheckResult, Severity, Verdict

    outcome = PipelineOutcome(
        verdict=Verdict.REJECTED,
        checks=[CheckResult("sensitive-name", False, Severity.BLOCK, "token=private-response")],
        trace={"responses": [{"text": "private-response"}]},
    )
    diagnostic = runner._diagnostics(outcome)
    assert diagnostic["failed_checks"] == {"other": ["check_failed"]}
    assert "private-response" not in json.dumps(diagnostic)
    assert "sensitive-name" not in json.dumps(diagnostic)


def test_schema_diagnostics_show_types_and_redact_unknown_field_names() -> None:
    from ai_service.core.pipeline import SCHEMA_CHECK, PipelineOutcome
    from ai_service.verification.checks import CheckResult, Severity, Verdict

    response = json.dumps(
        {
            "intent": "payments",
            "items": [{"direction": "income", "private-field": "private-value"}],
            "questions": [],
            "target_ref": None,
        }
    )
    outcome = PipelineOutcome(
        verdict=Verdict.REJECTED,
        checks=[CheckResult(SCHEMA_CHECK, False, Severity.BLOCK, "invalid output")],
        trace={"responses": [{"text": response}]},
    )
    diagnostic = runner._diagnostics(outcome)
    assert {"path": "items.[].direction", "type": "literal_error"} in diagnostic["schema_errors"]
    assert {"path": "items.[].extra_field", "type": "extra_forbidden"} in diagnostic["schema_errors"]
    assert "private-field" not in json.dumps(diagnostic)
    assert "private-value" not in json.dumps(diagnostic)


@pytest.mark.parametrize("flag", [True, "private-flag-value"])
def test_preparation_diagnostics_expose_only_trusted_booleans(flag: object) -> None:
    from ai_service.core.pipeline import PipelineOutcome
    from ai_service.verification.checks import Verdict

    outcome = PipelineOutcome(
        verdict=Verdict.NEEDS_REVIEW,
        trace={"output_prepared": flag, "output_prepare_failed": True, "private": "private-response"},
    )
    diagnostic = runner._diagnostics(outcome)
    assert diagnostic["output_prepared"] is (flag is True)
    assert diagnostic["output_prepare_failed"] is True
    assert "private" not in json.dumps(diagnostic)


def test_semantic_default_is_metadata_only_and_uses_its_own_source_hash(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    from ai_service.evals import payment_intake_semantics as semantics

    def forbidden(*args: Any, **kwargs: Any) -> None:
        pytest.fail("semantic dry-run constructed an adapter")

    monkeypatch.setattr(runner, "build_adapters", forbidden)
    monkeypatch.setenv("PAYMENT_INTAKE_EVAL_TOKEN", "private-test-credential")
    assert runner.main(["--dataset", "semantic"]) == 0
    report = json.loads(capsys.readouterr().out)
    assert report["network_calls"] == 0
    assert report["metrics"] is None
    assert report["manifest"]["N"] == 12
    assert report["manifest"]["dataset"] == "semantic"
    assert report["manifest"]["gold"] == "synthetic_explicit_choices"
    assert report["manifest"]["corpus_sha256"] == runner.digest(semantics.SEMANTIC_CORPUS.read_text())
    assert report["manifest"]["review_sha256"] is None
    assert "private-test-credential" not in json.dumps(report)
    assert "expected_items" not in json.dumps(report)


def test_semantic_subset_rejects_source_ids_before_adapter_construction(capsys: pytest.CaptureFixture[str]) -> None:
    assert runner.main(["--dataset", "semantic", "--execute", "--case-id", "tg-004"]) == 2
    assert json.loads(capsys.readouterr().out) == {"error": "invalid_case_selection"}


def test_semantic_execution_reuses_pipeline_without_leaking_gold_to_model() -> None:
    from ai_service.core.prompt import extract_data_block
    from ai_service.evals import payment_intake_semantics as semantics
    from ai_service.tasks.parse_payment_message.schemas import PaymentInput
    from tests.unit.test_payment_intake_semantics import answer

    case = semantics.load_cases()[0]
    reply = answer(case)

    class Reply:
        def complete(self, model: str, request: LlmRequest) -> LlmResponse:
            data = json.loads(extract_data_block(request.user))
            assert set(data) == set(PaymentInput.model_fields)
            assert data["text"] == case["payload"]["text"]
            assert "expected_items" not in data
            assert "literal_anchor" not in data
            return LlmResponse(json.dumps(reply), "stub/semantic", 3, 4, 1)

    report = runner.evaluate([case], Reply(), "stub/semantic", limits(max_calls=1), dataset="semantic")
    assert report["network_calls"] == 1
    assert report["complete"]
    assert report["manifest"]["dataset"] == "semantic"
    assert report["manifest"]["N"] == 1
    assert report["metrics"]["verified_candidate_recall"] == {"numerator": 1, "denominator": 1}
    assert report["metrics"]["category_id_agreement"] == {"numerator": 1, "denominator": 1}
    assert report["metrics"]["overall_accuracy"] is None


def test_manifest_fingerprints_preparation_and_final_verification_sources() -> None:
    from pathlib import Path

    from ai_service.core import pipeline
    from ai_service.tasks.parse_payment_message import checks, preparation, task

    manifest = runner.manifest(runner.load_holdout())
    for name, module in {
        "pipeline_sha256": pipeline,
        "task_spec_sha256": task,
        "verification_sha256": checks,
        "preparation_sha256": preparation,
    }.items():
        assert module.__file__ is not None
        assert manifest[name] == runner.digest(Path(module.__file__).read_text())
    assert manifest["semantic_scorer_sha256"] is None


def test_source_scorer_does_not_count_correction_rows_as_new_payments() -> None:
    from ai_service.tasks.parse_payment_message.checks import verify
    from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
    from ai_service.verification.checks import CheckContext
    from tests.unit.test_payment_intake_preparation import proposal

    inp, answer = proposal("Получила 53000 руб.")
    answer["intent"] = "correction"
    answer["questions"] = ["Какую строку исправить?"]
    assert verify(
        PaymentInput.model_validate(inp),
        PaymentOutput.model_validate(answer),
        CheckContext("synthetic", "parse_payment_message"),
    ).passed
    case = {
        "id": "source-intent-test",
        "annotations": {
            "clarification_topics": [],
            "explicit_direction": "inflow",
            "money_mentions": [{"role": "candidate_amount", "surface": "53000 руб.", "literal_amount": "53000.00"}],
        },
    }
    metrics = runner._score([case], {case["id"]: answer})
    assert metrics["verified_candidate_recall"] == {"numerator": 0, "denominator": 1}
    assert metrics["literal_amount_agreement"] == {"numerator": 0, "denominator": 1}
    assert metrics["payment_intent_agreement"] == {"numerator": 0, "denominator": 1}
