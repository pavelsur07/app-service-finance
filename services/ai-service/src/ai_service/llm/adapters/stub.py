"""Адаптер без сети: диагностическая задача echo, тесты и evals ядра.

Модели: `echo` — возвращает {"text": <содержимое блока данных>}; `categorize` —
детерминированная эвристика для categorize_transactions (локальный e2e и прогон evals
без сети, не модель); `fail` — всегда повторяемая ошибка; `invalid` — ответ не JSON.
"""

import json
import re
from typing import Any

from ai_service.core.prompt import extract_data_block
from ai_service.llm.types import LlmError, LlmRequest, LlmResponse


class StubAdapter:
    def __init__(self, provider: str = "stub") -> None:
        self._provider = provider

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        match model:
            case "echo":
                text = json.dumps({"text": extract_data_block(request.user)}, ensure_ascii=False)
            case "categorize":
                text = json.dumps(_categorize(json.loads(extract_data_block(request.user))))
            case "invalid":
                text = "не JSON"
            case "fail":
                raise LlmError("stub failure", retryable=True, code="stub_failure")
            case _:
                raise LlmError(f"unknown stub model {model}", retryable=False, code="unknown_model")
        return LlmResponse(text=text, model=f"{self._provider}/{model}", input_tokens=0, output_tokens=0, latency_ms=0)


def _categorize(data: dict[str, Any]) -> dict[str, Any]:
    """Статья — первая подходящая по направлению, у которой основа слова из названия есть в тексте операции."""
    items = []
    for op in data["operations"]:
        text = f"{op.get('description') or ''} {op.get('counterparty') or ''}".lower()
        found = None
        for category in data["categories"]:
            if category["direction"] not in ("any", op["direction"]):
                continue
            name = category["path"].rsplit("/", 1)[-1].lower()
            stems = [w[:5] for w in re.findall(r"[а-яёa-z]{4,}", name)]
            if any(stem in text for stem in stems):
                found = category["id"]
                break
        items.append({"operation_id": op["id"], "category_id": found, "confidence": 0.9 if found else 0.0})
    return {"items": items}
