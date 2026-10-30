"""Путь задачи через API и worker на настоящем PostgreSQL; ответы проверяются по схеме контракта."""

import uuid
from typing import Any

import psycopg
import pytest
from fastapi.testclient import TestClient

from ai_service.core.pipeline import Pipeline
from ai_service.llm.adapters.stub import StubAdapter
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import parse_routing
from ai_service.store.migrate import migrate
from ai_service.store.tasks import TaskStore
from ai_service.tasks.registry import build_registry
from ai_service.worker.worker import Worker
from tests.contract import validate
from tests.integration.conftest import MIGRATIONS

pytestmark = pytest.mark.integration

COMPANY = "0192f0a0-0000-7000-8000-0000000000c1"


def task_body(text: str = "звонить +79123456789", key: str | None = None) -> dict[str, Any]:
    task_id = str(uuid.uuid4())
    return {
        "task_id": task_id,
        "task_type": "echo",
        "contract_version": "v1",
        "company_id": COMPANY,
        "idempotency_key": key or task_id,
        "requested_by": "system",
        "created_at": "2026-10-30T12:00:00+03:00",
        "trace_id": str(uuid.uuid4()),
        "payload": {"text": text},
    }


def post(client: TestClient, body: dict[str, Any]) -> Any:
    return client.post("/internal/tasks", json=body, headers={"Idempotency-Key": body["idempotency_key"]})


def worker(conn: psycopg.Connection[Any], model: str = "stub/echo", max_attempts: int = 3) -> Worker:
    routing = parse_routing(
        {"providers": {"stub": {"type": "stub"}}, "tasks": {"echo": {"mode": "single", "models": [model]}}}, {}
    )
    return Worker(
        conn,
        TaskStore(),
        Pipeline(build_registry(), LlmGateway(routing, {"stub": StubAdapter()}, retries_per_model=0)),
        lease_seconds=60,
        max_attempts=max_attempts,
        retry_backoff_seconds=0,
        poll_seconds=0.01,
        trace_ttl_days=30,
        trace_cleanup_interval_seconds=3600,
    )


def test_internal_endpoints_require_token(client: TestClient) -> None:
    assert client.get("/health").status_code == 200
    response = client.get("/internal/status", headers={"Authorization": "Bearer wrong"})
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "unauthorized"


def test_full_echo_flow_with_masking_ack_and_retention(client: TestClient, conn: psycopg.Connection[Any]) -> None:
    body = task_body()
    accepted = post(client, body)
    assert accepted.status_code == 202
    validate("task_accepted", accepted.json())
    assert accepted.json() == {"task_id": body["task_id"], "status": "queued", "duplicate": False}

    assert worker(conn).run_once() is True
    assert worker(conn).run_once() is False

    status = client.get(f"/internal/tasks/{body['task_id']}").json()
    validate("task_status", status)
    assert (status["status"], status["result_status"], status["attempts"]) == ("done", "accepted", 1)

    page = client.get("/internal/tasks", params={"status": "done"}).json()
    validate("results_page", page)
    [item] = page["items"]
    assert item["output"] == {"text": "звонить [PHONE_1]"}
    assert (item["status"], item["models"], item["prompt_version"]) == ("accepted", ["stub/echo"], "echo/v1")

    # Исходный payload с ПДн удалён после финализации; в трассе — только маскированный вход.
    assert conn.execute("SELECT payload FROM ai_task").fetchone() == (None,)
    trace = conn.execute("SELECT data::text FROM ai_trace").fetchone()
    assert trace is not None
    assert "+79123456789" not in trace[0]

    ack = client.post("/internal/tasks/ack", json={"task_ids": [body["task_id"]]})
    validate("ack_response", ack.json())
    assert ack.json() == {"acked": 1}
    assert client.post("/internal/tasks/ack", json={"task_ids": [body["task_id"]]}).json() == {"acked": 0}
    assert client.get("/internal/tasks", params={"status": "done"}).json()["items"] == []
    assert conn.execute("SELECT output FROM ai_result").fetchone() == (None,)
    assert client.get(f"/internal/tasks/{body['task_id']}").json()["status"] == "acked"


def test_repeat_with_same_key_returns_same_task(client: TestClient, conn: psycopg.Connection[Any]) -> None:
    body = task_body()
    post(client, body)
    repeat = post(client, body)
    assert repeat.status_code == 202
    assert repeat.json() == {"task_id": body["task_id"], "status": "queued", "duplicate": True}
    assert conn.execute("SELECT count(*) FROM ai_task").fetchone() == (1,)


def test_same_key_with_new_task_id_returns_original_task(client: TestClient) -> None:
    body = task_body()
    post(client, body)
    repeat = post(client, {**body, "task_id": str(uuid.uuid4())})
    assert repeat.json() == {"task_id": body["task_id"], "status": "queued", "duplicate": True}


def test_same_key_with_other_content_is_conflict(client: TestClient) -> None:
    body = task_body()
    post(client, body)
    other = {**body, "payload": {"text": "другое"}}
    response = post(client, other)
    assert response.status_code == 409
    assert response.json()["error"]["code"] == "idempotency_conflict"


@pytest.mark.parametrize(
    ("change", "code"),
    [({"task_type": "nope"}, "unknown_task_type"), ({"idempotency_key": "other"}, "idempotency_key_mismatch")],
)
def test_rejected_requests(client: TestClient, change: dict[str, str], code: str) -> None:
    body = task_body()
    response = client.post(
        "/internal/tasks", json={**body, **change}, headers={"Idempotency-Key": body["idempotency_key"]}
    )
    assert response.status_code == 422
    assert response.json()["error"]["code"] == code


def test_unavailable_model_is_retried_then_failed(client: TestClient, conn: psycopg.Connection[Any]) -> None:
    body = task_body()
    post(client, body)
    w = worker(conn, model="stub/fail", max_attempts=2)
    assert w.run_once() is True  # попытка 1 → назад в очередь
    assert client.get(f"/internal/tasks/{body['task_id']}").json()["status"] == "queued"
    assert w.run_once() is True  # попытка 2 → failed
    [item] = client.get("/internal/tasks", params={"status": "done"}).json()["items"]
    assert (item["status"], item["error_code"], item["output"]) == ("failed", "llm_unavailable", None)


def test_expired_lease_is_reclaimed(client: TestClient, conn: psycopg.Connection[Any]) -> None:
    body = task_body()
    post(client, body)
    claimed = TaskStore().claim(conn, lease_seconds=60)
    assert claimed is not None
    assert TaskStore().claim(conn, lease_seconds=60) is None  # аренда действует
    conn.execute("UPDATE ai_task SET lease_until = now() - interval '1 second'")
    assert worker(conn).run_once() is True
    # Первый worker «очнулся» с устаревшей арендой: его финализация не проходит.
    assert client.get(f"/internal/tasks/{body['task_id']}").json()["attempts"] == 2


def test_results_are_paged_by_cursor(client: TestClient, conn: psycopg.Connection[Any]) -> None:
    for _ in range(3):
        post(client, task_body())
    w = worker(conn)
    while w.run_once():
        pass
    first = client.get("/internal/tasks", params={"status": "done", "limit": 2}).json()
    assert len(first["items"]) == 2
    assert first["next_cursor"] is not None
    second = client.get("/internal/tasks", params={"status": "done", "after": first["next_cursor"]}).json()
    assert len(second["items"]) == 1
    assert second["next_cursor"] is None
    assert client.get("/internal/tasks", params={"status": "done", "limit": 201}).status_code == 422


def test_expired_traces_are_deleted(conn: psycopg.Connection[Any]) -> None:
    conn.execute(
        "INSERT INTO ai_trace (task_id, company_id, trace_id, task_type, data, created_at) VALUES"
        " (gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), 'echo', '{}', now() - interval '31 days'),"
        " (gen_random_uuid(), gen_random_uuid(), gen_random_uuid(), 'echo', '{}', now())"
    )
    assert TaskStore().delete_expired_traces(conn, 30) == 1


def test_migrations_are_idempotent(dsn: str, conn: psycopg.Connection[Any]) -> None:
    assert migrate(dsn, MIGRATIONS) == []
