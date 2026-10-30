import pytest
from jsonschema import ValidationError
from pydantic import ValidationError as PydanticValidationError

from ai_service.api.models import TaskRequest
from ai_service.tasks.categorize_transactions.schemas import CategorizeInput, CategorizeOutput
from tests.contract import CONTRACT_DIR, example, validate


@pytest.mark.parametrize(
    ("definition", "name"),
    [
        ("task_request", "task_request"),
        ("task_accepted", "task_accepted"),
        ("results_page", "results_page"),
        ("ack_response", "ack_response"),
    ],
)
def test_examples_match_schema(definition: str, name: str) -> None:
    validate(definition, example(name))


def test_echo_payload_example_matches_schema() -> None:
    validate("echo_payload", example("task_request")["payload"])


def test_categorize_examples_match_schema_and_models() -> None:
    validate("categorize_payload", example("categorize_payload"))
    validate("categorize_output", example("categorize_output"))
    CategorizeInput.model_validate(example("categorize_payload"))
    CategorizeOutput.model_validate(example("categorize_output"))


@pytest.mark.parametrize(
    ("change", "path"),
    [
        ({"amount": 85000.0}, "amount"),
        ({"amount": "85 000,00"}, "amount"),
        ({"direction": "transfer"}, "direction"),
        ({"inn": "500100732259"}, "inn"),
    ],
)
def test_categorize_payload_rejected_by_schema_and_model(change: dict[str, object], path: str) -> None:
    payload = example("categorize_payload")
    payload["operations"][0].update(change)
    with pytest.raises(ValidationError):
        validate("categorize_payload", payload)
    with pytest.raises(PydanticValidationError, match=path):
        CategorizeInput.model_validate(payload)


def test_request_model_accepts_contract_example() -> None:
    assert TaskRequest.model_validate(example("task_request")).task_type == "echo"


def test_money_as_float_is_not_hidden_by_schema() -> None:
    broken = example("results_page")
    broken["items"][0]["confidence"] = 1.5
    with pytest.raises(ValidationError):
        validate("results_page", broken)


SYMFONY_FIXTURES = CONTRACT_DIR.parents[3] / "site" / "tests" / "Fixtures" / "ServiceGateway" / "ai-task-v1"


@pytest.mark.skipif(not SYMFONY_FIXTURES.is_dir(), reason="site/ не смонтирован")
def test_symfony_fixtures_are_copies_of_contract_examples() -> None:
    examples = sorted(p.name for p in (CONTRACT_DIR / "examples").glob("*.json"))
    assert sorted(p.name for p in SYMFONY_FIXTURES.glob("*.json")) == examples
    for name in examples:
        assert (SYMFONY_FIXTURES / name).read_bytes() == (CONTRACT_DIR / "examples" / name).read_bytes(), name
