"""LLM Gateway: маршрут задачи → адаптеры с повторами и резервом (ТЗ §5, §5.1)."""

import structlog

from ai_service.llm.routes import Mode, RoutingConfig
from ai_service.llm.types import CallRecord, GatewayResult, LlmAdapter, LlmError, LlmRequest, LlmUnavailableError

log = structlog.get_logger(__name__)


class UnknownRouteError(LookupError):
    pass


class LlmGateway:
    def __init__(self, routing: RoutingConfig, adapters: dict[str, LlmAdapter], retries_per_model: int = 1) -> None:
        self._routing = routing
        self._adapters = adapters
        self._retries = max(0, retries_per_model)

    def has_route(self, task_type: str) -> bool:
        return task_type in self._routing.routes

    def complete(self, task_type: str, request: LlmRequest) -> GatewayResult:
        route = self._routing.routes.get(task_type)
        if route is None:
            raise UnknownRouteError(task_type)

        calls: list[CallRecord] = []
        for ref in route.models:
            adapter = self._adapters.get(ref.provider)
            if adapter is None or not self._routing.providers[ref.provider].available:
                calls.append(CallRecord(model=ref.key, ok=False, error="provider_disabled"))
                continue
            for attempt in range(self._retries + 1):
                try:
                    response = adapter.complete(ref.model, request)
                except LlmError as e:
                    calls.append(CallRecord(model=ref.key, ok=False, error=e.code))
                    log.warning("llm_call_failed", model=ref.key, error=e.code, attempt=attempt, retryable=e.retryable)
                    if not e.retryable:
                        break
                    continue
                calls.append(
                    CallRecord(
                        model=ref.key,
                        ok=True,
                        latency_ms=response.latency_ms,
                        input_tokens=response.input_tokens,
                        output_tokens=response.output_tokens,
                    )
                )
                return GatewayResult(response=response, calls=calls)
            if route.mode is Mode.SINGLE:
                break
        raise LlmUnavailableError(calls)
