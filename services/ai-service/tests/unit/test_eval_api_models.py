"""Technical eval requests never accept business data, model overrides or secret paths."""

from datetime import date
from typing import Any
from uuid import UUID

import pytest
from pydantic import ValidationError

from ai_service.api.eval_models import EvalRunRequest


def request() -> dict[str, Any]:
    return {
        "contract_version": "v1",
        "run_id": "0192f0a0-0000-7000-8000-000000000001",
        "suite": "payment_intake",
        "run_date": "2026-11-14",
        "trace_id": "0192f0a0-0000-7000-8000-000000000002",
        "idempotency_key": "payment_intake:2026-11-14",
    }


def test_technical_request_requires_typed_daily_identity() -> None:
    body = EvalRunRequest.model_validate(request())
    assert body.run_date == date(2026, 11, 14)
    assert body.run_id == UUID(request()["run_id"])
    assert "company_id" not in EvalRunRequest.model_fields
    assert "payload" not in EvalRunRequest.model_fields


@pytest.mark.parametrize(
    "field", ["company_id", "payload", "models", "prices", "budget_rub", "dataset_path", "configuration_fingerprint"]
)
def test_request_forbids_business_data_and_runtime_overrides(field: str) -> None:
    with pytest.raises(ValidationError):
        EvalRunRequest.model_validate(request() | {field: "untrusted"})


@pytest.mark.parametrize(
    "change",
    [
        {"run_date": 0},
        {"run_date": "2026-11-14T00:00:00Z"},
        {"run_date": "2026-02-30"},
        {"suite": "other_task"},
        {"contract_version": "v2"},
        {"idempotency_key": ""},
        {"idempotency_key": "bad\x00key"},
        {"idempotency_key": "bad\nkey"},
        {"idempotency_key": "x" * 201},
    ],
)
def test_request_rejects_ambiguous_dates_and_invalid_identity(change: dict[str, Any]) -> None:
    with pytest.raises(ValidationError):
        EvalRunRequest.model_validate(request() | change)
