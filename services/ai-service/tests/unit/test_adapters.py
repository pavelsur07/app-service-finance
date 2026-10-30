import json
from typing import Any

import anthropic
import httpx
import httpx2
import pytest

from ai_service.llm.adapters.anthropic import AnthropicAdapter
from ai_service.llm.adapters.openai_compatible import OpenAiCompatibleAdapter
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.adapters.yandexgpt import YandexGptAdapter
from ai_service.llm.types import LlmError, LlmRequest

REQ = LlmRequest(system="sys", user="usr", max_tokens=100)


def test_openai_compatible_request_and_response() -> None:
    seen: dict[str, Any] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers["authorization"]
        seen["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={
                "choices": [{"message": {"role": "assistant", "content": '{"text": "x"}'}}],
                "usage": {"prompt_tokens": 12, "completion_tokens": 3},
            },
        )

    adapter = OpenAiCompatibleAdapter(
        provider="deepseek",
        base_url="https://api.example/v1/",
        api_key="key",
        timeout=5,
        transport=httpx.MockTransport(handler),
    )
    response = adapter.complete("model-x", REQ)
    assert seen["url"] == "https://api.example/v1/chat/completions"
    assert seen["auth"] == "Bearer key"
    assert seen["body"]["messages"] == [{"role": "system", "content": "sys"}, {"role": "user", "content": "usr"}]
    assert seen["body"]["temperature"] == 0.0
    assert (response.text, response.model, response.input_tokens, response.output_tokens) == (
        '{"text": "x"}',
        "deepseek/model-x",
        12,
        3,
    )


@pytest.mark.parametrize(
    ("status", "retryable"), [(429, True), (500, True), (503, True), (400, False), (401, False), (404, False)]
)
def test_openai_compatible_status_classification(status: int, retryable: bool) -> None:
    adapter = OpenAiCompatibleAdapter(
        provider="p",
        base_url="http://x",
        api_key="k",
        timeout=5,
        transport=httpx.MockTransport(lambda _r: httpx.Response(status, json={"error": "secret body"})),
    )
    with pytest.raises(LlmError) as e:
        adapter.complete("m", REQ)
    assert e.value.retryable is retryable
    assert e.value.code == f"http_{status}"
    assert "secret body" not in str(e.value)


def test_openai_compatible_timeout_is_retryable() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("slow", request=request)

    adapter = OpenAiCompatibleAdapter(
        provider="p", base_url="http://x", api_key="k", timeout=5, transport=httpx.MockTransport(handler)
    )
    with pytest.raises(LlmError) as e:
        adapter.complete("m", REQ)
    assert (e.value.retryable, e.value.code) == (True, "timeout")


def test_openai_compatible_unexpected_shape() -> None:
    adapter = OpenAiCompatibleAdapter(
        provider="p",
        base_url="http://x",
        api_key="k",
        timeout=5,
        transport=httpx.MockTransport(lambda _r: httpx.Response(200, json={"choices": []})),
    )
    with pytest.raises(LlmError, match="unexpected response shape"):
        adapter.complete("m", REQ)


def test_yandexgpt_request_and_response() -> None:
    seen: dict[str, Any] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["headers"] = dict(request.headers)
        seen["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={
                "result": {
                    "alternatives": [
                        {"message": {"role": "assistant", "text": "ответ"}, "status": "ALTERNATIVE_STATUS_FINAL"}
                    ],
                    "usage": {"inputTextTokens": "21", "completionTokens": "4", "totalTokens": "25"},
                    "modelVersion": "x",
                }
            },
        )

    adapter = YandexGptAdapter(
        provider="yandex",
        api_key="key",
        folder_id="b1g",
        base_url=None,
        timeout=5,
        transport=httpx.MockTransport(handler),
    )
    response = adapter.complete("yandexgpt/latest", REQ)
    assert seen["url"] == "https://llm.api.cloud.yandex.net/foundationModels/v1/completion"
    assert seen["headers"]["authorization"] == "Api-Key key"
    assert seen["headers"]["x-folder-id"] == "b1g"
    assert seen["body"]["modelUri"] == "gpt://b1g/yandexgpt/latest"
    assert seen["body"]["completionOptions"]["maxTokens"] == "100"
    assert seen["body"]["messages"][0] == {"role": "system", "text": "sys"}
    assert (response.text, response.input_tokens, response.output_tokens) == ("ответ", 21, 4)


def _anthropic(handler: Any) -> AnthropicAdapter:
    client = anthropic.Anthropic(
        api_key="key",
        base_url="https://anthropic.test",
        max_retries=0,
        http_client=anthropic.DefaultHttpxClient(transport=httpx2.MockTransport(handler)),
    )
    return AnthropicAdapter(provider="anthropic", api_key="key", base_url=None, timeout=5, client=client)


def _message(stop_reason: str, text: str = '{"text": "x"}') -> dict[str, Any]:
    return {
        "id": "msg_1",
        "type": "message",
        "role": "assistant",
        "model": "m",
        "content": [{"type": "text", "text": text}],
        "stop_reason": stop_reason,
        "stop_sequence": None,
        "usage": {"input_tokens": 30, "output_tokens": 7},
    }


def test_anthropic_request_and_response() -> None:
    seen: dict[str, Any] = {}

    def handler(request: httpx2.Request) -> httpx2.Response:
        seen["path"] = request.url.path
        seen["auth"] = request.headers["authorization"]
        seen["version"] = request.headers["anthropic-version"]
        seen["body"] = json.loads(request.content)
        return httpx2.Response(200, json=_message("end_turn"))

    response = _anthropic(handler).complete("claude-model", REQ)
    assert seen["path"] == "/v1/messages"
    assert seen["auth"] == "Bearer key"
    # С версии API 2026-10-21 параметры сэмплинга запрещены — адаптер их не шлёт.
    assert seen["version"] >= "2026-10-21"
    assert seen["body"]["system"] == "sys"
    assert seen["body"]["messages"] == [{"role": "user", "content": "usr"}]
    assert "temperature" not in seen["body"]
    assert (response.text, response.model, response.input_tokens, response.output_tokens) == (
        '{"text": "x"}',
        "anthropic/claude-model",
        30,
        7,
    )


def test_anthropic_refusal_is_not_retryable() -> None:
    adapter = _anthropic(lambda _r: httpx2.Response(200, json=_message("refusal", "")))
    with pytest.raises(LlmError) as e:
        adapter.complete("m", REQ)
    assert (e.value.retryable, e.value.code) == (False, "refusal")


@pytest.mark.parametrize(("status", "retryable"), [(429, True), (529, True), (500, True), (400, False), (401, False)])
def test_anthropic_status_classification(status: int, retryable: bool) -> None:
    body = {"type": "error", "error": {"type": "x", "message": "m"}}
    adapter = _anthropic(lambda _r: httpx2.Response(status, json=body))
    with pytest.raises(LlmError) as e:
        adapter.complete("m", REQ)
    assert (e.value.retryable, e.value.code) == (retryable, f"http_{status}")


def test_stub_models() -> None:
    stub = StubAdapter()
    assert json.loads(stub.complete("echo", LlmRequest("s", "x <data>\nпривет\n</data>")).text) == {"text": "привет"}
    assert stub.complete("invalid", REQ).text == "не JSON"
    with pytest.raises(LlmError):
        stub.complete("fail", REQ)
