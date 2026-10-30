"""YandexGPT, Foundation Models API (completion): российская модель со своим API."""

from typing import Any

import httpx

from ai_service.llm.adapters._http import bad_response, post_json
from ai_service.llm.types import LlmRequest, LlmResponse

_DEFAULT_BASE_URL = "https://llm.api.cloud.yandex.net"


class YandexGptAdapter:
    def __init__(
        self,
        *,
        provider: str,
        api_key: str,
        folder_id: str,
        base_url: str | None,
        timeout: float,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        self._provider = provider
        self._url = (base_url or _DEFAULT_BASE_URL).rstrip("/") + "/foundationModels/v1/completion"
        self._api_key = api_key
        self._folder_id = folder_id
        self._client = httpx.Client(timeout=timeout, transport=transport)

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        body: dict[str, Any] = {
            # model — имя в каталоге, например yandexgpt/latest; каталог (folder) — из env.
            "modelUri": f"gpt://{self._folder_id}/{model}",
            "completionOptions": {
                "stream": False,
                "temperature": request.temperature,
                "maxTokens": str(request.max_tokens),
            },
            "messages": [
                {"role": "system", "text": request.system},
                {"role": "user", "text": request.user},
            ],
        }
        headers = {"Authorization": f"Api-Key {self._api_key}", "x-folder-id": self._folder_id}
        data, latency_ms = post_json(self._client, self._url, headers, body)
        try:
            result = data["result"]
            text = result["alternatives"][0]["message"]["text"]
            usage = result.get("usage") or {}
            input_tokens = int(usage.get("inputTextTokens", 0))
            output_tokens = int(usage.get("completionTokens", 0))
        except (KeyError, IndexError, TypeError, ValueError) as e:
            raise bad_response("result.alternatives[0].message.text") from e
        if not isinstance(text, str):
            raise bad_response("text is not a string")
        return LlmResponse(
            text=text,
            model=f"{self._provider}/{model}",
            input_tokens=input_tokens,
            output_tokens=output_tokens,
            latency_ms=latency_ms,
        )
