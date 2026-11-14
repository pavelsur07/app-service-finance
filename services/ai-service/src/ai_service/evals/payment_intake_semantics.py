"""Strict synthetic gold and source-evidence scoring; no model execution or real-world accuracy claim."""

import json
import re
from pathlib import Path
from typing import Any

from ai_service.tasks.parse_payment_message.checks import normalize_money, verify
from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
from ai_service.verification.checks import CheckContext

SEMANTIC_CORPUS = Path(__file__).resolve().parents[3] / "evals/parse_payment_message/semantic-cases.jsonl"
FIELDS = ("amount", "currency", "direction", "execution_status", "date", "category_id", "project_id", "cfo_id")
PAIR_VARIANTS = "allowed_project_cfo_pairs"


def _canonical(case: dict[str, Any], golds: list[dict[str, Any]]) -> PaymentOutput:
    return PaymentOutput.model_validate(
        {
            "intent": "payments",
            "target_ref": None,
            "questions": ["Уточните данные?"] if case["questions_required"] else [],
            "items": [
                {
                    **{field: gold[field] for field in FIELDS},
                    "ref": f"synthetic-{index}",
                    "text_evidence": gold["literal_anchor"],
                    "amount_evidence": gold["literal_anchor"],
                    "description": "",
                    "questions": [],
                }
                for index, gold in enumerate(golds)
            ],
        }
    )


def _validate_case(case: dict[str, Any]) -> None:
    if set(case) != {"id", "family", "payload", "expected_items", "questions_required"}:
        raise ValueError
    if not isinstance(case["family"], str) or not re.fullmatch(r"[a-z][a-z_]{0,63}", case["family"]):
        raise ValueError
    if type(case["questions_required"]) is not bool:
        raise ValueError
    if not isinstance(case["payload"], dict) or set(case["payload"]) != set(PaymentInput.model_fields):
        raise ValueError
    inp = PaymentInput.model_validate(case["payload"])
    golds = case["expected_items"]
    if not isinstance(golds, list) or not 1 <= len(golds) <= 50:
        raise ValueError
    amounts: set[str] = set()
    for gold in golds:
        if not isinstance(gold, dict) or set(gold) not in (
            {*FIELDS, "literal_anchor"},
            {*FIELDS, "literal_anchor", PAIR_VARIANTS},
        ):
            raise ValueError
        anchor, amount = gold["literal_anchor"], gold["amount"]
        if (
            not isinstance(anchor, str)
            or not anchor
            or inp.text.count(anchor) != 1
            or not isinstance(amount, str)
            or not re.fullmatch(r"[0-9]{1,16}\.[0-9]{2}", amount)
            or normalize_money(anchor) != amount
            or amount == "0.00"
            or amount in amounts
        ):
            raise ValueError
        amounts.add(amount)
        if PAIR_VARIANTS in gold:
            variants = gold[PAIR_VARIANTS]
            if not isinstance(variants, list) or len(variants) != 3:
                raise ValueError
            if any(not isinstance(pair, list) or len(pair) != 2 for pair in variants):
                raise ValueError
            if any(value is not None and not isinstance(value, str) for pair in variants for value in pair):
                raise ValueError
            pairs = {tuple(pair) for pair in variants}
            projects = {p for p, _ in pairs if p is not None}
            cfos = {c for _, c in pairs if c is not None}
            if len(projects) != 1 or len(cfos) != 1 or not case["questions_required"]:
                raise ValueError
            project, cfo = next(iter(projects)), next(iter(cfos))
            if (
                pairs != {(project, None), (None, cfo), (None, None)}
                or (gold["project_id"], gold["cfo_id"]) not in pairs
                or (project, cfo) in {(p.project_id, p.cfo_id) for p in inp.allowed_pairs}
            ):
                raise ValueError
            # Every permitted gold variant must remain schema-valid and context-valid.
            for project, cfo in variants:
                replacement = {**gold, "project_id": project, "cfo_id": cfo}
                output = _canonical(case, [replacement if item is gold else item for item in golds])
                if not verify(inp, output, CheckContext("synthetic", "parse_payment_message")).passed:
                    raise ValueError
    if case["family"] == "forbidden_pair" and not any(PAIR_VARIANTS in gold for gold in golds):
        raise ValueError
    if not verify(inp, _canonical(case, golds), CheckContext("synthetic", "parse_payment_message")).passed:
        raise ValueError


def load_cases(path: Path = SEMANTIC_CORPUS) -> list[dict[str, Any]]:
    """Reject incomplete or unverifiable gold with one sanitized error before adapter construction."""
    try:
        cases = [json.loads(line) for line in path.read_text().splitlines() if line.strip()]
        if len(cases) != 12 or any(not isinstance(case, dict) for case in cases):
            raise ValueError
        ids = [case.get("id") for case in cases]
        if sorted(ids) != [f"sem-{index:03d}" for index in range(1, 13)]:
            raise ValueError
        for case in cases:
            _validate_case(case)
    except (OSError, ValueError, TypeError, KeyError):
        raise ValueError("invalid_semantic_corpus") from None
    return cases


def _matches(payload: dict[str, Any], gold: dict[str, Any], item: Any) -> bool:
    if not isinstance(item, dict):
        return False
    quote, evidence = item.get("text_evidence"), item.get("amount_evidence")
    if "amount_evidence" in item and evidence is None and isinstance(quote, str) and normalize_money(quote) is not None:
        evidence = quote
    if not isinstance(quote, str) or not isinstance(evidence, str) or not quote or not evidence:
        return False
    # Locate a literal source occurrence first, using monetary evidence normalization only.
    # The model's normalized amount, analytics, ref and output order never choose the gold row.
    source, anchor = payload["text"], gold["literal_anchor"]
    if normalize_money(evidence) != normalize_money(anchor) or evidence not in quote:
        return False
    anchor_start = source.index(anchor)
    anchor_number, evidence_number = re.search(r"[0-9]", anchor), re.search(r"[0-9]", evidence)
    if anchor_number is None or evidence_number is None:
        return False
    for quote_match in re.finditer(re.escape(quote), source):
        for evidence_match in re.finditer(re.escape(evidence), quote):
            start = quote_match.start() + evidence_match.start()
            if start + evidence_number.start() == anchor_start + anchor_number.start():
                return True
    return False


def score(cases: list[dict[str, Any]], answers: dict[str, dict[str, Any]]) -> dict[str, Any]:
    """Score post-Pipeline verified outputs only; missing candidates fail even expected-null fields."""
    metrics = {
        name: {"numerator": 0, "denominator": 0}
        for name in (
            "verified_candidate_recall",
            "verified_candidate_precision",
            "exact_candidate_count",
            *(f"{field}_agreement" for field in FIELDS),
            "expected_null_abstention",
            "pair_abstention",
            "required_question_presence",
            "synthetic_exact_row_tuple",
            "payment_intent_agreement",
        )
    }

    def record(name: str, passed: bool) -> None:
        metrics[name]["denominator"] += 1
        metrics[name]["numerator"] += int(passed)

    for case in cases:
        answer = answers.get(case["id"], {})
        payments = answer.get("intent") == "payments"
        record("payment_intent_agreement", payments)
        items = answer.get("items", [])
        if not isinstance(items, list):
            items = []
        questions = bool(
            answer.get("questions") or any(isinstance(item, dict) and item.get("questions") for item in items)
        )
        if case["questions_required"]:
            record("required_question_presence", questions)
        record("exact_candidate_count", payments and len(items) == len(case["expected_items"]))
        used: set[int] = set()
        for gold in case["expected_items"]:
            index = next(
                (
                    i
                    for i, item in enumerate(items)
                    if payments and i not in used and _matches(case["payload"], gold, item)
                ),
                None,
            )
            item = items[index] if index is not None else None
            if index is not None:
                used.add(index)
            record("verified_candidate_recall", item is not None)
            exact = True
            for field in FIELDS:
                if PAIR_VARIANTS in gold and field in ("project_id", "cfo_id"):
                    continue
                correct = item is not None and field in item and item[field] == gold[field]
                record(f"{field}_agreement", correct)
                if gold[field] is None:
                    record("expected_null_abstention", correct)
                exact = exact and correct
            if PAIR_VARIANTS in gold:
                pair_correct = (
                    item is not None
                    and "project_id" in item
                    and "cfo_id" in item
                    and [item["project_id"], item["cfo_id"]] in gold[PAIR_VARIANTS]
                    and questions
                )
                record("pair_abstention", pair_correct)
                exact = exact and pair_correct
            record("synthetic_exact_row_tuple", exact)
        metrics["verified_candidate_precision"]["denominator"] += len(items)
        metrics["verified_candidate_precision"]["numerator"] += len(used)
    return metrics | {"overall_accuracy": None, "question_quality": None}
