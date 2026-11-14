"""Адаптер без сети: диагностическая задача echo, тесты и evals ядра.

Модели: `echo` — возвращает {"text": <содержимое блока данных>}; `categorize` —
детерминированная эвристика для categorize_transactions (локальный e2e и прогон evals
без сети, не модель); `source-type-draft` — вид самого похожего по словам примера для
marketplace_source_type_draft; `fail` — всегда повторяемая ошибка; `invalid` — ответ не JSON.
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
            case "payment-intake":
                text = json.dumps(
                    {
                        "intent": "payments",
                        "items": [],
                        "target_ref": None,
                        "questions": ["Тестовый адаптер: разбор моделью не выполнялся."],
                    }
                )
            case "source-type-draft":
                text = json.dumps(_source_type_draft(json.loads(extract_data_block(request.user))), ensure_ascii=False)
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


def _source_type_draft(data: dict[str, Any]) -> dict[str, Any]:
    """Вид примера с наибольшим числом общих основ слов в названии; без совпадений — новый вид."""

    def stems(text: str) -> set[str]:
        return {w[:5] for w in re.findall(r"[а-яёa-z]{4,}", text.lower())}

    own = stems(data["name"])
    best = max(data["examples"], key=lambda e: len(own & stems(e["name"])), default=None)
    if best is not None and own & stems(best["name"]):
        return {
            "kind": best["kind"],
            "new_kind": None,
            "title": data["name"],
            "reason": f"как {best['source_type']}",
            "confidence": 0.9,
        }
    return {
        "kind": None,
        "new_kind": {"code": "other", "title": data["name"], "level": "overhead"},
        "title": data["name"],
        "reason": "похожих кодов нет",
        "confidence": 0.3,
    }
