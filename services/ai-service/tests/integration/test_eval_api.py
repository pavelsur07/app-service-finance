"""Authenticated technical quality API cannot enqueue company tasks or override budgets."""

from collections.abc import Iterator
from datetime import UTC, datetime
from typing import Any
from uuid import uuid4

import psycopg
import pytest
from fastapi.testclient import TestClient
from psycopg_pool import ConnectionPool

from ai_service.api.app import create_app
from ai_service.store.evals import EvalStore
from tests.integration.conftest import TOKEN
from tests.unit.test_eval_contract import validate

pytestmark = pytest.mark.integration
NOW = datetime(2026, 11, 14, 2, 0, tzinfo=UTC)


def body(**changes: Any) -> dict[str, Any]:
    return {
        "contract_version": "v1",
        "run_id": str(uuid4()),
        "suite": "payment_intake",
        "run_date": "2026-11-14",
        "trace_id": str(uuid4()),
        "idempotency_key": "payment_intake:2026-11-14",
    } | changes


@pytest.fixture
def eval_client(dsn: str, conn: psycopg.Connection[Any]) -> Iterator[tuple[TestClient, list[str]]]:
    fingerprint = ["a" * 64]
    pool: ConnectionPool[Any] = ConnectionPool(dsn, min_size=1, max_size=2, open=False, kwargs={"autocommit": True})
    app = create_app(
        pool, TOKEN, ["echo"], eval_enabled=True, eval_fingerprint=lambda: fingerprint[0], eval_clock=lambda: NOW
    )
    with TestClient(app) as client:
        client.headers["Authorization"] = f"Bearer {TOKEN}"
        yield client, fingerprint


def post(client: TestClient, value: dict[str, Any]) -> Any:
    return client.post("/internal/eval-runs", json=value, headers={"Idempotency-Key": value["idempotency_key"]})


def test_enqueue_replay_status_and_configuration_conflict_are_technical_only(
    eval_client: tuple[TestClient, list[str]], conn: psycopg.Connection[Any]
) -> None:
    client, fingerprint = eval_client
    value = body()
    first = post(client, value)
    assert first.status_code == 202
    validate("EvalAccepted", first.json())
    assert first.json()["run_id"] == value["run_id"]
    assert first.json()["status"] == "queued"
    assert first.json()["duplicate"] is False
    replay = post(client, body())
    assert replay.status_code == 202
    assert replay.json()["run_id"] == value["run_id"]
    assert replay.json()["duplicate"] is True
    status = client.get("/internal/eval-runs/" + value["run_id"])
    assert status.status_code == 200
    validate("EvalStatus", status.json())
    assert status.json()["configuration_fingerprint"] == "a" * 64
    assert status.json()["report"] is None
    latest = client.get("/internal/eval-runs/latest").json()
    validate("EvalLatest", latest)
    assert latest["enabled"] is True
    assert latest["run"]["run_id"] == value["run_id"]
    assert latest["configuration_fingerprint"] == "a" * 64
    fingerprint[0] = "b" * 64
    changed = post(client, body())
    assert changed.status_code == 409
    assert changed.json()["error"]["code"] == "eval_idempotency_conflict"
    assert client.get("/internal/eval-runs/latest").json()["configuration_fingerprint"] == "b" * 64
    assert conn.execute("SELECT count(*) FROM ai_eval_run").fetchone() == (1,)
    assert conn.execute("SELECT count(*) FROM ai_task").fetchone() == (0,)
    assert conn.execute("SELECT count(*) FROM ai_result").fetchone() == (0,)


def test_eval_authentication_and_idempotency_header_are_required(eval_client: tuple[TestClient, list[str]]) -> None:
    client, _ = eval_client
    response = client.post("/internal/eval-runs", json=body(), headers={"Authorization": "Bearer wrong"})
    assert response.status_code == 401
    response = client.get("/internal/eval-runs/latest", headers={"Authorization": "Bearer wrong"})
    assert response.status_code == 401
    response = client.post("/internal/eval-runs", json=body(), headers={"Idempotency-Key": "other"})
    assert response.status_code == 422
    assert response.json()["error"]["code"] == "idempotency_key_mismatch"


@pytest.mark.parametrize(
    "change",
    [
        {"run_date": "2026-11-13"},
        {"run_date": "2026-11-15"},
        {"company_id": str(uuid4())},
        {"budget_rub": "3000"},
        {"payload": {"text": "untrusted"}},
    ],
)
def test_eval_cannot_backdate_or_accept_business_overrides(
    eval_client: tuple[TestClient, list[str]], conn: psycopg.Connection[Any], change: dict[str, Any]
) -> None:
    client, _ = eval_client
    assert post(client, body(**change)).status_code == 422
    assert conn.execute("SELECT count(*) FROM ai_eval_run").fetchone() == (0,)


def test_disabled_eval_never_loads_configuration_or_creates_a_run(dsn: str, conn: psycopg.Connection[Any]) -> None:
    def forbidden() -> str:
        raise AssertionError("disabled eval must not load runtime assets")

    pool: ConnectionPool[Any] = ConnectionPool(dsn, min_size=1, max_size=2, open=False, kwargs={"autocommit": True})
    with TestClient(
        create_app(pool, TOKEN, ["echo"], eval_enabled=False, eval_fingerprint=forbidden, eval_clock=lambda: NOW)
    ) as client:
        client.headers["Authorization"] = f"Bearer {TOKEN}"
        assert post(client, body()).status_code == 503
        assert client.get("/internal/eval-runs/latest").json() == {
            "enabled": False,
            "configuration_fingerprint": None,
            "run": None,
        }
    assert conn.execute("SELECT count(*) FROM ai_eval_run").fetchone() == (0,)


def test_configuration_failure_is_sanitized_and_does_not_enqueue(dsn: str, conn: psycopg.Connection[Any]) -> None:
    def broken() -> str:
        raise ValueError("private-fixture-value-not-for-response")

    pool: ConnectionPool[Any] = ConnectionPool(dsn, min_size=1, max_size=2, open=False, kwargs={"autocommit": True})
    with TestClient(
        create_app(pool, TOKEN, ["echo"], eval_enabled=True, eval_fingerprint=broken, eval_clock=lambda: NOW)
    ) as client:
        client.headers["Authorization"] = f"Bearer {TOKEN}"
        response = post(client, body())
        assert response.status_code == 503
        assert "private-fixture-value" not in response.text
        assert response.json()["error"]["code"] == "eval_configuration_unavailable"
    assert conn.execute("SELECT count(*) FROM ai_eval_run").fetchone() == (0,)


def test_terminal_report_and_unknown_run_are_returned_without_business_content(
    eval_client: tuple[TestClient, list[str]], conn: psycopg.Connection[Any]
) -> None:
    client, _ = eval_client
    assert client.get("/internal/eval-runs/" + str(uuid4())).status_code == 404
    value = body()
    assert post(client, value).status_code == 202
    store = EvalStore()
    claim = store.claim(conn, now=NOW, lease_seconds=900)
    assert claim is not None
    report = {"complete": False, "checked_cases": 1, "expected_cases": 53}
    assert store.finish(conn, claim, status="failed", report=report, error_code="quality_regression", now=NOW)
    response = client.get("/internal/eval-runs/" + value["run_id"])
    assert response.status_code == 200
    validate("EvalStatus", response.json())
    assert response.json()["status"] == "failed"
    assert response.json()["report"] == report
    assert "company_id" not in response.json()
    assert "payload" not in response.json()


def test_latest_rejects_unknown_suite_instead_of_returning_other_quality_data(
    eval_client: tuple[TestClient, list[str]],
) -> None:
    client, _ = eval_client
    assert client.get("/internal/eval-runs/latest", params={"suite": "other"}).status_code == 422
    assert client.get("/internal/eval-runs/latest", params={"suite": "payment_intake"}).status_code == 200
