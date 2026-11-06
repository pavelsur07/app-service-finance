"""Цены моделей в Symfony (учёт расходов ИИ) — копия prices.yaml сервиса."""

from pathlib import Path

import pytest
import yaml

SERVICE = Path(__file__).resolve().parents[2]
SYMFONY = SERVICE.parents[1] / "site" / "config" / "packages" / "ai_prices.yaml"


def test_symfony_prices_match_service_prices() -> None:
    if not SYMFONY.is_file():
        pytest.skip("site/ не смонтирован (локальный контейнер сервиса)")
    service = yaml.safe_load((SERVICE / "config" / "prices.yaml").read_text(encoding="utf-8"))
    symfony = yaml.safe_load(SYMFONY.read_text(encoding="utf-8"))["parameters"]["ai.prices"]

    assert symfony == service
