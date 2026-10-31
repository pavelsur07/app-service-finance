"""OpenAI-совместимый Chat Completions: OpenAI, DeepSeek, vLLM, Ollama и другие с тем же API."""

from typing import Any

import httpx

from ai_service.llm.adapters._http import bad_response, post_json
from ai_service.llm.types import LlmRequest, LlmResponse


class OpenAiCompatibleAdapter:
    def __init__(
        self,
        *,
        provider: str,
        base_url: str,
        api_key: str,
        timeout: float,
        extra_body: dict[str, Any] | None = None,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self._provider = provider
        self._extra_body = dict(extra_body or {})
        self._url = base_url.rstrip("/") + "/chat/completions"
        self._api_key = api_key
        self._client = httpx.Client(timeout=timeout, transport=transport)

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        body: dict[str, Any] = {
            "model": model,
            "messages": [
                {"role": "system", "content": request.system},
                {"role": "user", "content": request.user},
            ],
            "max_tokens": request.max_tokens,
            "temperature": request.temperature,
        }
        body.update(self._extra_body)
        data, latency_ms = post_json(self._client, self._url, {"Authorization": f"Bearer {self._api_key}"}, body)
        try:
            text = data["choices"][0]["message"]["content"]
            usage = data.get("usage") or {}
            input_tokens = int(usage.get("prompt_tokens", 0))
            output_tokens = int(usage.get("completion_tokens", 0))
        except (KeyError, IndexError, TypeError, ValueError) as e:
            raise bad_response("choices[0].message.content") from e
        if not isinstance(text, str):
            raise bad_response("content is not a string")
        return LlmResponse(
            text=text,
            model=f"{self._provider}/{model}",
            input_tokens=input_tokens,
            output_tokens=output_tokens,
            latency_ms=latency_ms,
        )
