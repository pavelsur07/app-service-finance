"""Маскирование ПДн до вызова модели (ТЗ §7): ФИО, телефоны, e-mail, счета и карты, ИНН физлиц.

Таблица подстановки живёт в памяти на время задачи; в MVP маскирование одностороннее.
Эвристика на регулярных выражениях: ловит типовые формы, не гарантирует полноту.
"""

import re
from collections.abc import Iterable
from dataclasses import dataclass, field
from typing import Any

_PATRONYMIC = r"[А-ЯЁ][а-яё]+(?:ович|евич|ич|овна|евна|ична|инична)"
_NAME = r"[А-ЯЁ][а-яё]+"

# Порядок важен: длинные цифровые последовательности — раньше коротких.
_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("EMAIL", re.compile(r"[\w.+-]+@[\w-]+(?:\.[\w-]+)+")),
    ("ACCOUNT", re.compile(r"(?<!\d)\d{20}(?!\d)")),
    ("CARD", re.compile(r"(?<!\d)\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{4}(?!\d)")),
    ("PHONE", re.compile(r"(?<![\w+])(?:\+7|8)[\s(-]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)")),
    # ИНН физлица — 12 цифр; ИНН организации (10 цифр) не персональные данные и остаётся.
    ("INN", re.compile(r"(?<!\d)\d{12}(?!\d)")),
    # Фамилия Имя Отчество / Имя Отчество Фамилия.
    ("PERSON", re.compile(rf"\b{_NAME}\s+{_NAME}\s+{_PATRONYMIC}\b|\b{_NAME}\s+{_PATRONYMIC}\s+{_NAME}\b")),
    # Фамилия И. О. / И. О. Фамилия.
    ("PERSON", re.compile(rf"\b{_NAME}\s+[А-ЯЁ]\.\s?[А-ЯЁ]\.(?!\w)|(?<!\w)[А-ЯЁ]\.\s?[А-ЯЁ]\.\s?{_NAME}\b")),
)


@dataclass(slots=True)
class Masker:
    """Заменяет ПДн токенами вида [PHONE_1]; одно значение в задаче — один и тот же токен."""

    _tokens: dict[str, str] = field(default_factory=dict)
    _counters: dict[str, int] = field(default_factory=dict)

    @property
    def replacements(self) -> int:
        return len(self._tokens)

    def mask_text(self, text: str) -> str:
        for kind, pattern in _PATTERNS:
            text = pattern.sub(lambda m, kind=kind: self._token(kind, m.group(0)), text)  # type: ignore[misc]
        return text

    def mask_json(self, value: Any, skip_keys: Iterable[str] = ()) -> Any:
        """Маскирует строки в JSON-структуре; ключи из skip_keys (идентификаторы, суммы, даты) не трогает."""
        skip = frozenset(skip_keys)
        return self._walk(value, skip)

    def _walk(self, value: Any, skip: frozenset[str]) -> Any:
        if isinstance(value, str):
            return self.mask_text(value)
        if isinstance(value, list):
            return [self._walk(v, skip) for v in value]
        if isinstance(value, dict):
            return {k: (v if k in skip else self._walk(v, skip)) for k, v in value.items()}
        return value

    def _token(self, kind: str, original: str) -> str:
        if original not in self._tokens:
            self._counters[kind] = self._counters.get(kind, 0) + 1
            self._tokens[original] = f"[{kind}_{self._counters[kind]}]"
        return self._tokens[original]
