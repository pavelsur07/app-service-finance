"""Все задачи сервиса. Новая ИИ-функция — новая папка в tasks/ и строка здесь."""

from ai_service.core.task import TaskRegistry
from ai_service.tasks.categorize_transactions import task as categorize_transactions
from ai_service.tasks.echo import task as echo
from ai_service.tasks.parse_payment_message import task as parse_payment_message


def build_registry() -> TaskRegistry:
    return TaskRegistry([echo.build(), categorize_transactions.build(), parse_payment_message.build()])
