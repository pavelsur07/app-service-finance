"""SQL очереди задач, результатов и трасс (БД ai_service). Только техническое состояние сервиса."""

import hashlib
import json
from dataclasses import dataclass
from datetime import datetime
from typing import Any
from uuid import UUID

from psycopg import Connection
from psycopg.rows import dict_row
from psycopg.types.json import Jsonb

from ai_service.core.pipeline import PipelineOutcome


@dataclass(frozen=True, slots=True)
class NewTask:
    task_id: UUID
    task_type: str
    contract_version: str
    company_id: UUID
    idempotency_key: str
    requested_by: str
    requested_at: datetime
    trace_id: UUID
    payload: dict[str, Any]

    def request_hash(self) -> str:
        """Отпечаток содержимого: тот же ключ с другим содержимым — конфликт, а не повтор.

        task_id в отпечаток не входит: повтор с тем же ключом и новым task_id возвращает прежнюю задачу.
        """
        body = {
            "task_type": self.task_type,
            "company_id": str(self.company_id),
            "payload": self.payload,
        }
        return hashlib.sha256(json.dumps(body, sort_keys=True, ensure_ascii=False).encode()).hexdigest()


@dataclass(frozen=True, slots=True)
class EnqueueResult:
    task_id: UUID
    status: str
    duplicate: bool
    conflict: bool


@dataclass(frozen=True, slots=True)
class ClaimedTask:
    task_id: UUID
    task_type: str
    company_id: UUID
    trace_id: UUID
    payload: dict[str, Any] | None
    attempts: int


class TaskStore:
    def enqueue(self, conn: Connection[Any], task: NewTask) -> EnqueueResult:
        request_hash = task.request_hash()
        with conn.transaction():
            row = conn.execute(
                """
                INSERT INTO ai_task (id, task_type, contract_version, company_id, idempotency_key, request_hash,
                                     requested_by, trace_id, payload, requested_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (idempotency_key) DO NOTHING
                RETURNING id, status
                """,
                (
                    task.task_id,
                    task.task_type,
                    task.contract_version,
                    task.company_id,
                    task.idempotency_key,
                    request_hash,
                    task.requested_by,
                    task.trace_id,
                    Jsonb(task.payload),
                    task.requested_at,
                ),
            ).fetchone()
            if row is not None:
                return EnqueueResult(row[0], row[1], duplicate=False, conflict=False)
            existing = conn.execute(
                "SELECT id, status, request_hash FROM ai_task WHERE idempotency_key = %s",
                (task.idempotency_key,),
            ).fetchone()
        if existing is None:  # pragma: no cover — конфликт без строки невозможен вне гонки с удалением
            raise RuntimeError("enqueue conflict without existing row")
        return EnqueueResult(existing[0], existing[1], duplicate=True, conflict=existing[2] != request_hash)

    def status(self, conn: Connection[Any], task_id: UUID) -> dict[str, Any] | None:
        with conn.cursor(row_factory=dict_row) as cur:
            return cur.execute(
                """
                SELECT t.id AS task_id, t.task_type, t.status, t.attempts, r.status AS result_status,
                       t.created_at, t.updated_at
                FROM ai_task t LEFT JOIN ai_result r ON r.task_id = t.id
                WHERE t.id = %s
                """,
                (task_id,),
            ).fetchone()

    def claim(self, conn: Connection[Any], lease_seconds: int) -> ClaimedTask | None:
        """Берёт одну задачу: свободную или с истёкшей арендой (упавший worker)."""
        with conn.transaction():
            row = conn.execute(
                """
                UPDATE ai_task SET status = 'running', attempts = attempts + 1,
                       lease_until = now() + make_interval(secs => %s), updated_at = now()
                WHERE id = (
                    SELECT id FROM ai_task
                    WHERE (status = 'queued' AND available_at <= now())
                       OR (status = 'running' AND lease_until < now())
                    ORDER BY created_at
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                )
                RETURNING id, task_type, company_id, trace_id, payload, attempts
                """,
                (lease_seconds,),
            ).fetchone()
        if row is None:
            return None
        return ClaimedTask(row[0], row[1], row[2], row[3], row[4], row[5])

    def finalize(self, conn: Connection[Any], task: ClaimedTask, outcome: PipelineOutcome) -> bool:
        """Результат, трасса и статус — одной транзакцией. False — задачу уже забрал другой worker."""
        with conn.transaction():
            updated = conn.execute(
                """
                UPDATE ai_task SET status = 'done', payload = NULL, lease_until = NULL, updated_at = now()
                WHERE id = %s AND status = 'running' AND attempts = %s
                """,
                (task.task_id, task.attempts),
            ).rowcount
            if updated != 1:
                return False
            conn.execute(
                """
                INSERT INTO ai_result (task_id, status, output, checks, confidence, models, prompt_version,
                                       input_tokens, output_tokens, error_code)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (task_id) DO NOTHING
                """,
                (
                    task.task_id,
                    outcome.verdict.value,
                    None if outcome.output is None else Jsonb(outcome.output),
                    Jsonb([c.as_dict() for c in outcome.checks]),
                    outcome.confidence,
                    Jsonb(outcome.models),
                    outcome.prompt_version,
                    outcome.input_tokens,
                    outcome.output_tokens,
                    outcome.error_code,
                ),
            )
            conn.execute(
                "INSERT INTO ai_trace (task_id, company_id, trace_id, task_type, data) VALUES (%s, %s, %s, %s, %s)",
                (task.task_id, task.company_id, task.trace_id, task.task_type, Jsonb(outcome.trace)),
            )
        return True

    def release_for_retry(self, conn: Connection[Any], task: ClaimedTask, error: str, backoff_seconds: int) -> None:
        with conn.transaction():
            conn.execute(
                """
                UPDATE ai_task SET status = 'queued', lease_until = NULL, last_error = %s,
                       available_at = now() + make_interval(secs => %s), updated_at = now()
                WHERE id = %s AND status = 'running' AND attempts = %s
                """,
                (error, backoff_seconds, task.task_id, task.attempts),
            )

    def results_page(self, conn: Connection[Any], after: int, limit: int) -> list[dict[str, Any]]:
        with conn.cursor(row_factory=dict_row) as cur:
            return cur.execute(
                """
                SELECT r.seq, t.id AS task_id, t.task_type, t.company_id, r.status, r.output, r.checks,
                       r.confidence, t.trace_id, r.models, r.prompt_version, r.input_tokens, r.output_tokens,
                       r.error_code, r.completed_at
                FROM ai_result r JOIN ai_task t ON t.id = r.task_id
                WHERE r.acked_at IS NULL AND r.seq > %s
                ORDER BY r.seq
                LIMIT %s
                """,
                (after, limit),
            ).fetchall()

    def ack(self, conn: Connection[Any], task_ids: list[UUID]) -> int:
        """Подтверждение приёма: output обнуляется (retention), повторный ack ничего не меняет."""
        with conn.transaction():
            acked = conn.execute(
                "UPDATE ai_result SET acked_at = now(), output = NULL WHERE task_id = ANY(%s) AND acked_at IS NULL",
                (task_ids,),
            ).rowcount
            conn.execute(
                "UPDATE ai_task SET status = 'acked', updated_at = now() WHERE id = ANY(%s) AND status = 'done'",
                (task_ids,),
            )
        return acked

    def queue_stats(self, conn: Connection[Any]) -> dict[str, Any]:
        with conn.cursor(row_factory=dict_row) as cur:
            row = cur.execute(
                """
                SELECT count(*) FILTER (WHERE status = 'queued') AS queued,
                       count(*) FILTER (WHERE status = 'running') AS running,
                       count(*) FILTER (WHERE status = 'done') AS done_unacked,
                       (SELECT count(*) FROM ai_result
                        WHERE status = 'failed' AND completed_at > now() - interval '24 hours') AS failed_24h,
                       (SELECT max(completed_at) FROM ai_result WHERE status <> 'failed') AS last_success_at
                FROM ai_task
                """
            ).fetchone()
        return dict(row) if row else {}

    def delete_expired_traces(self, conn: Connection[Any], ttl_days: int) -> int:
        with conn.transaction():
            return conn.execute(
                "DELETE FROM ai_trace WHERE created_at < now() - make_interval(days => %s)", (ttl_days,)
            ).rowcount
