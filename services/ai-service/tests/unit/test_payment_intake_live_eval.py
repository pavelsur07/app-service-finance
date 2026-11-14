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
