"""Адаптер без сети: диагностическая задача echo, тесты и evals ядра.

Модели: `echo` — возвращает {"text": <содержимое блока данных>}; `fail` — всегда
повторяемая ошибка; `invalid` — ответ не JSON.
"""

import json

from ai_service.core.prompt import extract_data_block
from ai_service.llm.types import LlmError, LlmRequest, LlmResponse


class StubAdapter:
    def __init__(self, provider: str = "stub") -> None:
        self._provider = provider

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        match model:
            case "echo":
                text = json.dumps({"text": extract_data_block(request.user)}, ensure_ascii=False)
            case "invalid":
                text = "не JSON"
            case "fail":
                raise LlmError("stub failure", retryable=True, code="stub_failure")
            case _:
                raise LlmError(f"unknown stub model {model}", retryable=False, code="unknown_model")
        return LlmResponse(text=text, model=f"{self._provider}/{model}", input_tokens=0, output_tokens=0, latency_ms=0)
