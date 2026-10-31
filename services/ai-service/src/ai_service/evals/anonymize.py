"""Обезличивание выгрузки `app:cash:ai-eval-export` перед коммитом в репозиторий (ТЗ §8).

- назначение и контрагент — тем же `Masker`, что маскирует вход моделей в проде (ФИО, телефоны,
  e-mail, счета, карты, ИНН физлиц), плюс любые номера от 8 цифр (договоры, ИНН организаций);
- id операций и статей — в последовательные (`h-0001`, `c-01`); компании в наборе нет:
  её названия и бренды (`redact`, без учёта регистра, с окончаниями) — в `[COMPANY]`, UUID в
  назначениях (номера счетов) — в `[ID]`;
- дубли «назначение + контрагент + направление + статья» схлопываются: они не добавляют
  проверки, только вес частым платежам.

Суммы и даты остаются; пути статей маскируются так же, как назначения. Результат смотрит
человек до коммита — эвристика маскирования не гарантирует полноту.
"""

import re
from collections.abc import Sequence
from typing import Any

from ai_service.privacy.masking import Masker

FORMAT = "cash-categorize-history/v1"
_LONG_NUMBER = re.compile(r"(?<!\d)\d{8,}(?!\d)")
_UUID = re.compile(r"\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b", re.IGNORECASE)
_MIN_REDACT = 3


class ExportFormatError(ValueError):
    pass


def anonymize_history(export: Any, redact: Sequence[str] = ()) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    if not isinstance(export, dict) or export.get("format") != FORMAT:
        raise ExportFormatError(f"expected format {FORMAT}")
    categories = export.get("categories")
    raw_cases = export.get("cases")
    if not isinstance(categories, list) or not isinstance(raw_cases, list):
        raise ExportFormatError("categories and cases must be lists")

    masker = Masker()
    names = _redact_pattern(redact)
    category_ids = {c["id"]: f"c-{n:02d}" for n, c in enumerate(categories, 1)}
    # Названия статей пишет пользователь: ФИО или телефон в них — тоже ПДн.
    out_categories = [
        {"id": category_ids[c["id"]], "path": _scrub(masker, names, c["path"]), "direction": c["direction"]}
        for c in categories
    ]

    seen: set[tuple[str | None, str | None, str, str]] = set()
    cases: list[dict[str, Any]] = []
    for case in raw_cases:
        expected = category_ids.get(case["expected_category_id"])
        if expected is None:
            continue
        operation = case["operation"]
        description = _scrub(masker, names, operation.get("description"))
        counterparty = _scrub(masker, names, operation.get("counterparty"))
        key = (description, counterparty, operation["direction"], expected)
        if key in seen:
            continue
        seen.add(key)
        case_id = f"h-{len(cases) + 1:04d}"
        cases.append(
            {
                "id": case_id,
                "operation": {
                    "id": case_id,
                    "direction": operation["direction"],
                    "amount": operation["amount"],
                    "currency": operation["currency"],
                    "date": operation["date"],
                    "description": description,
                    "counterparty": counterparty,
                },
                "expected_category_id": expected,
            }
        )
    return out_categories, cases


def _scrub(masker: Masker, names: re.Pattern[str] | None, text: str | None) -> str | None:
    if text is None:
        return None
    if names is not None:
        text = names.sub("[COMPANY]", text)
    return _LONG_NUMBER.sub("[NUMBER]", masker.mask_text(_UUID.sub("[ID]", text)))


def _redact_pattern(terms: Sequence[str]) -> re.Pattern[str] | None:
    """Названия компании: слова через любые пробелы, с окончанием («Вумджой» → «Вумджоя»)."""
    parts = []
    for term in terms:
        words = term.split()
        if len("".join(words)) < _MIN_REDACT:
            raise ValueError(f"redact term must have at least {_MIN_REDACT} letters: {term!r}")
        parts.append(r"\s+".join(re.escape(w) for w in words) + r"\w*")
    if not parts:
        return None
    # Длинные раньше коротких: «Рога и копыта» не режется на «Рога».
    parts.sort(key=len, reverse=True)
    return re.compile("|".join(parts), re.IGNORECASE)
