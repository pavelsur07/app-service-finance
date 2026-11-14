"""Published technical quality contract stays aligned with service request/response models."""

import json
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker
from pydantic.json_schema import models_json_schema
from referencing import Registry, Resource

from ai_service.api.eval_models import EvalAccepted, EvalLatest, EvalRunRequest, EvalStatus
from tests.unit.test_eval_api_models import request

CONTRACT = Path(__file__).resolve().parents[3] / "_contracts/ai-eval-run/v1"


def validate(definition: str, value: object) -> None:
    schema = json.loads((CONTRACT / "schema.json").read_text())
    registry = Registry().with_resource(schema["$id"], Resource.from_contents(schema))
    Draft202012Validator(
        {"$ref": schema["$id"] + "#/$defs/" + definition}, registry=registry, format_checker=FormatChecker()
    ).validate(value)


def test_request_and_responses_have_published_definitions() -> None:
    schema = json.loads((CONTRACT / "schema.json").read_text())
    Draft202012Validator.check_schema(schema)
    _, runtime_schema = models_json_schema(
        [
            (EvalRunRequest, "validation"),
            (EvalAccepted, "serialization"),
            (EvalStatus, "serialization"),
            (EvalLatest, "serialization"),
        ]
    )
    assert schema["$defs"] == runtime_schema["$defs"]
    assert schema["$id"] == "https://vashfindir.ru/contracts/ai-eval-run/v1/schema.json"
    for model in [EvalRunRequest, EvalAccepted, EvalStatus, EvalLatest]:
        assert model.__name__ in schema["$defs"]
    validate("EvalRunRequest", request())
    validate(
        "EvalAccepted",
        {
            "contract_version": "v1",
            "run_id": request()["run_id"],
            "status": "queued",
            "duplicate": False,
            "configuration_fingerprint": "a" * 64,
        },
    )
    validate("EvalLatest", {"enabled": False, "configuration_fingerprint": None, "run": None})


def test_technical_contract_does_not_accept_business_payload_or_request_limits() -> None:
    import pytest
    from jsonschema import ValidationError

    for forbidden in ["company_id", "payload", "budget_rub", "models", "prices", "dataset_path"]:
        with pytest.raises(ValidationError):
            validate("EvalRunRequest", request() | {forbidden: "untrusted"})
