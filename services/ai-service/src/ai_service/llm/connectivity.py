"""Проверки связи с провайдерами через шлюз (docs/tasks/ai/gateway/plan.md, «Проверки подключения»).

gateway_check — проверки 2 и 4 (бесплатно): `GET <base_url>/models` с токеном → 200, без токена → 401.
llm_ping — проверка 3: один короткий чат-запрос к первой модели каждого провайдера маршрутов.
Диагностика: в pipeline задач не участвует, трассы не пишет, токен и ответ модели не печатает.
"""

from dataclasses import dataclass

import httpx

from ai_service.llm.routes import AdapterType, RoutingConfig
from ai_service.llm.types import LlmAdapter, LlmError, LlmRequest, ModelRef

PING = LlmRequest(system="Проверка связи.", user="Ответь одним словом: ok", max_tokens=5)


@dataclass(frozen=True, slots=True)
class CheckLine:
    provider: str
    ok: bool
    detail: str

    def render(self) -> str:
        return f"{self.provider}: {'ok' if self.ok else 'fail'} {self.detail}"


def route_providers(routing: RoutingConfig) -> list[str]:
    """Провайдеры маршрутов, кроме stub, в порядке первого появления."""
    return list(dict.fromkeys(ref.provider for ref in _route_models(routing)))


def gateway_check(
    routing: RoutingConfig, *, timeout: float = 10.0, transport: httpx.BaseTransport | None = None
) -> list[CheckLine]:
    lines: list[CheckLine] = []
    with httpx.Client(timeout=timeout, transport=transport) as client:
        for name in route_providers(routing):
            provider = routing.providers[name]
            if not provider.available:
                lines.append(CheckLine(name, False, "no_token"))
                continue
            url = (provider.base_url or "").rstrip("/") + "/models"
            try:
                with_token = client.get(url, headers={"Authorization": f"Bearer {provider.api_key}"}).status_code
                without = client.get(url).status_code
            except httpx.TransportError:
                lines.append(CheckLine(name, False, "transport_error"))
                continue
            lines.append(CheckLine(name, with_token == 200 and without == 401, f"{with_token}/{without}"))
    return lines


def llm_ping(routing: RoutingConfig, adapters: dict[str, LlmAdapter]) -> list[CheckLine]:
    lines: list[CheckLine] = []
    for name in route_providers(routing):
        adapter = adapters.get(name)
        if adapter is None or not routing.providers[name].available:
            lines.append(CheckLine(name, False, "no_token"))
            continue
        ref = next(r for r in _route_models(routing) if r.provider == name)
        try:
            response = adapter.complete(ref.model, PING)
        except LlmError as e:
            lines.append(CheckLine(name, False, e.code))
            continue
        lines.append(CheckLine(name, True, f"{ref.key} {response.latency_ms}ms"))
    return lines


def _route_models(routing: RoutingConfig) -> list[ModelRef]:
    return [
        ref
        for route in routing.routes.values()
        for ref in route.models
        if routing.providers[ref.provider].type is not AdapterType.STUB
    ]
