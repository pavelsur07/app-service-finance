"""Occurrence-local source checks. No accounting decisions or financial writes."""

import re
from datetime import date, timedelta
from decimal import Decimal, InvalidOperation
from zoneinfo import ZoneInfo

from ai_service.tasks.parse_payment_message.schemas import PaymentInput, PaymentItem, PaymentOutput
from ai_service.verification.checks import CheckContext, CheckResult, Severity

MONEY = re.compile(
    r"^[+−-]?\s*([0-9]{1,16}(?:[ \u00a0\u202f][0-9]{3})*(?:[,.][0-9]{1,2})?)\s*"
    r"(тысяч(?:а|и)?|тыс\.?|т\.?|[кk])?\s*(руб(?:\.|лей|ля)?|[р₽]|RUB)?$",
    re.IGNORECASE,
)
MASK = re.compile(r"\[(?:PERSON|PHONE|EMAIL|CARD|ACCOUNT|INN)_\d+\]")
CLAUSE_BREAK = re.compile(r"[\n;!?]|,(?![0-9])|\.(?=\s*[А-ЯЁA-Z])")
PAID_OUT = r"(?:отдал[аи]?|оплатил[аи]?|оплачивал[аи]?|заплатил[аи]?|перев[её]л[аи]?|выплатил[аи]?)"
PAID = PAID_OUT + r"|получил[аи]?|купил[аи]?|потратил[аи]?|вн[её]с(?:ла)?|скинул[аи]?|закинул[аи]?"
PLANNED_OUT = r"(?:оплачу|заплачу|оплатить)"
MONTHS = dict(
    zip(
        (
            "января",
            "февраля",
            "марта",
            "апреля",
            "мая",
            "июня",
            "июля",
            "августа",
            "сентября",
            "октября",
            "ноября",
            "декабря",
        ),
        range(1, 13),
        strict=True,
    )
)
CURRENCIES = (
    (r"руб(?:[а-яё]*|\.)|₽|р\b|rub\b", "RUB"),
    (r"\$|usd\b|доллар[а-яё]*|бакс[а-яё]*", "USD"),
    (r"€|eur\b|евро\b", "EUR"),
    (r"cny\b|юан[а-яё]*", "CNY"),
    (r"kzt\b|тенге\b", "KZT"),
    (r"£|gbp\b|фунт[а-яё]*", "GBP"),
)


def normalize_money(evidence: str) -> str | None:
    match = MONEY.fullmatch(evidence.strip())
    if match is None:
        return None
    try:
        value = Decimal(re.sub(r"[ \u00a0\u202f]", "", match[1]).replace(",", "."))
        if match[2]:
            value *= Decimal(1000)
        return f"{value:.2f}" if 0 < value < Decimal("10000000000000000") else None
    except InvalidOperation:
        return None


def _clause(text: str, start: int, end: int) -> tuple[str, int]:
    left, right = 0, len(text)
    for boundary in CLAUSE_BREAK.finditer(text):
        if boundary.end() <= start:
            left = boundary.end()
        elif boundary.start() >= end:
            right = boundary.start()
            break
    return text[left:right], left


def reference_date(inp: PaymentInput) -> date:
    return inp.reference_at.astimezone(ZoneInfo(inp.timezone)).date()


def _dates(clause: str, reference: date, money_start: int, money_end: int) -> set[date]:
    found = set()
    relative = {"позавчера": -2, "вчера": -1, "сегодня": 0, "завтра": 1, "послезавтра": 2}
    for word, delta in relative.items():
        if re.search(rf"\b{word}\b", clause, re.IGNORECASE):
            found.add(reference + timedelta(days=delta))
    patterns = (
        r"\b(?P<year>[0-9]{4})-(?P<month>[0-9]{2})-(?P<day>[0-9]{2})\b",
        r"\b(?P<day>[0-9]{1,2})\.(?P<month>[0-9]{1,2})(?:\.(?P<year>[0-9]{4}))?\b",
        r"\b(?P<day>[0-9]{1,2})\s+(?P<month>" + "|".join(MONTHS) + r")(?:\s+(?P<year>[0-9]{4}))?\b",
    )
    for pattern in patterns:
        for match in re.finditer(pattern, clause, re.IGNORECASE):
            if match.start() < money_end and match.end() > money_start:
                continue
            month = MONTHS.get(match["month"].lower())
            try:
                found.add(date(int(match["year"] or reference.year), month or int(match["month"]), int(match["day"])))
            except ValueError:
                continue
    return found


def _currency(evidence: str, after: str) -> str | None:
    money = MONEY.fullmatch(evidence.strip())
    if money is not None and money[3]:
        return "RUB"
    for pattern, currency in CURRENCIES:
        if re.match(r"\s*(?:" + pattern + ")", after, re.IGNORECASE):
            return currency
    code = re.match(r"\s*([A-Z]{3})\b", after)
    return code[1] if code else None


def _occurrence_errors(inp: PaymentInput, item: PaymentItem, start: int, end: int, has_questions: bool) -> set[str]:
    errors: set[str] = set()
    clause, left = _clause(inp.text, start, end)
    before, after = inp.text[:start], inp.text[end:]
    evidence = item.amount_evidence
    if item.amount is not None and evidence:
        list_prefix = re.search(r"(?:^|\n)\s*[0-9]+\.\s+$", before)
        if (re.search(r"[0-9][ \u00a0\u202f.,]*$", before) and not list_prefix) or re.match(
            r"(?:[0-9]|[,.][0-9]|[ \u00a0\u202f]+[0-9]{3})",
            after,
        ):
            errors.add("partial_money_number")
        if re.match(r"\s*(?:тысяч|тыс\b|[ткk]\b|млн\b|миллион\w*|млрд\b|лям\w*)", after, re.IGNORECASE):
            errors.add("omitted_scale")
        if re.match(r"\s*(?:%|процент|чел|шт|афиш|участник|[-–]х)", after, re.IGNORECASE):
            errors.add("quantity_amount")
        if re.search(r"(?:^|\n)\s*$", before) and re.match(r"\.\s", after):
            errors.add("list_index_amount")
    if re.search(r"итого\s+(?:\S+\s+){0,3}$", inp.text[left:start], re.IGNORECASE) or re.match(
        r"\s*итого\b", inp.text[start:end], re.IGNORECASE
    ):
        errors.add("context_total_amount")
    explicit_currency = _currency(evidence or "", after)
    if (
        item.amount is not None
        and evidence
        and explicit_currency is None
        and re.fullmatch(r"[+−-]?\s*[0-9]{1,3}(?:[,.][0-9]{1,2})?", evidence.strip())
    ):
        errors.add("short_amount_scale")
    if item.amount is not None and explicit_currency is not None and item.currency != explicit_currency:
        errors.add("currency_contradiction")
    if re.match(r"\s*у\.?\s*е\b", after, re.IGNORECASE) and item.amount is not None:
        errors.add("currency_ambiguous")
    if item.currency is not None and explicit_currency is None and item.currency != inp.currency:
        errors.add("currency_unanchored")
    local_before = inp.text[left:start]
    sign = re.search(r"([+−-])\s*$", local_before)
    own_sign = re.match(r"\s*([+−-])", evidence or "")
    sign_value = own_sign[1] if own_sign else sign[1] if sign else None
    inflow = bool(re.search(r"\b(?:плюс|приход|получил[аи]?)\b", clause, re.IGNORECASE))
    outflow = bool(re.search(r"\b(?:" + PAID_OUT + "|" + PLANNED_OUT + r")\b", clause, re.IGNORECASE))
    expected_direction: str | None
    if sign_value:
        expected_direction = "inflow" if sign_value == "+" else "outflow"
    else:
        expected_direction = ("inflow" if inflow else "outflow") if inflow != outflow else None
    word_direction = ("inflow" if inflow else "outflow") if inflow != outflow else None
    sign_conflict = sign_value is not None and word_direction is not None and expected_direction != word_direction
    if sign_conflict or (sign_value is None and inflow and outflow):
        if item.direction is not None:
            errors.add("direction_ambiguous")
        elif not has_questions:
            errors.add("direction_question")
    if (
        not sign_conflict
        and item.direction is not None
        and expected_direction is not None
        and item.direction != expected_direction
    ):
        errors.add("direction_contradiction")
    if item.direction is not None and sign_value is None and not inflow and not outflow:
        errors.add("direction_unanchored")
    reference = reference_date(inp)
    anchored_dates = _dates(clause, reference, start - left, end - left)
    if len(anchored_dates) > 1:
        if item.date is not None:
            errors.add("date_ambiguous")
        elif not has_questions:
            errors.add("date_question")
    elif item.date is not None and item.date not in anchored_dates:
        errors.add("date_unanchored")
    if item.execution_status == "paid":
        paid = re.search(r"\b(?:" + PAID + r")\b", clause, re.IGNORECASE)
        future = re.search(r"\b(?:завтра|послезавтра|" + PLANNED_OUT + r"|планирую)\b", clause, re.IGNORECASE)
        negated = re.search(r"\bне\s+(?:\w+\s+){0,2}(?:" + PAID + ")", clause, re.IGNORECASE)
        if paid is None or future is not None or negated is not None:
            errors.add("paid_unanchored")
    return errors


def _distinct_occurrences(options: list[set[int]]) -> bool:
    """Each payable candidate consumes a distinct numeric occurrence, even with identical quotes."""
    assigned: dict[int, int] = {}

    def claim(item: int, seen: set[int]) -> bool:
        for position in sorted(options[item] - seen):
            seen.add(position)
            if position not in assigned or claim(assigned[position], seen):
                assigned[position] = item
                return True
        return False

    return all(claim(item, set()) for item in range(len(options)))


def verify(inp: PaymentInput, out: PaymentOutput, _ctx: CheckContext) -> CheckResult:
    errors: set[str] = set()
    if len({i.ref for i in out.items}) != len(out.items):
        errors.add("duplicate_ref")
    if out.intent == "no_payment" and out.items:
        errors.add("no_payment_items")
    if out.intent != "correction" and out.target_ref is not None:
        errors.add("unexpected_target")
    if out.intent == "correction":
        if out.target_ref is not None and out.target_ref not in {d.ref for d in inp.draft_items}:
            errors.add("unknown_target")
        if out.target_ref is None and not out.questions:
            errors.add("missing_target_question")
    if out.intent == "payments" and not out.items and not out.questions:
        errors.add("empty_payments")
    if len(out.items) > 1 and not (out.questions or any(i.questions for i in out.items)):
        errors.add("grouping_question")
    if any(MASK.search(question) for question in out.questions):
        errors.add("output_mask_token")
    categories = {c.id: c.direction for c in inp.categories}
    projects, cfos = {p.id for p in inp.projects}, {c.id for c in inp.cfos}
    pairs = {(p.project_id, p.cfo_id) for p in inp.allowed_pairs}
    payable_occurrences: list[set[int]] = []
    for item in out.items:
        quote, evidence = item.text_evidence, item.amount_evidence
        if MASK.search(item.description) or any(MASK.search(question) for question in item.questions):
            errors.add("output_mask_token")
        if quote not in inp.text or MASK.search(quote):
            errors.add("text_evidence")
        if evidence is not None and (evidence not in quote or MASK.search(evidence)):
            errors.add("amount_evidence")
        if item.amount is not None:
            if evidence is None or normalize_money(evidence) != item.amount:
                errors.add("amount_normalization")
        elif not item.questions and not out.questions:
            errors.add("missing_amount_question")
        # One complete occurrence must support ALL monetary/date/status fields.
        # A dirty substring inside another amount cannot invalidate a clean occurrence.
        quote_occurrences = list(re.finditer(re.escape(quote), inp.text))
        occurrences = [
            match
            for match in re.finditer(re.escape(evidence or quote), inp.text)
            if any(q.start() <= match.start() and q.end() >= match.end() for q in quote_occurrences)
        ]
        occurrence_errors = [
            _occurrence_errors(inp, item, match.start(), match.end(), bool(item.questions or out.questions))
            for match in occurrences
        ]
        if occurrence_errors and not any(not failed for failed in occurrence_errors):
            errors.update(set.union(*occurrence_errors))
        if item.amount is not None and evidence:
            numeric = re.search(r"[0-9]", evidence)
            if numeric is not None:
                payable_occurrences.append(
                    {
                        match.start() + numeric.start()
                        for match, failed in zip(occurrences, occurrence_errors, strict=True)
                        if not failed
                    }
                )
        if item.category_id is not None:
            if item.category_id not in categories:
                errors.add("unknown_category")
            elif categories[item.category_id] not in ("any", item.direction):
                errors.add("category_direction")
        if item.project_id is not None and item.project_id not in projects:
            errors.add("unknown_project")
        if item.cfo_id is not None and item.cfo_id not in cfos:
            errors.add("unknown_cfo")
        if item.project_id is not None and item.cfo_id is not None and (item.project_id, item.cfo_id) not in pairs:
            errors.add("forbidden_pair")
        if item.execution_status != "paid" and not (item.questions or out.questions):
            errors.add("execution_question")
    if not _distinct_occurrences(payable_occurrences):
        errors.add("ambiguous_occurrence")
    return CheckResult("payment_evidence_and_context", not errors, Severity.BLOCK, ",".join(sorted(errors)))
