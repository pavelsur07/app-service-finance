"""Схема контракта ai-task/v1 из services/_contracts — единственный источник правды (services/AGENTS.md §2)."""

import json
from functools import cache
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator
from referencing import Registry, Resource

CONTRACT_DIR = Path(__file__).resolve().parents[2] / "_contracts" / "ai-task" / "v1"


@cache
def _schema() -> dict[str, Any]:
    return json.loads((CONTRACT_DIR / "schema.json").read_text(encoding="utf-8"))  # type: ignore[no-any-return]


def validate(definition: str, instance: Any) -> None:
    schema = _schema()
    registry = Registry().with_resource(schema["$id"], Resource.from_contents(schema))
    validator = Draft202012Validator({"$ref": f"{schema['$id']}#/$defs/{definition}"}, registry=registry)
    validator.validate(instance)


def example(name: str) -> Any:
    return json.loads((CONTRACT_DIR / "examples" / f"{name}.json").read_text(encoding="utf-8"))
