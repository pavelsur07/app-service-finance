"""Маршруты задач и провайдеры из YAML (ТЗ §5.1): провайдер, модель и режим — конфиг, не код."""

import os
from dataclasses import dataclass
from enum import StrEnum
from pathlib import Path
from typing import Any

import yaml

from ai_service.llm.types import ModelRef


class Mode(StrEnum):
    SINGLE = "single"
    FALLBACK = "fallback"
    VOTE = "vote"


class AdapterType(StrEnum):
    STUB = "stub"
    OPENAI_COMPATIBLE = "openai_compatible"
    ANTHROPIC = "anthropic"
    YANDEXGPT = "yandexgpt"


@dataclass(frozen=True, slots=True)
class ProviderConfig:
    name: str
    type: AdapterType
    base_url: str | None
    api_key: str | None
    folder_id: str | None

    @property
    def available(self) -> bool:
        """Провайдер без ключа в env выключен: маршрут его пропускает."""
        if self.type is AdapterType.STUB:
            return True
        if self.type is AdapterType.YANDEXGPT:
            return bool(self.api_key) and bool(self.folder_id)
        return bool(self.api_key)


@dataclass(frozen=True, slots=True)
class Route:
    task_type: str
    mode: Mode
    models: tuple[ModelRef, ...]


@dataclass(frozen=True, slots=True)
class RoutingConfig:
    providers: dict[str, ProviderConfig]
    routes: dict[str, Route]


class RoutingConfigError(ValueError):
    pass


def load_routing(path: Path, env: dict[str, str] | None = None) -> RoutingConfig:
    raw = yaml.safe_load(path.read_text(encoding="utf-8"))
    return parse_routing(raw, dict(os.environ) if env is None else env)


def parse_routing(raw: Any, env: dict[str, str]) -> RoutingConfig:
    if not isinstance(raw, dict):
        raise RoutingConfigError("routes config must be a mapping")
    providers: dict[str, ProviderConfig] = {}
    for name, spec in (raw.get("providers") or {}).items():
        if not isinstance(spec, dict):
            raise RoutingConfigError(f"provider {name}: must be a mapping")
        try:
            adapter_type = AdapterType(str(spec.get("type")))
        except ValueError as e:
            raise RoutingConfigError(f"provider {name}: unknown type {spec.get('type')!r}") from e
        providers[name] = ProviderConfig(
            name=name,
            type=adapter_type,
            base_url=spec.get("base_url"),
            api_key=_env(env, spec.get("api_key_env")),
            folder_id=_env(env, spec.get("folder_id_env")),
        )

    routes: dict[str, Route] = {}
    for task_type, spec in (raw.get("tasks") or {}).items():
        if not isinstance(spec, dict):
            raise RoutingConfigError(f"task {task_type}: must be a mapping")
        try:
            mode = Mode(spec.get("mode", Mode.FALLBACK))
        except ValueError as e:
            raise RoutingConfigError(f"task {task_type}: unknown mode {spec.get('mode')!r}") from e
        if mode is Mode.VOTE:
            # Голосование 2 из 3 — этап 2 ТЗ; до реализации маршрут с ним не загружается.
            raise RoutingConfigError(f"task {task_type}: mode vote is not implemented yet")
        try:
            models = tuple(ModelRef.parse(m) for m in spec.get("models") or [])
        except ValueError as e:
            raise RoutingConfigError(f"task {task_type}: {e}") from e
        if not models:
            raise RoutingConfigError(f"task {task_type}: models must not be empty")
        if mode is Mode.SINGLE and len(models) != 1:
            raise RoutingConfigError(f"task {task_type}: mode single takes exactly one model")
        for ref in models:
            if ref.provider not in providers:
                raise RoutingConfigError(f"task {task_type}: unknown provider {ref.provider!r}")
        routes[task_type] = Route(task_type=task_type, mode=mode, models=models)

    return RoutingConfig(providers=providers, routes=routes)


def _env(env: dict[str, str], name: Any) -> str | None:
    if name is None:
        return None
    if not isinstance(name, str):
        raise RoutingConfigError(f"env name must be a string, got {name!r}")
    return env.get(name) or None
