"""Проверки связи через шлюз (gateway/plan.md, «Проверки подключения»): 2, 4 — gateway-check, 3 — llm-ping."""

import httpx
import pytest

from ai_service.llm.connectivity import CheckLine, gateway_check, llm_ping, route_providers
from ai_service.llm.routes import RoutingConfig, parse_routing
from ai_service.llm.types import LlmError, LlmRequest, LlmResponse

TOKEN = "gw-token-0123456789abcdef0123456789"
RAW = {
    "providers": {
        "stub": {"type": "stub"},
        "deepseek": {
            "type": "openai_compatible",
            "base_url": "https://gw.test/deepseek/v1",
            "api_key_env": "AI_GATEWAY_TOKEN",
        },
        "openai": {
            "type": "openai_compatible",
            "base_url": "https://gw.test/openai/v1",
            "api_key_env": "AI_GATEWAY_TOKEN",
        },
        "kimi": {"type": "openai_compatible", "base_url": "https://gw.test/kimi/v1", "api_key_env": "AI_GATEWAY_TOKEN"},
    },
    "tasks": {
        "echo": {"mode": "single", "models": ["stub/echo"]},
        "a": {"mode": "fallback", "models": ["deepseek/chat", "openai/mini"]},
        "b": {"mode": "single", "models": ["openai/big"]},
    },
}


def routing(token: str | None = TOKEN) -> RoutingConfig:
    return parse_routing(RAW, {"AI_GATEWAY_TOKEN": token} if token else {})


def test_route_providers_skip_stub_and_unrouted_in_route_order() -> None:
    assert route_providers(routing()) == ["deepseek", "openai"]


def gateway(statuses: dict[str, tuple[int, int]], seen: list[httpx.Request]) -> httpx.MockTransport:
    """statuses: провайдер → (статус с токеном, статус без токена)."""

    def handler(request: httpx.Request) -> httpx.Response:
        seen.append(request)
        provider = request.url.path.split("/")[1]
        if provider in statuses and isinstance(statuses[provider], Exception):
            raise statuses[provider]  # type: ignore[misc]
        with_token, without = statuses[provider]
        if request.headers.get("authorization") == f"Bearer {TOKEN}":
            return httpx.Response(with_token, json={"data": []})
        return httpx.Response(without, json={"error": {"code": "unauthorized"}})

    return httpx.MockTransport(handler)


def test_gateway_check_ok_when_models_200_with_token_and_401_without() -> None:
    seen: list[httpx.Request] = []
    lines = gateway_check(routing(), transport=gateway({"deepseek": (200, 401), "openai": (200, 401)}, seen))
    assert lines == [CheckLine("deepseek", True, "200/401"), CheckLine("openai", True, "200/401")]
    assert {(r.method, str(r.url)) for r in seen} == {
        ("GET", "https://gw.test/deepseek/v1/models"),
        ("GET", "https://gw.test/openai/v1/models"),
    }
    assert sorted(r.headers.get("authorization", "-") for r in seen) == ["-", "-", f"Bearer {TOKEN}", f"Bearer {TOKEN}"]


@pytest.mark.parametrize(
    ("statuses", "detail"),
    [
        ((403, 403), "403/403"),  # не с IP app
        ((503, 401), "503/401"),  # провайдер не настроен на шлюзе
        ((200, 200), "200/200"),  # шлюз пустил без токена
    ],
)
def test_gateway_check_fails_on_unexpected_status(statuses: tuple[int, int], detail: str) -> None:
    lines = gateway_check(routing(), transport=gateway({"deepseek": (200, 401), "openai": statuses}, []))
    assert lines[1] == CheckLine("openai", False, detail)
    assert lines[0].ok


def test_gateway_check_reports_transport_error() -> None:
    lines = gateway_check(
        routing(),
        transport=gateway({"deepseek": httpx.ConnectError("refused"), "openai": (200, 401)}, []),  # type: ignore[dict-item]
    )
    assert lines[0] == CheckLine("deepseek", False, "transport_error")


def test_without_token_both_checks_fail_without_http() -> None:
    seen: list[httpx.Request] = []
    assert gateway_check(routing(token=None), transport=gateway({}, seen)) == [
        CheckLine("deepseek", False, "no_token"),
        CheckLine("openai", False, "no_token"),
    ]
    assert seen == []
    assert llm_ping(routing(token=None), {}) == [
        CheckLine("deepseek", False, "no_token"),
        CheckLine("openai", False, "no_token"),
    ]


class FakeAdapter:
    def __init__(self, error: LlmError | None = None) -> None:
        self.error = error
        self.calls: list[tuple[str, LlmRequest]] = []

    def complete(self, model: str, request: LlmRequest) -> LlmResponse:
        self.calls.append((model, request))
        if self.error:
            raise self.error
        return LlmResponse(text="ok, secret answer", model=model, input_tokens=9, output_tokens=1, latency_ms=321)


def test_llm_ping_asks_first_model_of_each_provider_with_five_tokens() -> None:
    deepseek, openai = FakeAdapter(), FakeAdapter()
    lines = llm_ping(routing(), {"deepseek": deepseek, "openai": openai})
    assert lines == [CheckLine("deepseek", True, "deepseek/chat 321ms"), CheckLine("openai", True, "openai/mini 321ms")]
    assert [m for m, _ in deepseek.calls + openai.calls] == ["chat", "mini"]
    assert all(r.max_tokens == 5 and "ok" in r.user for _, r in deepseek.calls + openai.calls)
    assert all("secret answer" not in line.render() for line in lines)


def test_llm_ping_reports_error_code() -> None:
    lines = llm_ping(
        routing(), {"deepseek": FakeAdapter(LlmError("x", retryable=True, code="http_503")), "openai": FakeAdapter()}
    )
    assert lines[0] == CheckLine("deepseek", False, "http_503")
    assert lines[1].ok


def test_render() -> None:
    assert CheckLine("openai", True, "200/401").render() == "openai: ok 200/401"
    assert CheckLine("kimi", False, "no_token").render() == "kimi: fail no_token"
