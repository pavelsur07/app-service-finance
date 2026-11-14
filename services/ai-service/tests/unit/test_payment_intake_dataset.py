"""Only human-reviewed, provenance-bearing, verifiable cases enter versioned datasets."""

import importlib
import json
from copy import deepcopy
from pathlib import Path
from typing import Any

import pytest

from ai_service.evals import payment_intake_semantics as semantics


def helper() -> Any:
    return importlib.import_module("ai_service.evals.payment_intake_dataset")


def entry() -> dict[str, Any]:
    case = deepcopy(semantics.load_cases()[0])
    case["id"] = "reviewed-example-2026"
    return {
        "format_version": 1,
        "source": {"channel": "manual", "provenance": "synthetic", "annotation_origin": "human"},
        "review": {"status": "human_reviewed", "reviewer_ref": "review-team", "reviewed_at": "2026-11-14"},
        "case": case,
    }


def write(tmp_path: Path, rows: list[dict[str, Any]]) -> Path:
    path = tmp_path / "new-data.jsonl"
    path.write_text("\n".join(json.dumps(row, ensure_ascii=False) for row in rows) + "\n")
    return path


def test_generic_reviewed_ids_count_are_separate_from_legacy(tmp_path: Path) -> None:
    approved = entry()
    pending = deepcopy(approved)
    pending["review"] = {"status": "pending", "reviewer_ref": None, "reviewed_at": None}
    pending["case"] = {"candidate": "no financial gold assigned"}
    cases = helper().load_reviewed_dataset(write(tmp_path, [approved]))
    assert cases == [approved["case"]]
    with pytest.raises(ValueError, match=r"^invalid_reviewed_dataset$"):
        helper().load_reviewed_dataset(write(tmp_path, [approved, pending]))
    assert "source" not in cases[0]["payload"]
    with pytest.raises(ValueError, match="invalid_semantic_corpus"):
        semantics.load_cases(write(tmp_path, [approved["case"]]))


@pytest.mark.parametrize(
    "mutation",
    [
        "model_gold",
        "user_gold",
        "missing_reviewer",
        "bad_date",
        "foreign_id",
        "unknown_gold",
        "duplicate_id",
        "duplicate_anchor",
        "duplicate_amount",
        "extra_model_metadata",
        "pending_only",
        "unreviewed_status",
    ],
)
def test_invalid_reviewed_data_has_only_sanitized_error(tmp_path: Path, mutation: str) -> None:
    row = entry()
    rows = [row]
    if mutation in {"model_gold", "user_gold"}:
        row["source"]["annotation_origin"] = mutation
    elif mutation == "missing_reviewer":
        row["review"]["reviewer_ref"] = None
    elif mutation == "bad_date":
        row["review"]["reviewed_at"] = "sensitive-raw-token"
    elif mutation == "foreign_id":
        row["case"]["expected_items"][0]["project_id"] = "foreign"
    elif mutation == "unknown_gold":
        row["case"]["expected_items"][0]["secret"] = "sensitive-raw-token"
    elif mutation == "duplicate_id":
        rows.append(deepcopy(row))
    elif mutation == "duplicate_anchor":
        row["case"]["payload"]["text"] *= 2
    elif mutation == "duplicate_amount":
        row["case"]["expected_items"] *= 2
    elif mutation == "extra_model_metadata":
        row["case"]["payload"]["source"] = row["source"]
    elif mutation == "pending_only":
        row["review"]["status"] = "pending"
    else:
        row["review"]["status"] = "automatic"
    with pytest.raises(ValueError, match=r"^invalid_reviewed_dataset$") as caught:
        helper().load_reviewed_dataset(write(tmp_path, rows))
    assert caught.value.__cause__ is None


def test_index_pins_version_path_digest_and_validated_gold(tmp_path: Path) -> None:
    row = entry()
    path = write(tmp_path, [row])
    dataset = path.rename(tmp_path / "reviewed-v1.jsonl")
    import hashlib

    index = {
        "format_version": 1,
        "datasets": [
            {"version": "v1", "path": dataset.name, "sha256": hashlib.sha256(dataset.read_bytes()).hexdigest()}
        ],
    }
    index_path = tmp_path / "reviewed-index.json"
    index_path.write_text(json.dumps(index))
    assert helper().load_reviewed_index(index_path) == index
    index["datasets"][0]["path"] = "../outside.jsonl"
    index_path.write_text(json.dumps(index))
    with pytest.raises(ValueError, match=r"^invalid_reviewed_index$"):
        helper().load_reviewed_index(index_path)


@pytest.mark.parametrize("mutation", ["digest", "duplicate_version", "duplicate_path", "symlink", "missing_gold"])
def test_index_rejects_unverified_versions(tmp_path: Path, mutation: str) -> None:
    import hashlib

    row = entry()
    if mutation == "missing_gold":
        del row["case"]["expected_items"][0]["date"]
    path = write(tmp_path, [row]).rename(tmp_path / "reviewed-v1.jsonl")
    index = {
        "format_version": 1,
        "datasets": [{"version": "v1", "path": path.name, "sha256": hashlib.sha256(path.read_bytes()).hexdigest()}],
    }
    if mutation == "digest":
        index["datasets"][0]["sha256"] = "0" * 64
    elif mutation == "duplicate_version":
        index["datasets"].append(dict(index["datasets"][0]))
    elif mutation == "duplicate_path":
        index["datasets"].append({**index["datasets"][0], "version": "v2"})
    elif mutation == "symlink":
        path.rename(tmp_path / "outside.jsonl")
        path.symlink_to(tmp_path / "outside.jsonl")
    index_path = tmp_path / "reviewed-index.json"
    index_path.write_text(json.dumps(index))
    with pytest.raises(ValueError, match=r"^invalid_reviewed_index$"):
        helper().load_reviewed_index(index_path)


def unknown_amount_entry() -> dict[str, Any]:
    row = entry()
    case = row["case"]
    gold = case["expected_items"][0]
    case["payload"]["text"] = case["payload"]["text"].replace(gold["literal_anchor"], "120")
    gold.update(literal_anchor="120", amount=None)
    case["questions_required"] = True
    return row


def test_reviewed_unknown_scale_amount_is_verified_and_scored_by_source_anchor(tmp_path: Path) -> None:
    from ai_service.tasks.parse_payment_message.checks import verify
    from ai_service.tasks.parse_payment_message.schemas import PaymentInput
    from ai_service.verification.checks import CheckContext

    row = unknown_amount_entry()
    case = helper().load_reviewed_dataset(write(tmp_path, [row]))[0]
    answer = semantics._canonical(case, case["expected_items"])
    assert verify(
        PaymentInput.model_validate(case["payload"]), answer, CheckContext("test", "parse_payment_message")
    ).passed
    scores = semantics.score([case], {case["id"]: answer.model_dump(mode="json")})
    for name in (
        "amount_agreement",
        "verified_candidate_recall",
        "synthetic_exact_row_tuple",
        "required_question_presence",
    ):
        assert scores[name] == {"numerator": 1, "denominator": 1}
    assert scores["expected_null_abstention"]["numerator"] == scores["expected_null_abstention"]["denominator"]
    missing = semantics.score([case], {})
    for name in ("amount_agreement", "verified_candidate_recall", "synthetic_exact_row_tuple"):
        assert missing[name] == {"numerator": 0, "denominator": 1}
    answer.questions = []
    assert not verify(
        PaymentInput.model_validate(case["payload"]), answer, CheckContext("test", "parse_payment_message")
    ).passed
    assert semantics.score([case], {case["id"]: answer.model_dump(mode="json")})["required_question_presence"] == {
        "numerator": 0,
        "denominator": 1,
    }


@pytest.mark.parametrize(
    "mutation", ["no_question", "missing_gold", "invalid_anchor", "repeated_anchor", "repeated_normalized_amount"]
)
def test_reviewed_unknown_amount_requires_complete_gold_and_unique_money_anchor(tmp_path: Path, mutation: str) -> None:
    row = unknown_amount_entry()
    case = row["case"]
    if mutation == "no_question":
        case["questions_required"] = False
    elif mutation == "missing_gold":
        del case["expected_items"][0]["amount"]
    elif mutation == "invalid_anchor":
        case["payload"]["text"] = case["payload"]["text"].replace("120", "без суммы")
        case["expected_items"][0]["literal_anchor"] = "без суммы"
    elif mutation == "repeated_anchor":
        case["payload"]["text"] += " 120"
    else:
        case["expected_items"][0]["literal_anchor"] = "120 "
        case["payload"]["text"] += " Сегодня оплатил ещё 120.00 руб."
        case["expected_items"].append({**case["expected_items"][0], "literal_anchor": "120.00 руб", "amount": "120.00"})
    with pytest.raises(ValueError, match=r"^invalid_reviewed_dataset$"):
        helper().load_reviewed_dataset(write(tmp_path, [row]))


def test_legacy_semantic_loader_keeps_known_amount_gold_required(tmp_path: Path) -> None:
    cases = deepcopy(semantics.load_cases())
    cases[0] = {**unknown_amount_entry()["case"], "id": "sem-001"}
    with pytest.raises(ValueError, match=r"^invalid_semantic_corpus$"):
        semantics.load_cases(write(tmp_path, cases))
