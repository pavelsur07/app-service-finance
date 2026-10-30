import os
from collections.abc import Iterator
from pathlib import Path
from typing import Any

import psycopg
import pytest
from fastapi.testclient import TestClient
from psycopg_pool import ConnectionPool

from ai_service.api.app import create_app
from ai_service.store.migrate import migrate

MIGRATIONS = Path(__file__).resolve().parents[2] / "migrations"
TOKEN = "test-token-0123456789"


@pytest.fixture(scope="session")
def dsn() -> str:
    value = os.environ.get("AI_TEST_DB_DSN")
    if not value:
        pytest.skip("AI_TEST_DB_DSN не задан — интеграционные тесты пропущены")
    return value


@pytest.fixture
def conn(dsn: str) -> Iterator[psycopg.Connection[Any]]:
    with psycopg.connect(dsn, autocommit=True) as c:
        c.execute("DROP TABLE IF EXISTS ai_trace, ai_result, ai_task, schema_migrations CASCADE")
        migrate(dsn, MIGRATIONS)
        yield c


@pytest.fixture
def client(dsn: str, conn: psycopg.Connection[Any]) -> Iterator[TestClient]:
    pool: ConnectionPool[Any] = ConnectionPool(dsn, min_size=1, max_size=2, open=False, kwargs={"autocommit": True})
    with TestClient(create_app(pool, TOKEN, ["echo"])) as c:
        c.headers["Authorization"] = f"Bearer {TOKEN}"
        yield c
