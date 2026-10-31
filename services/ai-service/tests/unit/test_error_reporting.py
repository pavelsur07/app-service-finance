import logging
from typing import Any

import pytest
import sentry_sdk
from fastapi import FastAPI
from fastapi.testclient import TestClient
from sentry_sdk.envelope import Envelope
from sentry_sdk.transport import Transport

from ai_service.tracing.errors import configure_error_reporting


class _Captured(Transport):
    def __init__(self) -> None:
        super().__init__()
        self.events: list[dict[str, Any]] = []

    def capture_envelope(self, envelope: Envelope) -> None:
        event = envelope.get_event()
        if event is not None:
            self.events.append(event)


@pytest.fixture
def captured(monkeypatch: pytest.MonkeyPatch) -> Any:
    monkeypatch.setenv("SENTRY_DSN", "https://public@glitchtip.invalid/1")
    monkeypatch.setenv("SENTRY_RELEASE", "abc123")
    monkeypatch.setenv("SENTRY_ENVIRONMENT", "prod")
    transport = _Captured()
    assert configure_error_reporting(transport) is True
    yield transport
    sentry_sdk.init(dsn="")


def test_without_dsn_nothing_is_reported(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("SENTRY_DSN", raising=False)

    assert configure_error_reporting() is False


def test_only_error_logs_are_reported_without_payloads_or_locals(captured: _Captured) -> None:
    options = sentry_sdk.get_client().options
    assert options["release"] == "abc123"
    assert options["environment"] == "prod"
    assert options["send_default_pii"] is False
    assert options["include_local_variables"] is False
    assert options["max_request_body_size"] == "never"

    logging.getLogger("ai_service.test").warning("task_retry")
    logging.getLogger("ai_service.test").error("task_failed")
    sentry_sdk.flush()

    assert [e.get("logentry", {}).get("message") for e in captured.events] == ["task_failed"]


def test_unhandled_endpoint_exception_text_is_not_reported(captured: _Captured) -> None:
    app = FastAPI()

    @app.get("/boom")
    def boom() -> None:
        raise RuntimeError("ООО Ромашка ИНН 7701234567")

    TestClient(app, raise_server_exceptions=False).get("/boom")
    sentry_sdk.flush()

    assert all("Ромашка" not in str(event) for event in captured.events)
