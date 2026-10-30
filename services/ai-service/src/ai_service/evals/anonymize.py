"""Обезличивание выгрузки `app:cash:ai-eval-export` перед коммитом в репозиторий (ТЗ §8).

- назначение и контрагент — тем же `Masker`, что маскирует вход моделей в проде (ФИО, телефоны,
  e-mail, счета, карты, ИНН физлиц), плюс любые номера от 8 цифр (договоры, ИНН организаций);
- id операций и статей — в последовательные (`h-0001`, `c-01`); компании в наборе нет;
- дубли «назначение + контрагент + направление + статья» схлопываются: они не добавляют
  проверки, только вес частым платежам.

Суммы и даты остаются; пути статей маскируются так же, как назначения. Результат смотрит
человек до коммита — эвристика маскирования не гарантирует полноту.
"""

import re
from typing import Any

from ai_service.privacy.masking import Masker

FORMAT = "cash-categorize-history/v1"
_LONG_NUMBER = re.compile(r"(?<!\d)\d{8,}(?!\d)")


class ExportFormatError(ValueError):
    pass


def anonymize_history(export: Any) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    if not isinstance(export, dict) or export.get("format") != FORMAT:
        raise ExportFormatError(f"expected format {FORMAT}")
    categories = export.get("categories")
    raw_cases = export.get("cases")
    if not isinstance(categories, list) or not isinstance(raw_cases, list):
        raise ExportFormatError("categories and cases must be lists")

    masker = Masker()
    category_ids = {c["id"]: f"c-{n:02d}" for n, c in enumerate(categories, 1)}
    # Названия статей пишет пользователь: ФИО или телефон в них — тоже ПДн.
    out_categories = [
        {"id": category_ids[c["id"]], "path": _scrub(masker, c["path"]), "direction": c["direction"]}
        for c in categories
    ]

    seen: set[tuple[str | None, str | None, str, str]] = set()
    cases: list[dict[str, Any]] = []
    for case in raw_cases:
        expected = category_ids.get(case["expected_category_id"])
        if expected is None:
            continue
        operation = case["operation"]
        description = _scrub(masker, operation.get("description"))
        counterparty = _scrub(masker, operation.get("counterparty"))
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


def _scrub(masker: Masker, text: str | None) -> str | None:
    if text is None:
        return None
    return _LONG_NUMBER.sub("[NUMBER]", masker.mask_text(text))
