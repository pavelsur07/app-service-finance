from decimal import Decimal

import pytest
from pydantic import ValidationError

from ai_service.config import Settings


def test_nightly_is_explicit_and_has_owner_approved_budget_defaults() -> None:
    settings = Settings(db_dsn="postgresql://localhost/example_test", api_token="test-token-at-least-sixteen")
    assert settings.eval_enabled is False
    assert settings.eval_daily_limit_rub == Decimal("30")
    assert settings.eval_monthly_limit_rub == Decimal("900")


@pytest.mark.parametrize("field", ["eval_daily_limit_rub", "eval_monthly_limit_rub"])
@pytest.mark.parametrize("value", ["0", "-1", "NaN", "Infinity"])
def test_nightly_budget_is_finite_positive(field: str, value: str) -> None:
    with pytest.raises(ValidationError):
        Settings.model_validate(
            {"db_dsn": "postgresql://localhost/example_test", "api_token": "test-token-at-least-sixteen", field: value}
        )


def test_eval_worker_has_a_separate_cli_entrypoint(capsys: pytest.CaptureFixture[str]) -> None:
    from ai_service.__main__ import main

    with pytest.raises(SystemExit) as result:
        main(["eval-worker", "--help"])
    assert result.value.code == 0
    assert "eval-worker" in capsys.readouterr().out
