"""Offline preflight and immutable configuration identity, without network or DB."""

from datetime import UTC, date, datetime
from decimal import Decimal
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from uuid import uuid4

import pytest

from ai_service.evals.payment_intake_nightly import (
    EvalLimits,
    NightlyEvaluator,
    configuration_fingerprint,
    load_prices,
)
from ai_service.llm.routes import parse_routing


def routing(key: str = "secret", extra: str = "disabled") -> Any:
    return parse_routing(
        {
            "providers": {
                "one": {"type": "openai_compatible", "api_key_env": "KEY", "extra_body": {"thinking": extra}}
            },
            "tasks": {"parse_payment_message": {"models": ["one/first", "one/second"]}},
        },
        {"KEY": key},
    )


def claim(fingerprint: str = "0" * 64) -> Any:
    return SimpleNamespace(
        run_id=uuid4(),
        suite="payment_intake",
        run_date=date(2026, 11, 14),
        trace_id=uuid4(),
        attempt=1,
        configuration_fingerprint=fingerprint,
    )


def test_fingerprint_excludes_credentials_but_covers_route_and_prices() -> None:
    prices = {"one/first": (Decimal("1.25"), Decimal("2.50"))}
    baseline = configuration_fingerprint(routing(), prices)
    assert baseline == configuration_fingerprint(routing("rotated-secret"), prices)
    assert baseline != configuration_fingerprint(routing(extra="enabled"), prices)
    assert baseline != configuration_fingerprint(routing(), {"one/first": (Decimal("1.26"), Decimal("2.50"))})


def test_prices_parse_decimal_without_binary_float(tmp_path: Path) -> None:
    path = tmp_path / "prices.yaml"
    path.write_text(
        "one/first: {input: 0.123456789123456789, output: '9.876543210987654321'}\n"
        "unknown/model: {input: null, output: null}\n"
    )
    assert load_prices(path) == {"one/first": (Decimal("0.123456789123456789"), Decimal("9.876543210987654321"))}


def test_missing_assets_and_configuration_drift_never_touch_db_or_adapter(tmp_path: Path) -> None:
    for root, fingerprint, expected in [
        (tmp_path, "0" * 64, "invalid_runtime_pack"),
        (None, "0" * 64, "configuration_changed"),
    ]:
        evaluator = NightlyEvaluator(
            object(),
            object(),
            routing=routing(),
            adapters={},
            prices={},
            limits=EvalLimits(),
            assets_root=root,
            clock=lambda: datetime(2026, 11, 14, tzinfo=UTC),
        )
        result = evaluator.run(claim(fingerprint))
        assert result.status == "incomplete"
        assert result.error_code == expected
        assert result.report["network_calls"] == 0


@pytest.mark.parametrize(
    "changes", [{"day_limit": Decimal("0")}, {"max_calls": 165}, {"max_model_calls": 83}, {"deadline_seconds": 0}]
)
def test_limits_reject_unapproved_caps(changes: dict[str, Any]) -> None:
    with pytest.raises(ValueError, match="invalid_eval_limits"):
        EvalLimits(**changes)


def test_zero_cases_is_incomplete_without_touching_db(monkeypatch: pytest.MonkeyPatch) -> None:
    from ai_service.evals import payment_intake_nightly as nightly
    from ai_service.evals.payment_intake_assets import load_pack

    pack = load_pack()
    pack["datasets"] = {"source": []}
    monkeypatch.setattr(nightly, "load_pack", lambda root: pack)
    config = routing()
    fingerprint = configuration_fingerprint(config, {})
    outcome = NightlyEvaluator(object(), object(), routing=config, adapters={}, prices={}, limits=EvalLimits()).run(
        claim(fingerprint)
    )
    assert outcome.status == "incomplete"
    assert outcome.error_code == "empty_dataset"
    assert outcome.report["network_calls"] == 0


@pytest.mark.parametrize("phase", ["claim", "evaluate", "finish"])
@pytest.mark.parametrize("error_name", ["OperationalError", "InterfaceError"])
def test_broken_database_exits_daemon_for_supervisor_restart(
    phase: str, error_name: str, monkeypatch: pytest.MonkeyPatch
) -> None:
    import psycopg

    from ai_service.evals import worker as worker_module
    from ai_service.evals.payment_intake_nightly import EvalOutcome
    from ai_service.evals.worker import EvalWorker

    database_error = getattr(psycopg, error_name)

    def fail() -> None:
        raise database_error("database connection lost")

    class Store:
        claims = 0

        def claim(self, *_args: Any, **_kwargs: Any) -> Any:
            self.claims += 1
            if self.claims > 1:
                worker.stop()
                return None
            if phase == "claim":
                fail()
            return claim()

        def finish(self, *_args: Any, **_kwargs: Any) -> bool:
            if phase == "finish":
                fail()
            return True

    class Evaluator:
        def run(self, _claim: Any) -> EvalOutcome:
            if phase == "evaluate":
                fail()
            return EvalOutcome("incomplete", {"complete": False})

    worker = EvalWorker(object(), Store(), Evaluator(), lease_seconds=900, poll_seconds=0)
    monkeypatch.setattr(worker_module.signal, "signal", lambda *_args: None)
    # Prevent a buggy swallowed exception from looping forever during the RED run.
    monkeypatch.setattr(worker._stop, "wait", lambda _timeout: worker.stop())
    with pytest.raises(database_error):
        worker.run_forever()


def test_malformed_price_yaml_uses_sanitized_configuration_error(tmp_path: Path) -> None:
    path = tmp_path / "prices.yaml"
    path.write_text("one/first: [unterminated secret content")
    with pytest.raises(ValueError, match=r"^invalid_eval_prices$"):
        load_prices(path)
