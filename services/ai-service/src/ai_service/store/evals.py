"""Durable technical eval jobs and Decimal resource ledger, independent of company tasks."""

import hashlib
import json
from dataclasses import dataclass
from datetime import date, datetime
from decimal import ROUND_CEILING, Decimal
from typing import Any
from uuid import UUID
from zoneinfo import ZoneInfo

from psycopg import Connection
from psycopg.rows import dict_row
from psycopg.sql import SQL
from psycopg.types.json import Jsonb

_MOSCOW = ZoneInfo("Europe/Moscow")
_QUANTUM = Decimal("0.00000001")
_TERMINAL = frozenset({"passed", "failed", "unavailable", "incomplete", "skipped_budget"})
_STATUS_COLUMNS = """run_id, suite, run_date, status, attempt, trace_id, configuration_fingerprint,
                     created_at, updated_at, completed_at, error_code, report"""


@dataclass(frozen=True, slots=True)
class EvalClaim:
    run_id: UUID
    suite: str
    run_date: date
    trace_id: UUID
    attempt: int
    configuration_fingerprint: str


@dataclass(frozen=True, slots=True)
class EvalEnqueue:
    run_id: UUID
    status: str
    duplicate: bool
    conflict: bool


def _periods(now: datetime) -> tuple[date, date]:
    if now.utcoffset() is None:
        raise ValueError("timezone-aware now required")
    day = now.astimezone(_MOSCOW).date()
    return day.replace(day=1), day


def _cost(value: Decimal, *, positive: bool = False, round_up: bool = False) -> Decimal:
    if not isinstance(value, Decimal) or not value.is_finite() or value < 0 or (positive and value == 0):
        raise ValueError("nonnegative finite Decimal cost required")
    return value.quantize(_QUANTUM, rounding=ROUND_CEILING) if round_up else value


def _call_lock(conn: Connection[Any], call_id: UUID) -> None:
    # Identity lock exists even before INSERT; reserve/settle always use call -> run -> month -> day.
    key = int.from_bytes(hashlib.sha256(b"ai_eval_call:" + call_id.bytes).digest()[:8], signed=True)
    conn.execute("SELECT pg_advisory_xact_lock(%s)", (key,))


def _lock_budgets(conn: Connection[Any], month: date, day: date) -> list[tuple[str, date, Decimal, Decimal]]:
    rows = []
    for kind, start in (("month", month), ("day", day)):
        conn.execute(
            "INSERT INTO ai_eval_budget (period_kind, period_start) VALUES (%s, %s) ON CONFLICT DO NOTHING",
            (kind, start),
        )
        row = conn.execute(
            "SELECT period_kind, period_start, spent, reserved FROM ai_eval_budget "
            "WHERE period_kind = %s AND period_start = %s FOR UPDATE",
            (kind, start),
        ).fetchone()
        if row is None:  # pragma: no cover — INSERT above guarantees the row
            raise RuntimeError("missing eval budget row")
        rows.append(row)
    return rows


class EvalStore:
    def enqueue(
        self,
        conn: Connection[Any],
        *,
        run_id: UUID,
        suite: str,
        run_date: date,
        trace_id: UUID,
        idempotency_key: str,
        configuration_fingerprint: str,
        now: datetime,
    ) -> EvalEnqueue:
        _periods(now)
        digest = hashlib.sha256(
            json.dumps([suite, run_date.isoformat(), configuration_fingerprint, idempotency_key]).encode()
        ).hexdigest()
        with conn.transaction():
            row = conn.execute(
                """INSERT INTO ai_eval_run
                   (run_id, suite, run_date, trace_id, idempotency_key, configuration_fingerprint,
                    request_hash, created_at, updated_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                   ON CONFLICT DO NOTHING RETURNING run_id, status""",
                (run_id, suite, run_date, trace_id, idempotency_key, configuration_fingerprint, digest, now, now),
            ).fetchone()
            if row is not None:
                return EvalEnqueue(row[0], row[1], duplicate=False, conflict=False)
            rows = conn.execute(
                """SELECT run_id, status, request_hash FROM ai_eval_run
                   WHERE idempotency_key = %s OR run_id = %s OR (suite = %s AND run_date = %s)
                   ORDER BY (idempotency_key = %s) DESC, (run_id = %s) DESC""",
                (idempotency_key, run_id, suite, run_date, idempotency_key, run_id),
            ).fetchall()
            if not rows:  # pragma: no cover — rows are retained permanently
                raise RuntimeError("enqueue conflict without existing eval")
            return EvalEnqueue(
                rows[0][0],
                rows[0][1],
                duplicate=True,
                conflict=len(rows) != 1 or rows[0][2] != digest,
            )

    def status(self, conn: Connection[Any], run_id: UUID) -> dict[str, Any] | None:
        with conn.cursor(row_factory=dict_row) as cur:
            return cur.execute(
                SQL("SELECT {} FROM ai_eval_run WHERE run_id = %s").format(SQL(_STATUS_COLUMNS)), (run_id,)
            ).fetchone()

    def latest(self, conn: Connection[Any], suite: str) -> dict[str, Any] | None:
        with conn.cursor(row_factory=dict_row) as cur:
            return cur.execute(
                SQL("SELECT {} FROM ai_eval_run WHERE suite = %s ORDER BY run_date DESC LIMIT 1").format(
                    SQL(_STATUS_COLUMNS)
                ),
                (suite,),
            ).fetchone()

    def claim(self, conn: Connection[Any], *, now: datetime, lease_seconds: int) -> EvalClaim | None:
        _, today = _periods(now)
        if lease_seconds <= 0:
            raise ValueError("positive lease required")
        with conn.transaction():
            # Never reclaim paid work: unknown usage and the original attempt remain retained.
            conn.execute(
                """WITH expired AS (
                       SELECT run_id FROM ai_eval_run
                       WHERE (status = 'running' AND lease_until <= %s)
                          OR (status = 'queued' AND run_date < %s)
                       FOR UPDATE SKIP LOCKED
                   ) UPDATE ai_eval_run r SET status = 'incomplete', lease_until = NULL,
                       completed_at = %s, updated_at = %s, error_code = 'eval_expired'
                   FROM expired WHERE r.run_id = expired.run_id""",
                (now, today, now, now),
            )
            row = conn.execute(
                """UPDATE ai_eval_run SET status = 'running', attempt = attempt + 1,
                       lease_until = %s + make_interval(secs => %s), updated_at = %s
                   WHERE run_id = (
                       SELECT run_id FROM ai_eval_run WHERE status = 'queued' AND run_date = %s
                       ORDER BY created_at, run_id FOR UPDATE SKIP LOCKED LIMIT 1
                   ) RETURNING run_id, suite, run_date, trace_id, attempt, configuration_fingerprint""",
                (now, lease_seconds, now, today),
            ).fetchone()
        return None if row is None else EvalClaim(*row)

    def heartbeat(self, conn: Connection[Any], claim: EvalClaim, *, now: datetime, lease_seconds: int) -> bool:
        _periods(now)
        if lease_seconds <= 0:
            raise ValueError("positive lease required")
        with conn.transaction():
            return (
                conn.execute(
                    """UPDATE ai_eval_run SET lease_until = %s + make_interval(secs => %s), updated_at = %s
                   WHERE run_id = %s AND status = 'running' AND attempt = %s AND lease_until > %s""",
                    (now, lease_seconds, now, claim.run_id, claim.attempt, now),
                ).rowcount
                == 1
            )

    def finish(
        self,
        conn: Connection[Any],
        claim: EvalClaim,
        *,
        status: str,
        report: dict[str, Any],
        error_code: str | None,
        now: datetime,
    ) -> bool:
        _periods(now)
        if status not in _TERMINAL:
            raise ValueError("terminal eval status required")
        with conn.transaction():
            return (
                conn.execute(
                    """UPDATE ai_eval_run SET status = %s, report = %s, error_code = %s,
                       completed_at = %s, updated_at = %s, lease_until = NULL
                   WHERE run_id = %s AND status = 'running' AND attempt = %s AND lease_until > %s""",
                    (status, Jsonb(report), error_code, now, now, claim.run_id, claim.attempt, now),
                ).rowcount
                == 1
            )

    def reserve(
        self,
        conn: Connection[Any],
        claim: EvalClaim,
        *,
        call_id: UUID,
        model: str,
        amount: Decimal,
        day_limit: Decimal,
        month_limit: Decimal,
        max_calls: int,
        max_model_calls: int,
        now: datetime,
    ) -> bool:
        month, day = _periods(now)
        requested_amount = _cost(amount, positive=True)
        amount = _cost(amount, positive=True, round_up=True)
        limits = (min(_cost(month_limit), Decimal("900")), min(_cost(day_limit), Decimal("30")))
        with conn.transaction():
            _call_lock(conn, call_id)
            existing = conn.execute(
                "SELECT run_id, attempt, model, requested_amount FROM ai_eval_call WHERE call_id = %s",
                (call_id,),
            ).fetchone()
            if existing is not None:
                if existing != (claim.run_id, claim.attempt, model, requested_amount):
                    raise ValueError("eval reservation conflict")
                return False  # A committed reservation never authorizes a second HTTP call.
            run = conn.execute(
                "SELECT status, attempt, lease_until FROM ai_eval_run WHERE run_id = %s FOR UPDATE",
                (claim.run_id,),
            ).fetchone()
            if run is None or run[0] != "running" or run[1] != claim.attempt or run[2] <= now:
                return False
            counts = conn.execute(
                "SELECT count(*), count(*) FILTER (WHERE model = %s) FROM ai_eval_call WHERE run_id = %s",
                (model, claim.run_id),
            ).fetchone()
            if counts is None:  # pragma: no cover — aggregate always returns one row
                raise RuntimeError("missing eval call count")
            if counts[0] >= min(max_calls, 164) or counts[1] >= min(max_model_calls, 82):
                return False
            rows = _lock_budgets(conn, month, day)
            if any(row[2] + row[3] + amount > limit for row, limit in zip(rows, limits, strict=True)):
                return False
            conn.execute(
                """INSERT INTO ai_eval_call
                   (call_id, run_id, attempt, model, requested_amount, amount, month_start, day_start, created_at)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                (call_id, claim.run_id, claim.attempt, model, requested_amount, amount, month, day, now),
            )
            for kind, start, _, _ in rows:
                conn.execute(
                    "UPDATE ai_eval_budget SET reserved = reserved + %s WHERE period_kind = %s AND period_start = %s",
                    (amount, kind, start),
                )
        return True

    def settle(
        self,
        conn: Connection[Any],
        call_id: UUID,
        *,
        actual_cost: Decimal,
        input_tokens: int,
        output_tokens: int,
        now: datetime,
    ) -> bool:
        _periods(now)
        actual_cost = _cost(actual_cost)
        if input_tokens < 0 or output_tokens < 0:
            raise ValueError("nonnegative tokens required")
        with conn.transaction():
            _call_lock(conn, call_id)
            row = conn.execute(
                """SELECT run_id, amount, month_start, day_start, actual_cost, input_tokens, output_tokens
                   FROM ai_eval_call WHERE call_id = %s""",
                (call_id,),
            ).fetchone()
            if row is None:
                return False
            if row[4] is not None:
                if row[4:] != (actual_cost, input_tokens, output_tokens):
                    raise ValueError("eval settlement conflict")
                return True
            conn.execute("SELECT run_id FROM ai_eval_run WHERE run_id = %s FOR UPDATE", (row[0],))
            budgets = _lock_budgets(conn, row[2], row[3])
            for kind, start, _, _ in budgets:
                conn.execute(
                    """UPDATE ai_eval_budget SET reserved = reserved - %s, spent = spent + %s
                       WHERE period_kind = %s AND period_start = %s""",
                    (row[1], actual_cost, kind, start),
                )
            conn.execute(
                """UPDATE ai_eval_call SET actual_cost = %s, input_tokens = %s, output_tokens = %s,
                       settled_at = %s WHERE call_id = %s""",
                (actual_cost, input_tokens, output_tokens, now, call_id),
            )
        return True

    def budget(self, conn: Connection[Any], *, now: datetime) -> dict[str, str]:
        month, day = _periods(now)
        rows = conn.execute(
            """SELECT period_kind, spent, reserved FROM ai_eval_budget
               WHERE (period_kind = 'month' AND period_start = %s) OR (period_kind = 'day' AND period_start = %s)""",
            (month, day),
        ).fetchall()
        result = {f"{kind}_{field}": "0" for kind in ("day", "month") for field in ("spent", "reserved")}
        for kind, spent, reserved in rows:
            result[f"{kind}_spent"] = format(spent, "f")
            result[f"{kind}_reserved"] = format(reserved, "f")
        return result
