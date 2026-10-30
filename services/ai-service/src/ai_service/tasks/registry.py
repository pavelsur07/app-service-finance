"""Все задачи сервиса. Новая ИИ-функция — новая папка в tasks/ и строка здесь."""

from ai_service.core.task import TaskRegistry
from ai_service.tasks.echo import task as echo


def build_registry() -> TaskRegistry:
    return TaskRegistry([echo.build()])
