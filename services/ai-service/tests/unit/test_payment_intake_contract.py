"""Standalone contract and reviewed corpus; no model calls."""

import json
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator, ValidationError

ROOT = Path(__file__).resolve().parents[3]
CONTRACT = ROOT / "_contracts/ai-parse-payment-message/v1"


def validate(definition: str, value: object) -> None:
    schema = json.loads((CONTRACT / "schema.json").read_text())
    Draft202012Validator({"$ref": f"#/$defs/{definition}", "$defs": schema["$defs"]}).validate(value)


@pytest.mark.parametrize("definition", ["Payload", "Output"])
def test_examples_match_new_schema(definition: str) -> None:
    value = json.loads((CONTRACT / "examples" / f"{definition.lower()}.json").read_text())
    validate(definition, value)


@pytest.mark.parametrize("change", [{"amount": 12.0}, {"amount": "1e3"}, {"amount": "-1.00"}, {"extra": 1}])
def test_bad_money_or_extra_fields_are_rejected(change: dict[str, object]) -> None:
    value = json.loads((CONTRACT / "examples/output.json").read_text())
    value["items"][0].update(change)
    with pytest.raises(ValidationError):
        validate("Output", value)


def test_text_and_item_limits_reject_without_truncation() -> None:
    value = json.loads((CONTRACT / "examples/payload.json").read_text())
    value["text"] = "я" * 16001
    with pytest.raises(ValidationError):
        validate("Payload", value)
    output = json.loads((CONTRACT / "examples/output.json").read_text())
    output["items"] *= 51
    with pytest.raises(ValidationError):
        validate("Output", output)
