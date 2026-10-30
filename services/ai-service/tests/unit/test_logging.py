import json
import logging

import pytest
import structlog

from ai_service.tracing.logging import configure_logging


def test_own_and_foreign_logs_are_json_with_service(capsys: pytest.CaptureFixture[str]) -> None:
    configure_logging("INFO")
    structlog.get_logger("ai_service.test").info("own_event", task_id="t1")
    logging.getLogger("uvicorn.error").info("Application startup complete.")
    lines = [json.loads(line) for line in capsys.readouterr().out.splitlines()]
    assert [line["event"] for line in lines] == ["own_event", "Application startup complete."]
    assert all(line["service"] == "ai-service" for line in lines)
    assert lines[0]["task_id"] == "t1"
