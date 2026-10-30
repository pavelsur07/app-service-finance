"""Anthropic Messages API через официальный SDK `anthropic`; повторы SDK выключены — ими управляет gateway."""

import time

import anthropic

from ai_service.llm.types import LlmError, LlmRequest, LlmResponse


class AnthropicAdapter:
    def __init__(
        self,
        *,
        provider: str,
        api_key: str,
        base_url: str | None,
        timeout: float,
        client: anthropic.Anthropic | None = None,
    ) -> None:
        self._provider = provider
        self._client = client or anthropic.Anthropic(api_key=api_key, base_url=base_url, timeout=timeout, max_retries=0)

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        started = time.monotonic()
        try:
            # temperature не передаётся: текущая версия Messages API отвергает параметры сэмплинга (400).
            message = self._client.messages.create(
                model=model,
                max_tokens=request.max_tokens,
                system=request.system,
                messages=[{"role": "user", "content": request.user}],
            )
        except anthropic.APIStatusError as e:
            # 429, 5xx и 529 (перегрузка) — повторяемые; остальные 4xx — ошибка запроса или ключа.
            retryable = e.status_code in (408, 409, 429) or e.status_code >= 500
            raise LlmError(f"HTTP {e.status_code}", retryable=retryable, code=f"http_{e.status_code}") from e
        except anthropic.APITimeoutError as e:
            raise LlmError("timeout", retryable=True, code="timeout") from e
        except anthropic.APIConnectionError as e:
            raise LlmError("transport error", retryable=True, code="transport_error") from e
        latency_ms = int((time.monotonic() - started) * 1000)

        if message.stop_reason == "refusal":
            # Отказ классификатора безопасности: та же модель повторно не поможет, gateway берёт следующую.
            raise LlmError("model refused", retryable=False, code="refusal")
        text = "".join(block.text for block in message.content if isinstance(block, anthropic.types.TextBlock))
        return LlmResponse(
            text=text,
            model=f"{self._provider}/{model}",
            input_tokens=int(message.usage.input_tokens),
            output_tokens=int(message.usage.output_tokens),
            latency_ms=latency_ms,
        )
