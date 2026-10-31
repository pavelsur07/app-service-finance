import pytest
import sentry_sdk

from ai_service.tracing.errors import configure_error_reporting


def test_without_dsn_nothing_is_reported(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("SENTRY_DSN", raising=False)

    assert configure_error_reporting() is False


def test_with_dsn_payloads_and_locals_never_leave(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("SENTRY_DSN", "https://public@glitchtip.invalid/1")
    monkeypatch.setenv("SENTRY_RELEASE", "abc123")
    monkeypatch.setenv("SENTRY_ENVIRONMENT", "prod")
    try:
        assert configure_error_reporting() is True
        options = sentry_sdk.get_client().options
        assert options["release"] == "abc123"
        assert options["environment"] == "prod"
        assert options["send_default_pii"] is False
        assert options["include_local_variables"] is False
        assert options["max_request_body_size"] == "never"
    finally:
        sentry_sdk.init(dsn="")
