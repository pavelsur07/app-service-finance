import shutil
import uuid
from pathlib import Path

import psycopg

from ai_service.store.migrate import migrate

from .conftest import MIGRATIONS


def test_0003_removes_payment_intake_rows_and_eval_tables(dsn: str, tmp_path: Path) -> None:
    for name in ("0001_init.sql", "0002_eval_runs.sql"):
        shutil.copy(MIGRATIONS / name, tmp_path / name)
    with psycopg.connect(dsn, autocommit=True) as c:
        c.execute(
            "DROP TABLE IF EXISTS ai_eval_call, ai_eval_budget, ai_eval_run, "
            "ai_trace, ai_result, ai_task, schema_migrations CASCADE"
        )
        migrate(dsn, tmp_path)
        ids = {}
        for task_type in ("parse_payment_message", "categorize_transactions"):
            ids[task_type] = task_id = uuid.uuid4()
            c.execute(
                "INSERT INTO ai_task (id, task_type, contract_version, company_id, idempotency_key, request_hash, "
                "requested_by, trace_id, requested_at) VALUES (%s, %s, 'v1', %s, %s, 'h', 'test', %s, now())",
                (task_id, task_type, uuid.uuid4(), str(task_id), uuid.uuid4()),
            )
            c.execute("INSERT INTO ai_result (task_id, status) VALUES (%s, 'accepted')", (task_id,))
            c.execute(
                "INSERT INTO ai_trace (task_id, company_id, trace_id, task_type, data) VALUES (%s, %s, %s, %s, '{}')",
                (task_id, uuid.uuid4(), uuid.uuid4(), task_type),
            )

        assert migrate(dsn, MIGRATIONS) == ["0003_drop_payment_intake"]

        kept = [ids["categorize_transactions"]]
        assert [r[0] for r in c.execute("SELECT id FROM ai_task").fetchall()] == kept
        assert [r[0] for r in c.execute("SELECT task_id FROM ai_result").fetchall()] == kept
        assert [r[0] for r in c.execute("SELECT task_id FROM ai_trace").fetchall()] == kept
        assert c.execute("SELECT to_regclass('ai_eval_run')").fetchone() == (None,)
