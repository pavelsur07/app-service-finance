"""Порт LLM: запрос, ответ и ошибки, общие для всех адаптеров. Задачи не импортируют SDK провайдеров."""

from dataclasses import dataclass, field
from typing import Protocol


@dataclass(frozen=True, slots=True)
class LlmRequest:
    system: str
    user: str
    max_tokens: int = 1024
    # temperature 0 для извлечения и классификации (ТЗ §6); адаптер не шлёт её туда, где API её не принимает.
    temperature: float = 0.0


@dataclass(frozen=True, slots=True)
class LlmResponse:
    text: str
    model: str  # provider/model
    input_tokens: int
    output_tokens: int
    latency_ms: int


@dataclass(frozen=True, slots=True)
class ModelRef:
    provider: str
    model: str

    @property
    def key(self) -> str:
        return f"{self.provider}/{self.model}"

    @classmethod
    def parse(cls, value: str) -> "ModelRef":
        provider, sep, model = value.partition("/")
        if not sep or not provider or not model:
            raise ValueError(f"model ref must be provider/model, got {value!r}")
        return cls(provider, model)


class LlmError(Exception):
    """Сбой вызова модели. retryable — повтор той же модели имеет смысл (429, 5xx, сеть, таймаут)."""

    def __init__(self, message: str, *, retryable: bool, code: str = "provider_error") -> None:
        super().__init__(message)
        self.retryable = retryable
        self.code = code


@dataclass(slots=True)
class CallRecord:
    model: str
    ok: bool
    error: str | None = None
    latency_ms: int = 0
    input_tokens: int = 0
    output_tokens: int = 0


@dataclass(slots=True)
class GatewayResult:
    response: LlmResponse
    calls: list[CallRecord] = field(default_factory=list)


class LlmUnavailableError(Exception):
    """Ни одна модель маршрута не ответила. Задача вернётся в очередь (повтор worker'а)."""

    def __init__(self, calls: list[CallRecord]) -> None:
        super().__init__("no model of the route answered")
        self.calls = calls


class LlmAdapter(Protocol):
    """Адаптер семейства API. Повторов внутри нет — ими управляет gateway."""

    def complete(self, model: str, request: LlmRequest) -> LlmResponse: ...
