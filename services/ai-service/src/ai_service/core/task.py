"""Описание ИИ-задачи и реестр по task_type: новая функция — новая папка в tasks/, ядро не меняется (ТЗ §5)."""

from collections.abc import Callable
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Any

from pydantic import BaseModel

from ai_service.core.prompt import Prompt
from ai_service.verification.checks import Check


class Risk(StrEnum):
    LOW = "low"  # автоприменение возможно после периода подсказок
    MEDIUM = "medium"  # показывается с пометкой «сгенерировано ИИ»
    HIGH = "high"  # только черновик, всегда needs_review


@dataclass(frozen=True, slots=True)
class TaskSpec[In: BaseModel, Out: BaseModel]:
    task_type: str
    input_model: type[In]
    output_model: type[Out]
    prompt: Prompt
    risk: Risk
    build_user: Callable[[In], str]
    checks: tuple[Check[In, Out], ...] = ()
    confidence: Callable[[In, Out], float | None] = lambda _i, _o: None
    confidence_threshold: float = 0.8
    # Ключи payload, которые маскирование не трогает: идентификаторы, суммы, даты, валюта.
    mask_skip_keys: frozenset[str] = field(default_factory=frozenset)
    max_tokens: int = 1024


class TaskRegistry:
    def __init__(self, specs: list[TaskSpec[Any, Any]]) -> None:
        self._specs: dict[str, TaskSpec[Any, Any]] = {}
        for spec in specs:
            if spec.task_type in self._specs:
                raise ValueError(f"duplicate task_type {spec.task_type}")
            self._specs[spec.task_type] = spec

    def get(self, task_type: str) -> TaskSpec[Any, Any] | None:
        return self._specs.get(task_type)

    def task_types(self) -> list[str]:
        return sorted(self._specs)
