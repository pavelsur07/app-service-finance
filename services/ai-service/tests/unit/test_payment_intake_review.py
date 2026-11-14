"""Stage 2 review counterexamples, asserting the deciding verification error."""

from decimal import Decimal
from typing import Any

import pytest

from ai_service.tasks.parse_payment_message.checks import verify
from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentOutput
from ai_service.verification.checks import CheckContext
from tests.unit.test_parse_payment_message import output, payload


def probe(source: str, evidence: str, amount: str | None, **changes: Any) -> set[str]:
    inp = payload()
    inp["text"] = source
    inp["currency"] = changes.pop("input_currency", "RUB")
    out = output()
    out["items"][0].update(text_evidence=evidence, amount_evidence=evidence, amount=amount, date=None, category_id=None)
    out["items"][0].update(changes)
    result = verify(
        PaymentInput.model_validate(inp), PaymentOutput.model_validate(out), CheckContext("A", "parse_payment_message")
    )
    return set(result.details.split(",")) - {""}


@pytest.mark.parametrize(
    ("source", "evidence", "amount"),
    [
        ("Отдала 12 500 руб.", "500 руб.", "500.00"),
        ("Отдала 12.500 руб.", "500 руб.", "500.00"),
        ("Отдала 1500,50 руб.", "50 руб.", "50.00"),
        ("Отдала 12000,5000 руб.", "5000", "5000.00"),
    ],
)
def test_partial_group_or_fraction(source: str, evidence: str, amount: str) -> None:
    assert "partial_money_number" in probe(source, evidence, amount)


@pytest.mark.parametrize("evidence", ["1,5", "12,5", "120.25"])
def test_short_decimal_requires_scale(evidence: str) -> None:
    assert "short_amount_scale" in probe("Отдала " + evidence, evidence, f"{Decimal(evidence.replace(',', '.')):.2f}")


@pytest.mark.parametrize("suffix", ["млн", "миллиона", "млрд", "ляма"])
def test_unhandled_scale_cannot_be_omitted(suffix: str) -> None:
    assert "omitted_scale" in probe("Отдала 2500 " + suffix, "2500", "2500.00")


@pytest.mark.parametrize("suffix", ["долларов", "usd", "евро", "$", "€", "тенге", "юаней"])
@pytest.mark.parametrize("currency", ["RUB", None])
def test_foreign_currency_cannot_fall_back_to_account(suffix: str, currency: str | None) -> None:
    assert "currency_contradiction" in probe("Отдала 1500 " + suffix, "1500", "1500.00", currency=currency)


def test_source_rubles_override_account_usd() -> None:
    assert "currency_contradiction" in probe("Отдала 2000 руб", "2000", "2000.00", input_currency="USD", currency="USD")


def test_percentage_is_not_money() -> None:
    assert "quantity_amount" in probe("Отдала 1000%", "1000", "1000.00")


def test_clean_occurrence_survives_dirty_suffix_occurrence() -> None:
    assert not probe("Отдала 500 руб. за свет и 1500 руб. за аренду", "500 руб.", "500.00")


@pytest.mark.parametrize(
    ("source", "wanted"),
    [
        ("12.11 отдала 20000 руб.", "2026-11-12"),
        ("12 ноября отдала 20000 руб.", "2026-11-12"),
        ("Позавчера отдала 20000 руб.", "2026-11-11"),
    ],
)
def test_supported_local_dates(source: str, wanted: str) -> None:
    assert not probe(source, "20000 руб.", "20000.00", date=wanted)


@pytest.mark.parametrize("verb", ["Купила", "Потратила", "Внесла", "Скинул", "Закинула"])
def test_supported_paid_verbs(verb: str) -> None:
    assert not probe(verb + " бумагу -1500 руб.", "1500 руб.", "1500.00")


def test_mixed_tense_does_not_invalidate_paid_clause() -> None:
    assert not probe(
        "Вчера оплатила аренду 20000 руб., завтра оплачу свет 3000 руб.", "20000 руб.", "20000.00", date="2026-11-12"
    )


def test_neighbouring_sentence_does_not_lend_paid_or_date() -> None:
    source = "Вчера отдала 20000 руб. аренда.Реклама 15000 руб"
    errors = probe(source, "15000 руб", "15000.00", date="2026-11-12")
    assert "paid_unanchored" in errors
    assert "date_unanchored" in errors


def test_signed_amount_inside_clause_has_direction() -> None:
    assert "direction_contradiction" in probe("Перевела -5000 руб", "5000 руб", "5000.00", direction="inflow")


@pytest.mark.parametrize("direction", ["inflow", "outflow"])
def test_unsigned_verbless_payment_cannot_invent_direction(direction: str) -> None:
    errors = probe(
        "Такси 1700р",
        "1700р",
        "1700.00",
        direction=direction,
        execution_status="unknown",
        questions=["Оплата уже совершена?"],
    )
    assert "direction_unanchored" in errors


@pytest.mark.parametrize(
    ("source", "direction"),
    [
        ("Такси +1700р", "inflow"),
        ("Такси -1700р", "outflow"),
        ("Получила 1700р", "inflow"),
        ("Отдала 1700р", "outflow"),
    ],
)
def test_explicit_direction_anchor_is_preserved(source: str, direction: str) -> None:
    assert not probe(
        source,
        "1700р",
        "1700.00",
        direction=direction,
        execution_status="unknown",
        questions=["Оплата уже совершена?"],
    )


@pytest.mark.parametrize("verb", ["Оплачу", "Заплачу", "Оплатить"])
@pytest.mark.parametrize("direction", ["outflow", "inflow"])
def test_future_payment_verb_anchors_direction_independently_from_paid(verb: str, direction: str) -> None:
    errors = probe(
        verb + " 1700р",
        "1700р",
        "1700.00",
        direction=direction,
        execution_status="planned",
        questions=["Подтвердите оплату после её совершения"],
    )
    if direction == "outflow":
        assert not errors
    else:
        assert "direction_contradiction" in errors


@pytest.mark.parametrize("verb", ["Оплачу", "Заплачу", "Оплатить"])
def test_future_payment_direction_does_not_prove_paid(verb: str) -> None:
    errors = probe(verb + " 1700р", "1700р", "1700.00", direction="outflow")
    assert "paid_unanchored" in errors


@pytest.mark.parametrize("word", ["Завтра", "Послезавтра", "Планирую"])
def test_vague_future_context_does_not_anchor_direction(word: str) -> None:
    errors = probe(
        word + " такси 1700р",
        "1700р",
        "1700.00",
        direction="outflow",
        execution_status="planned",
        questions=["Уточните платёж"],
    )
    assert "direction_unanchored" in errors


@pytest.mark.parametrize("field", ["description", "item_question", "top_question"])
def test_mask_tokens_cannot_reach_visible_output(field: str) -> None:
    inp, out = payload(), output()
    if field == "description":
        out["items"][0][field] = "Оплата [PERSON_1]"
    elif field == "item_question":
        out["items"][0]["questions"] = ["Уточните [PERSON_1]"]
    else:
        out["questions"] = ["Уточните [PHONE_1]"]
    result = verify(
        PaymentInput.model_validate(inp), PaymentOutput.model_validate(out), CheckContext("A", "parse_payment_message")
    )
    assert "output_mask_token" in result.details


@pytest.mark.parametrize(
    ("source", "evidence", "error"),
    [
        ("1500. Отдала за аренду 2500 руб.", "1500", "list_index_amount"),
        ("Отдала за 2500 участников", "2500", "quantity_amount"),
    ],
)
def test_list_and_quantity_checks_are_not_hidden_by_short_scale(source: str, evidence: str, error: str) -> None:
    errors = probe(
        source, evidence, evidence + ".00", direction=None, execution_status="unknown", questions=["Уточните платёж"]
    )
    assert errors - {"ambiguous_occurrence"} == {error}
    assert "short_amount_scale" not in errors


def test_two_items_share_neither_dirty_suffix_nor_the_same_paid_occurrence() -> None:
    inp, out = payload(), output()
    inp["text"] = "Отдала 500 руб. за свет и 1500 руб. за аренду"
    item = out["items"][0]
    item.update(text_evidence="500 руб.", amount_evidence="500 руб.", amount="500.00", date=None)
    out["items"].append(
        item | {"ref": "second", "text_evidence": "1500 руб.", "amount_evidence": "1500 руб.", "amount": "1500.00"}
    )
    out["questions"] = ["Это два платежа?"]
    assert verify(
        PaymentInput.model_validate(inp), PaymentOutput.model_validate(out), CheckContext("A", "parse_payment_message")
    ).passed
    inp["text"] = "Отдала 500 руб. за свет, завтра оплачу 500 руб. за аренду"
    out["items"][1].update(text_evidence="500 руб.", amount_evidence="500 руб.", amount="500.00")
    result = verify(
        PaymentInput.model_validate(inp), PaymentOutput.model_validate(out), CheckContext("A", "parse_payment_message")
    )
    assert "ambiguous_occurrence" in result.details


@pytest.mark.parametrize(
    ("source", "evidence", "amount", "currency"),
    [
        ("Отдала 12,50 долларов", "12,50", "12.50", "USD"),
        ("Отдала 120 руб", "120", "120.00", "RUB"),
    ],
)
def test_short_number_with_explicit_source_currency_is_qualified(
    source: str, evidence: str, amount: str, currency: str
) -> None:
    assert not probe(source, evidence, amount, currency=currency)


@pytest.mark.parametrize(
    ("source", "wrong"),
    [
        ("Вчера оплатил 10000 руб и сегодня оплатил 20000 руб", {"date": "2026-11-13"}),
        ("Получил 10000 руб и оплатил 20000 руб", {"direction": "outflow"}),
    ],
)
def test_new_coordinated_predicate_cannot_lend_date_or_direction(source: str, wrong: dict[str, str]) -> None:
    assert probe(source, "10000 руб", "10000.00", **wrong)
    unresolved = dict.fromkeys(wrong)
    assert not probe(
        source, "10000 руб", "10000.00", questions=["Уточните к какому платежу относится значение"], **unresolved
    )


@pytest.mark.parametrize(
    ("source", "changes", "wanted"),
    [
        ("Вчера 10000 руб сегодня 20000 руб", {"date": "2026-11-13"}, "date_ambiguous"),
        ("Получил 10000 руб оплатил 20000 руб", {"direction": "inflow"}, "direction_ambiguous"),
    ],
)
def test_unscoped_conflicting_facts_require_unknown(source: str, changes: dict[str, str], wanted: str) -> None:
    assert wanted in probe(
        source, "10000 руб", "10000.00", execution_status="unknown", questions=["Уточните"], **changes
    )


@pytest.mark.parametrize(
    ("source", "changes", "wanted"),
    [
        ("Вчера оплатил 10000 руб и сегодня оплатил 20000 руб", {"date": None}, "date_question"),
        ("Получил 10000 руб и оплатил 20000 руб", {"direction": None}, "direction_question"),
    ],
)
def test_ambiguous_unknown_fields_also_need_explicit_question(
    source: str, changes: dict[str, None], wanted: str
) -> None:
    assert wanted in probe(source, "10000 руб", "10000.00", **changes)


@pytest.mark.parametrize("direction", ["inflow", "outflow"])
def test_separator_sign_conflicting_with_explicit_verb_requires_unknown(direction: str) -> None:
    assert "direction_ambiguous" in probe(
        "Получил от клиента - 79000 руб", "79000 руб", "79000.00", direction=direction
    )


def test_conflicting_sign_unknown_also_requires_question() -> None:
    source = "Получил от клиента - 79000 руб"
    assert "direction_question" in probe(source, "79000 руб", "79000.00", direction=None)
    assert not probe(source, "79000 руб", "79000.00", direction=None, questions=["Это приход или расход?"])


@pytest.mark.parametrize(
    ("quote", "amount_evidence"),
    [("9700", "9700"), ("9700", None), ("итого мы ей 9700", None)],
)
def test_context_total_cannot_be_unknown_amount_candidate(quote: str, amount_evidence: str | None) -> None:
    errors = probe(
        "Отдала 4300 руб., итого мы ей 9700 за октябрь отдали наличкой",
        quote,
        None,
        amount_evidence=amount_evidence,
        direction=None,
        execution_status="unknown",
        questions=["Уточните сумму платежа"],
    )
    assert "context_total_amount" in errors


@pytest.mark.parametrize("amount", [None, "4300.00"])
def test_current_payment_survives_reference_total(amount: str | None) -> None:
    assert not probe(
        "Отдала 4300 руб., итого мы ей 9700 за октябрь отдали наличкой",
        "4300 руб.",
        amount,
        execution_status="unknown",
        questions=["Подтвердите текущий платёж"],
    )


def test_clean_occurrence_survives_same_number_in_reference_total() -> None:
    assert not probe(
        "Отдала 9700 руб., итого 9700 руб.",
        "9700 руб.",
        "9700.00",
        execution_status="unknown",
        questions=["Подтвердите текущий платёж"],
    )


@pytest.mark.parametrize("source", ["плюс 53000", "+ 53000", "Партнёрский платёж\n+ 53000\nОтдал наличными"])
def test_explicit_local_plus_allows_inflow_without_assuming_paid(source: str) -> None:
    assert not probe(
        source,
        "53000",
        "53000.00",
        direction="inflow",
        execution_status="unknown",
        questions=["Оплата уже совершена?"],
    )


@pytest.mark.parametrize("separator", ["\n", ", ", "; "])
@pytest.mark.parametrize("amount", [None, "4300.00"])
def test_reference_total_in_previous_clause_cannot_hide_current_payment(separator: str, amount: str | None) -> None:
    assert not probe(
        "Итого 9700 руб." + separator + "Отдала 4300 руб.",
        "4300 руб.",
        amount,
        execution_status="unknown",
        questions=["Подтвердите текущий платёж"],
    )
