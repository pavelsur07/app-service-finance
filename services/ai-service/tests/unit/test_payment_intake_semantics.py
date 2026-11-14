"""Observable synthetic gold validation and evidence-only candidate alignment."""

import importlib
import json
from copy import deepcopy
from pathlib import Path
from typing import Any

import pytest

from ai_service.tasks.parse_payment_message.checks import verify
from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
from ai_service.verification.checks import CheckContext

FIELDS = ("amount", "currency", "direction", "execution_status", "date", "category_id", "project_id", "cfo_id")


def helper() -> Any:
    return importlib.import_module("ai_service.evals.payment_intake_semantics")


def answer(case: dict[str, Any]) -> dict[str, Any]:
    return {
        "intent": "payments",
        "target_ref": None,
        "questions": ["Уточните данные?"] if case["questions_required"] else [],
        "items": [
            {
                "ref": f"item-{i}",
                "text_evidence": gold["literal_anchor"],
                "amount_evidence": gold["literal_anchor"],
                "description": "",
                "questions": [],
                **{field: gold[field] for field in FIELDS},
            }
            for i, gold in enumerate(case["expected_items"])
        ],
    }


def write_cases(tmp_path: Path, cases: list[dict[str, Any]]) -> Path:
    path = tmp_path / "cases.jsonl"
    path.write_text("\n".join(json.dumps(case, ensure_ascii=False) for case in cases))
    return path


def test_full_corpus_is_canonical_and_company_scoped() -> None:
    cases = helper().load_cases()
    assert [case["id"] for case in cases] == [f"sem-{i:03d}" for i in range(1, 13)]
    a, b = cases[:2]
    for directory in ("categories", "projects", "cfos"):
        assert [row["name"] for row in a["payload"][directory]] == [row["name"] for row in b["payload"][directory]]
        assert {row["id"] for row in a["payload"][directory]}.isdisjoint(row["id"] for row in b["payload"][directory])
    metrics = helper().score(cases, {case["id"]: answer(case) for case in cases})
    for name, metric in metrics.items():
        if isinstance(metric, dict):
            assert metric["numerator"] == metric["denominator"], name
    assert metrics["overall_accuracy"] is None
    assert metrics["question_quality"] is None


@pytest.mark.parametrize(
    "mutation",
    [
        "missing_gold",
        "extra_gold",
        "bad_amount",
        "bad_anchor",
        "duplicate_anchor",
        "foreign_id",
        "forbidden_pair",
        "bad_payload",
        "bad_family",
        "bad_id",
        "duplicate_id",
        "missing_case",
        "bad_date",
        "bad_questions",
        "bad_variants",
    ],
)
def test_loader_rejects_invalid_gold_without_leaking_payload(tmp_path: Path, mutation: str) -> None:
    cases = helper().load_cases()
    gold = cases[0]["expected_items"][0]
    if mutation == "missing_gold":
        del gold["date"]
    elif mutation == "extra_gold":
        gold["secret"] = "must-not-leak"
    elif mutation == "bad_amount":
        gold["amount"] = "0.00"
    elif mutation == "bad_anchor":
        gold["literal_anchor"] = "must-not-leak"
    elif mutation == "duplicate_anchor":
        cases[0]["payload"]["text"] += " " + gold["literal_anchor"]
    elif mutation == "foreign_id":
        gold["project_id"] = cases[1]["expected_items"][0]["project_id"]
    elif mutation == "forbidden_pair":
        gold["cfo_id"] = cases[0]["payload"]["cfos"][1]["id"]
    elif mutation == "bad_payload":
        del cases[0]["payload"]["timezone"]
    elif mutation == "bad_family":
        cases[0]["family"] = "must-not-leak/unsafe"
    elif mutation == "bad_id":
        cases[0]["id"] = "sem-999"
    elif mutation == "duplicate_id":
        cases[1]["id"] = cases[0]["id"]
    elif mutation == "missing_case":
        cases.pop()
    elif mutation == "bad_date":
        gold["date"] = "2026-11-13"
    elif mutation == "bad_questions":
        cases[0]["questions_required"] = "yes"
    else:
        cases[6]["expected_items"][0]["allowed_project_cfo_pairs"].append(["foreign", None])
    with pytest.raises(ValueError, match=r"^invalid_semantic_corpus$") as caught:
        helper().load_cases(write_cases(tmp_path, cases))
    assert caught.value.__cause__ is None


def test_candidate_order_and_refs_do_not_determine_alignment() -> None:
    case = helper().load_cases()[10]
    predicted = answer(case)
    predicted["items"].reverse()
    predicted["items"][0]["ref"] = "arbitrary"
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 2, "denominator": 2}
    for field in ("category_id", "project_id", "cfo_id"):
        predicted["items"][0][field], predicted["items"][1][field] = (
            predicted["items"][1][field],
            predicted["items"][0][field],
        )
    metrics = helper().score([case], {case["id"]: predicted})
    for field in ("category_id", "project_id", "cfo_id"):
        assert metrics[f"{field}_agreement"] == {"numerator": 0, "denominator": 2}
    assert metrics["verified_candidate_recall"] == {"numerator": 2, "denominator": 2}


def test_missing_candidate_fails_all_fields_including_null_abstention() -> None:
    case = helper().load_cases()[5]
    metrics = helper().score([case], {})
    for field in FIELDS:
        assert metrics[f"{field}_agreement"] == {"numerator": 0, "denominator": 1}
    assert metrics["expected_null_abstention"]["numerator"] == 0
    assert metrics["expected_null_abstention"]["denominator"] >= 3
    assert metrics["required_question_presence"] == {"numerator": 0, "denominator": 1}
    predicted = answer(case)
    del predicted["items"][0]["category_id"]
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["category_id_agreement"] == {"numerator": 0, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 0, "denominator": 1}


@pytest.mark.parametrize("extra", ["duplicate", "unmatched"])
def test_extra_candidates_reduce_precision_and_exact_count(extra: str) -> None:
    case = helper().load_cases()[0]
    predicted = answer(case)
    added = deepcopy(predicted["items"][0])
    added["ref"] = "extra"
    if extra == "unmatched":
        added["amount_evidence"] = "9999.99 руб"
    predicted["items"].append(added)
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["verified_candidate_precision"] == {"numerator": 1, "denominator": 2}
    assert metrics["exact_candidate_count"] == {"numerator": 0, "denominator": 1}


def test_predicted_amount_cannot_choose_matching_gold() -> None:
    case = helper().load_cases()[10]
    predicted = answer(case)
    predicted["items"][0]["amount"], predicted["items"][1]["amount"] = (
        predicted["items"][1]["amount"],
        predicted["items"][0]["amount"],
    )
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["amount_agreement"] == {"numerator": 0, "denominator": 2}
    assert metrics["project_id_agreement"] == {"numerator": 2, "denominator": 2}


def test_wrong_evidence_cannot_match_using_correct_predicted_amount() -> None:
    case = helper().load_cases()[0]
    predicted = answer(case)
    predicted["items"][0]["amount_evidence"] = "9999.99 руб"
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["verified_candidate_recall"] == {"numerator": 0, "denominator": 1}


def test_pair_abstention_accepts_each_safe_variant_without_individual_denominators() -> None:
    case = helper().load_cases()[6]
    for project, cfo in case["expected_items"][0]["allowed_project_cfo_pairs"]:
        predicted = answer(case)
        predicted["items"][0].update(project_id=project, cfo_id=cfo)
        metrics = helper().score([case], {case["id"]: predicted})
        assert metrics["pair_abstention"] == {"numerator": 1, "denominator": 1}
        assert metrics["project_id_agreement"] == {"numerator": 0, "denominator": 0}
        assert metrics["cfo_id_agreement"] == {"numerator": 0, "denominator": 0}
        assert metrics["synthetic_exact_row_tuple"] == {"numerator": 1, "denominator": 1}
    predicted["questions"] = []
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["pair_abstention"]["numerator"] == 0
    assert metrics["required_question_presence"]["numerator"] == 0


def test_metrics_never_contain_source_or_prediction_values() -> None:
    case = helper().load_cases()[0]
    predicted = answer(case)
    predicted["items"][0]["project_id"] = "sensitive-prediction"
    encoded = json.dumps(helper().score([case], {case["id"]: predicted}))
    assert "sensitive-prediction" not in encoded
    assert case["expected_items"][0]["literal_anchor"] not in encoded


def test_missing_pair_keys_are_not_null_abstention() -> None:
    case = helper().load_cases()[6]
    predicted = answer(case)
    del predicted["items"][0]["project_id"]
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["pair_abstention"] == {"numerator": 0, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 0, "denominator": 1}


def test_company_a_ids_fail_company_b_analytics_agreement() -> None:
    a, b = helper().load_cases()[:2]
    predicted = answer(b)
    for field in ("category_id", "project_id", "cfo_id"):
        predicted["items"][0][field] = a["expected_items"][0][field]
    metrics = helper().score([b], {b["id"]: predicted})
    for field in ("category_id", "project_id", "cfo_id"):
        assert metrics[f"{field}_agreement"] == {"numerator": 0, "denominator": 1}
    assert metrics["verified_candidate_recall"] == {"numerator": 1, "denominator": 1}


def test_unknown_amount_with_explicit_null_evidence_preserves_other_field_agreements() -> None:
    case = helper().load_cases()[0]
    predicted = answer(case)
    predicted["items"][0].update(amount=None, amount_evidence=None)
    predicted["items"][0]["questions"] = ["Уточните сумму?"]
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["verified_candidate_recall"] == {"numerator": 1, "denominator": 1}
    assert metrics["amount_agreement"] == {"numerator": 0, "denominator": 1}
    for field in FIELDS[1:]:
        assert metrics[f"{field}_agreement"] == {"numerator": 1, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 0, "denominator": 1}


@pytest.mark.parametrize("evidence", ["missing", "invalid_type", "broad_quote"])
def test_unknown_amount_fallback_requires_explicit_null_and_monetary_quote(evidence: str) -> None:
    case = helper().load_cases()[0]
    predicted = answer(case)
    predicted["items"][0].update(amount=None, amount_evidence=None)
    if evidence == "missing":
        del predicted["items"][0]["amount_evidence"]
    elif evidence == "invalid_type":
        predicted["items"][0]["amount_evidence"] = 1101
    else:
        predicted["items"][0]["text_evidence"] = case["payload"]["text"]
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["verified_candidate_recall"] == {"numerator": 0, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 0, "denominator": 1}


def test_fixture_scenarios_cover_explicit_synthetic_semantics() -> None:
    cases = {case["family"]: case for case in helper().load_cases()}
    assert set(cases) == {
        "company_a",
        "company_b",
        "duplicate_category",
        "duplicate_project",
        "duplicate_cfo",
        "missing_directories",
        "forbidden_pair",
        "category_direction",
        "timezone_today",
        "unsupported_date",
        "multiple_payments",
        "ambiguous_date",
    }
    for family, directory, field in (
        ("duplicate_category", "categories", "category_id"),
        ("duplicate_project", "projects", "project_id"),
        ("duplicate_cfo", "cfos", "cfo_id"),
    ):
        case = cases[family]
        assert case["payload"][directory][0]["name"] == case["payload"][directory][1]["name"]
        assert case["payload"][directory][0]["id"] != case["payload"][directory][1]["id"]
        assert case["expected_items"][0][field] is None
        assert case["questions_required"] is True
    missing = cases["missing_directories"]
    for directory in ("categories", "projects", "cfos"):
        assert missing["payload"][directory] == []
    for field in ("category_id", "project_id", "cfo_id"):
        assert missing["expected_items"][0][field] is None
    pair = cases["forbidden_pair"]
    assert pair["expected_items"][0]["allowed_project_cfo_pairs"] == [
        ["a-project-1", None],
        [None, "a-cfo-2"],
        [None, None],
    ]
    assert {"project_id": "a-project-1", "cfo_id": "a-cfo-2"} not in pair["payload"]["allowed_pairs"]
    incompatible = cases["category_direction"]
    assert incompatible["payload"]["categories"][0]["direction"] == "inflow"
    assert incompatible["expected_items"][0]["direction"] == "outflow"
    assert incompatible["expected_items"][0]["category_id"] is None
    midnight = cases["timezone_today"]
    assert midnight["payload"]["reference_at"] == "2026-11-14T23:30:00+00:00"
    assert midnight["payload"]["timezone"] == "Asia/Tokyo"
    assert "Сегодня" in midnight["payload"]["text"]
    assert midnight["expected_items"][0]["date"] == "2026-11-15"
    unsupported = cases["unsupported_date"]
    assert "В следующую пятницу" in unsupported["payload"]["text"]
    assert unsupported["expected_items"][0]["date"] is None
    assert unsupported["expected_items"][0]["execution_status"] == "planned"
    multiple = cases["multiple_payments"]
    assert [gold["amount"] for gold in multiple["expected_items"]] == ["1111.11", "2211.21"]
    for field in ("category_id", "project_id", "cfo_id"):
        assert multiple["expected_items"][0][field] != multiple["expected_items"][1][field]
    ambiguous = cases["ambiguous_date"]
    assert "Вчера и сегодня" in ambiguous["payload"]["text"]
    assert ambiguous["expected_items"][0]["date"] is None
    assert ambiguous["questions_required"] is True


@pytest.mark.parametrize(("narrow_quote", "null_evidence"), [(True, False), (False, False), (True, True)])
@pytest.mark.parametrize("verified_output", [False, True])
def test_numeric_evidence_inside_anchor_preserves_found_candidate(
    narrow_quote: bool,
    null_evidence: bool,
    verified_output: bool,
) -> None:
    case = helper().load_cases()[0]
    predicted = answer(case)
    predicted["items"][0].update(
        text_evidence="1101.01" if narrow_quote else case["payload"]["text"],
        amount_evidence=None if null_evidence else "1101.01",
        amount=None if null_evidence else "1101.01",
        questions=["Уточните сумму?"] if null_evidence else [],
    )
    if verified_output:
        parsed = PaymentOutput.model_validate(predicted)
        check = verify(PaymentInput.model_validate(case["payload"]), parsed, CheckContext("synthetic", "test"))
        assert check.passed, check.details
        predicted = parsed.model_dump(mode="json")
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["verified_candidate_recall"] == {"numerator": 1, "denominator": 1}
    assert metrics["amount_agreement"] == {"numerator": 0 if null_evidence else 1, "denominator": 1}
    for field in FIELDS[1:]:
        assert metrics[f"{field}_agreement"] == {"numerator": 1, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 0 if null_evidence else 1, "denominator": 1}


@pytest.mark.parametrize("family", ["duplicate_category", "duplicate_project", "duplicate_cfo"])
def test_duplicate_names_remain_ambiguous_after_context_filtering(family: str) -> None:
    case = next(case for case in helper().load_cases() if case["family"] == family)
    payload, gold = case["payload"], case["expected_items"][0]
    if family == "duplicate_category":
        eligible = {
            category["id"]
            for category in payload["categories"]
            if category["name"] == "Синтетическая аренда" and category["direction"] in ("any", gold["direction"])
        }
        assert eligible == {"a-cat-1", "a-cat-2"}
    elif family == "duplicate_project":
        eligible = {
            project["id"]
            for project in payload["projects"]
            if project["name"] == "Синтетический Альфа"
            and {"project_id": project["id"], "cfo_id": gold["cfo_id"]} in payload["allowed_pairs"]
        }
        assert eligible == {"a-project-1", "a-project-2"}
    else:
        eligible = {
            cfo["id"]
            for cfo in payload["cfos"]
            if cfo["name"] == "Синтетический Север"
            and {"project_id": gold["project_id"], "cfo_id": cfo["id"]} in payload["allowed_pairs"]
        }
        assert eligible == {"a-cfo-1", "a-cfo-2"}


@pytest.mark.parametrize("span", ["leading_space", "trailing_space", "gold_numeric_only", "signed_evidence"])
def test_verified_monetary_spans_align_by_source_number_instead_of_envelope(span: str) -> None:
    case = helper().load_cases()[0]
    predicted = answer(case)
    evidence = "1101.01 руб"
    if span == "leading_space":
        evidence = " 1101.01 руб"
    elif span == "trailing_space":
        evidence = "1101.01 руб "
    elif span == "gold_numeric_only":
        case["expected_items"][0]["literal_anchor"] = "1101.01"
    else:
        case["payload"]["text"] = case["payload"]["text"].replace("1101.01 руб", "-1101.01 руб")
        evidence = "-1101.01 руб"
    predicted["items"][0].update(text_evidence=evidence, amount_evidence=evidence)
    parsed = PaymentOutput.model_validate(predicted)
    check = verify(PaymentInput.model_validate(case["payload"]), parsed, CheckContext("synthetic", "test"))
    assert check.passed, check.details
    metrics = helper().score([case], {case["id"]: parsed.model_dump(mode="json")})
    assert metrics["verified_candidate_recall"] == {"numerator": 1, "denominator": 1}
    assert metrics["amount_agreement"] == {"numerator": 1, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 1, "denominator": 1}


def test_verified_equal_money_at_different_source_occurrence_cannot_match_gold() -> None:
    case = helper().load_cases()[0]
    other_clause = "Сегодня оплатил 1101.01 руб для другого синтетического назначения."
    case["payload"]["text"] += " " + other_clause
    predicted = answer(case)
    predicted["items"][0]["text_evidence"] = other_clause
    parsed = PaymentOutput.model_validate(predicted)
    check = verify(PaymentInput.model_validate(case["payload"]), parsed, CheckContext("synthetic", "test"))
    assert check.passed, check.details
    metrics = helper().score([case], {case["id"]: parsed.model_dump(mode="json")})
    assert metrics["verified_candidate_recall"] == {"numerator": 0, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 0, "denominator": 1}


@pytest.mark.parametrize("intent", ["correction", "no_payment"])
def test_non_payment_intent_never_scores_rows_as_new_payments(intent: str) -> None:
    from ai_service.tasks.parse_payment_message.checks import verify
    from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
    from ai_service.verification.checks import CheckContext

    case = helper().load_cases()[0]
    predicted = answer(case)
    predicted["intent"] = intent
    predicted["questions"] = ["Какую строку исправить?"]
    if intent == "no_payment":
        predicted["items"] = []
    assert verify(
        PaymentInput.model_validate(case["payload"]),
        PaymentOutput.model_validate(predicted),
        CheckContext("synthetic", "parse_payment_message"),
    ).passed
    metrics = helper().score([case], {case["id"]: predicted})
    assert metrics["verified_candidate_recall"] == {"numerator": 0, "denominator": 1}
    assert metrics["synthetic_exact_row_tuple"] == {"numerator": 0, "denominator": 1}
    assert metrics["payment_intent_agreement"] == {"numerator": 0, "denominator": 1}
