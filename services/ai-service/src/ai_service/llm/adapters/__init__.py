"""Адаптеры по семейству API (ТЗ §5.1) и сборка их по конфигу провайдеров."""

from ai_service.llm.adapters.anthropic import AnthropicAdapter
from ai_service.llm.adapters.openai_compatible import OpenAiCompatibleAdapter
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.adapters.yandexgpt import YandexGptAdapter
from ai_service.llm.routes import AdapterType, RoutingConfig
from ai_service.llm.types import LlmAdapter


def build_adapters(routing: RoutingConfig, timeout_seconds: float) -> dict[str, LlmAdapter]:
    adapters: dict[str, LlmAdapter] = {}
    for name, provider in routing.providers.items():
        if not provider.available:
            continue
        match provider.type:
            case AdapterType.STUB:
                adapters[name] = StubAdapter(provider=name)
            case AdapterType.OPENAI_COMPATIBLE:
                if not provider.base_url:
                    raise ValueError(f"provider {name}: base_url is required")
                adapters[name] = OpenAiCompatibleAdapter(
                    provider=name,
                    base_url=provider.base_url,
                    api_key=provider.api_key or "",
                    timeout=timeout_seconds,
                    extra_body=provider.extra_body,
                )
            case AdapterType.ANTHROPIC:
                adapters[name] = AnthropicAdapter(
                    provider=name, api_key=provider.api_key or "", base_url=provider.base_url, timeout=timeout_seconds
                )
            case AdapterType.YANDEXGPT:
                adapters[name] = YandexGptAdapter(
                    provider=name,
                    api_key=provider.api_key or "",
                    folder_id=provider.folder_id or "",
                    base_url=provider.base_url,
                    timeout=timeout_seconds,
                )
    return adapters
