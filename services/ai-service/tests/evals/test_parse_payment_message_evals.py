"""Offline verifier evaluation; never presented as live model quality."""

import json
from collections import defaultdict
from pathlib import Path

import pytest

from ai_service.tasks.parse_payment_message.checks import verify
from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
from ai_service.tasks.parse_payment_message.task import build
from ai_service.verification.checks import CheckContext

pytestmark = pytest.mark.evals
ROOT = Path(__file__).resolve().parents[2]
CASES = [json.loads(line) for line in (ROOT / "evals/parse_payment_message/cases.jsonl").read_text().splitlines()]


def test_offline_verifier_dimensions() -> None:
    assert len(CASES) >= 20
    assert len({c["id"] for c in CASES}) == len(CASES)
    scores: dict[str, list[int]] = defaultdict(lambda: [0, 0])
    errors = []
    for case in CASES:
        result = verify(
            PaymentInput.model_validate(case["input"]),
            PaymentOutput.model_validate(case["reply"]),
            CheckContext("A", "parse_payment_message"),
        )
        correct = result.passed == case["expected_check_pass"]
        if not case["expected_check_pass"]:
            assert case["expected_error"] in result.details.split(","), case["id"]
        for dimension in case["dimensions"]:
            scores[dimension][0] += int(correct)
            scores[dimension][1] += 1
        if not correct:
            errors.append(case["id"] + ":" + result.details)
    print(  # noqa: T201
        json.dumps(
            {
                "model": "fixture-only",
                "prompt": build().prompt.id,
                "N": len(CASES),
                "scores": scores,
                "errors": errors,
                "live_eval": "pending",
            }
        )
    )
    assert not errors
    assert all(numerator == denominator for numerator, denominator in scores.values())


def test_corpus_review_is_complete_and_original_spans_exist() -> None:
    review = [
        json.loads(line) for line in (ROOT / "evals/parse_payment_message/corpus-review.jsonl").read_text().splitlines()
    ]
    sources = [
        json.loads(line)
        for line in (ROOT.parents[1] / "docs/tasks/telegram-payment-intake/examples/messages.jsonl")
        .read_text()
        .splitlines()
    ]
    assert len(review) == len(sources) == 41
    assert len({c["id"] for c in review}) == 41
    for source, checked in zip(sources, review, strict=True):
        assert checked["id"] == source["id"]
        assert checked["reviewed"]
        assert all(m["surface"] in source["text"] for m in checked["facts"])
        assert all(span in source["text"] for span in checked["non_money_spans"])


def test_independent_company_contexts_and_dialogs_are_exercised() -> None:
    from tests.unit.test_parse_payment_message import output

    context = json.loads((ROOT / "evals/parse_payment_message/contexts.json").read_text())
    a, b = PaymentInput.model_validate(context["company_a"]), PaymentInput.model_validate(context["company_b"])
    out = output()
    assert verify(a, PaymentOutput.model_validate(out), CheckContext("A", "parse_payment_message")).passed
    out["items"][0]["category_id"] = b.categories[0].id
    assert (
        "unknown_category"
        in verify(a, PaymentOutput.model_validate(out), CheckContext("A", "parse_payment_message")).details
    )
    out["items"][0].update(project_id=b.projects[0].id, cfo_id=b.cfos[0].id)
    assert (
        "forbidden_pair"
        in verify(b, PaymentOutput.model_validate(out), CheckContext("B", "parse_payment_message")).details
    )
    out["items"][0].update(category_id=context["archived_category"]["id"], project_id=None, cfo_id=None)
    assert (
        "unknown_category"
        in verify(a, PaymentOutput.model_validate(out), CheckContext("A", "parse_payment_message")).details
    )
    assert not context["archived_category"]["included_in_payload"]
    assert context["missing_account"]["expected"] == "Symfony rejects before requesting task"
    for dialog in context["dialogs"]:
        raw = context["company_a"] | {"text": dialog["text"]}
        if dialog["id"] == "correction-target":
            raw["draft_items"] = [{"ref": ref, "description": "Черновик"} for ref in dialog["draft_refs"]]
            reply = {"intent": "correction", "items": [], "target_ref": None, "questions": [dialog["question"]]}
        else:
            reply = output()
            reply["items"][0].update(
                text_evidence="5000 руб.",
                amount_evidence="5000 руб.",
                amount="5000.00",
                date=None,
                questions=[dialog["question"]],
            )
        assert verify(
            PaymentInput.model_validate(raw),
            PaymentOutput.model_validate(reply),
            CheckContext("A", "parse_payment_message"),
        ).passed
