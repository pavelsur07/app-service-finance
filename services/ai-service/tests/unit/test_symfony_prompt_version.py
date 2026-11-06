"""Версия промпта категоризации в Symfony — та же, что в сервисе: по ней переспрашивается «нет ответа»."""

import re
from pathlib import Path

import pytest

from ai_service.tasks.categorize_transactions import task as categorize

SERVICE = Path(__file__).resolve().parents[2]
ACTION = SERVICE.parents[1] / "site" / "src" / "Cash" / "Application" / "RequestCashAiCategorizationAction.php"


def test_symfony_knows_the_current_categorize_prompt_version() -> None:
    if not ACTION.is_file():
        pytest.skip("site/ не смонтирован (локальный контейнер сервиса)")
    match = re.search(r"PROMPT_VERSION = '([^']+)'", ACTION.read_text(encoding="utf-8"))

    assert match is not None
    assert match.group(1) == f"{categorize.TASK_TYPE}/{categorize.PROMPT_VERSION}"
