"""Общая обработка HTTP-ответов адаптеров на httpx: классификация ошибок без тела ответа в логах."""

import time
from typing import Any

import httpx

from ai_service.llm.types import LlmError

_RETRYABLE_STATUSES = frozenset({408, 409, 429})


def post_json(client: httpx.Client, url: str, headers: dict[str, str], body: dict[str, Any]) -> tuple[Any, int]:
    started = time.monotonic()
    try:
        response = client.post(url, headers=headers, json=body)
    except httpx.TimeoutException as e:
        raise LlmError("timeout", retryable=True, code="timeout") from e
    except httpx.TransportError as e:
        raise LlmError("transport error", retryable=True, code="transport_error") from e
    latency_ms = int((time.monotonic() - started) * 1000)
    status = response.status_code
    if status >= 500 or status in _RETRYABLE_STATUSES:
        raise LlmError(f"HTTP {status}", retryable=True, code=f"http_{status}")
    if status != 200:
        raise LlmError(f"HTTP {status}", retryable=False, code=f"http_{status}")
    try:
        return response.json(), latency_ms
    except ValueError as e:
        raise LlmError("response is not JSON", retryable=True, code="bad_response") from e


def bad_response(what: str) -> LlmError:
    return LlmError(f"unexpected response shape: {what}", retryable=False, code="bad_response")
