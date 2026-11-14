"""Durable nightly ledger: real PostgreSQL, no provider/network calls."""

from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal
from threading import Barrier
from typing import Any
from uuid import UUID, uuid4

import psycopg
import pytest

from ai_service.store.evals import EvalClaim, EvalStore

pytestmark = pytest.mark.integration
NOW = datetime(2026, 11, 14, 0, tzinfo=UTC)
STORE = EvalStore()


def enqueue(conn: psycopg.Connection[Any], **kwargs: Any) -> Any:
    values = {
        "run_id": uuid4(),
        "suite": "payment_intake",
        "run_date": date(2026, 11, 14),
        "trace_id": uuid4(),
        "idempotency_key": str(uuid4()),
        "configuration_fingerprint": "a" * 64,
        "now": NOW,
    }
    values.update(kwargs)
    return STORE.enqueue(conn, **values)


def claim(conn: psycopg.Connection[Any], **kwargs: Any) -> EvalClaim:
    enqueue(conn, **kwargs)
    result = STORE.claim(conn, now=kwargs.get("now", NOW), lease_seconds=900)
    assert result is not None
    return result


def reserve(conn: psycopg.Connection[Any], job: EvalClaim, **kwargs: Any) -> bool:
    values = {
        "call_id": uuid4(),
        "model": "model-a",
        "amount": Decimal("1"),
        "day_limit": Decimal("30"),
        "month_limit": Decimal("900"),
        "max_calls": 164,
        "max_model_calls": 82,
        "now": NOW,
    }
    values.update(kwargs)
    return STORE.reserve(conn, job, **values)


def settle(conn: psycopg.Connection[Any], call_id: UUID, **kwargs: Any) -> bool:
    values = {"actual_cost": Decimal("0.25"), "input_tokens": 10, "output_tokens": 2, "now": NOW}
    values.update(kwargs)
    return STORE.settle(conn, call_id, **values)


def test_enqueue_same_key_same_day_and_conflicts(conn: psycopg.Connection[Any]) -> None:
    run_id, key = uuid4(), str(uuid4())
    first = enqueue(conn, run_id=run_id, idempotency_key=key)
    duplicate = enqueue(conn, idempotency_key=key)
    same_day = enqueue(conn)
    conflict = enqueue(conn, idempotency_key=key, configuration_fingerprint="b" * 64)
    id_conflict = enqueue(conn, run_id=run_id, run_date=date(2026, 11, 15))
    assert (first.run_id, first.status, first.duplicate, first.conflict) == (run_id, "queued", False, False)
    assert duplicate.run_id == same_day.run_id == run_id
    assert duplicate.duplicate
    assert same_day.conflict
    assert conflict.conflict
    assert id_conflict.conflict
    assert conn.execute("SELECT count(*) FROM ai_eval_run").fetchone() == (1,)
    assert STORE.latest(conn, "payment_intake") == STORE.status(conn, run_id)
    assert STORE.status(conn, uuid4()) is None
    assert conn.execute("SELECT count(*) FROM ai_task").fetchone() == (0,)


def test_claim_is_exclusive_on_two_connections(conn: psycopg.Connection[Any], dsn: str) -> None:
    run_id = enqueue(conn).run_id
    barrier = Barrier(2)

    def worker() -> EvalClaim | None:
        with psycopg.connect(dsn, autocommit=True) as other:
            barrier.wait(timeout=5)
            return STORE.claim(other, now=NOW, lease_seconds=900)

    with ThreadPoolExecutor(max_workers=2) as pool:
        claims = list(pool.map(lambda _: worker(), range(2)))
    assert [result.run_id for result in claims if result is not None] == [run_id]
    assert STORE.status(conn, run_id)["attempt"] == 1


def test_heartbeat_finish_fenced_and_immutable(conn: psycopg.Connection[Any]) -> None:
    job = claim(conn)
    stale = replace(job, attempt=2)
    assert not STORE.heartbeat(conn, stale, now=NOW, lease_seconds=900)
    assert not STORE.finish(conn, stale, status="passed", report={}, error_code=None, now=NOW)
    assert STORE.heartbeat(conn, job, now=NOW + timedelta(seconds=800), lease_seconds=900)
    assert STORE.finish(conn, job, status="failed", report={"tested": 1}, error_code="quality", now=NOW)
    assert not STORE.finish(conn, job, status="passed", report={}, error_code=None, now=NOW)
    assert not STORE.heartbeat(conn, job, now=NOW, lease_seconds=900)
    assert STORE.status(conn, job.run_id)["report"] == {"tested": 1}
    assert not reserve(conn, job)


def test_expired_running_keeps_unknown_reserve_and_cannot_restart(conn: psycopg.Connection[Any]) -> None:
    job = claim(conn)
    call_id = uuid4()
    assert reserve(conn, job, call_id=call_id, amount=Decimal("4"))
    expired = NOW + timedelta(seconds=901)
    assert not STORE.heartbeat(conn, job, now=expired, lease_seconds=900)
    assert not STORE.finish(conn, job, status="passed", report={}, error_code=None, now=expired)
    assert not reserve(conn, job, now=expired)
    assert STORE.claim(conn, now=expired, lease_seconds=900) is None
    assert STORE.status(conn, job.run_id)["status"] == "incomplete"
    assert Decimal(STORE.budget(conn, now=expired)["month_reserved"]) == Decimal("4")
    assert settle(conn, call_id, now=expired)
    assert Decimal(STORE.budget(conn, now=expired)["month_reserved"]) == 0


def test_stale_queued_run_expires_without_calls(conn: psycopg.Connection[Any]) -> None:
    old = enqueue(conn, run_date=date(2026, 11, 13)).run_id
    future = enqueue(conn, run_date=date(2026, 11, 15)).run_id
    assert STORE.claim(conn, now=NOW, lease_seconds=900) is None
    assert STORE.status(conn, old)["status"] == "incomplete"
    assert STORE.status(conn, future)["status"] == "queued"


def test_reserve_commit_duplicate_conflict_and_settle_idempotency(conn: psycopg.Connection[Any], dsn: str) -> None:
    job, call_id = claim(conn), uuid4()
    assert reserve(conn, job, call_id=call_id, amount=Decimal("3"))
    with psycopg.connect(dsn, autocommit=True) as reader:
        assert Decimal(STORE.budget(reader, now=NOW)["day_reserved"]) == Decimal("3")
    assert not reserve(conn, job, call_id=call_id, amount=Decimal("3"))
    with pytest.raises(ValueError, match="conflict"):
        reserve(conn, job, call_id=call_id, amount=Decimal("2"))
    assert settle(conn, call_id)
    assert settle(conn, call_id)
    with pytest.raises(ValueError, match="conflict"):
        settle(conn, call_id, actual_cost=Decimal("0.5"))
    assert not settle(conn, uuid4())
    budget = STORE.budget(conn, now=NOW)
    assert Decimal(budget["day_spent"]) == Decimal("0.25")
    assert Decimal(budget["day_reserved"]) == 0


def test_settlement_uses_original_moscow_month_and_reports_overestimate(conn: psycopg.Connection[Any]) -> None:
    before = datetime(2026, 11, 30, 20, 59, tzinfo=UTC)
    after = datetime(2026, 11, 30, 21, 1, tzinfo=UTC)
    job = claim(conn, run_date=date(2026, 11, 30), now=before)
    call_id = uuid4()
    assert reserve(conn, job, call_id=call_id, now=before)
    assert settle(conn, call_id, actual_cost=Decimal("2"), now=after)
    assert Decimal(STORE.budget(conn, now=before)["day_spent"]) == 2
    assert Decimal(STORE.budget(conn, now=before)["month_reserved"]) == 0
    assert Decimal(STORE.budget(conn, now=after)["day_spent"]) == 0
    assert Decimal(STORE.budget(conn, now=after)["month_spent"]) == 0


def test_concurrent_reservations_cannot_exceed_daily_limit(conn: psycopg.Connection[Any], dsn: str) -> None:
    job, barrier = claim(conn), Barrier(2)

    def worker() -> bool:
        with psycopg.connect(dsn, autocommit=True) as other:
            barrier.wait(timeout=5)
            return reserve(other, job, amount=Decimal("20"))

    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: worker(), range(2)))
    assert sorted(results) == [False, True]
    assert Decimal(STORE.budget(conn, now=NOW)["day_reserved"]) == 20


def test_month_quota_shared_across_days_and_suites(conn: psycopg.Connection[Any]) -> None:
    first = claim(conn)
    assert reserve(conn, first, amount=Decimal("30"))
    conn.execute("UPDATE ai_eval_budget SET spent = 870 WHERE period_kind = 'month'")
    tomorrow = NOW + timedelta(days=1)
    second = claim(conn, suite="other", run_date=date(2026, 11, 15), now=tomorrow)
    assert not reserve(conn, second, now=tomorrow)
    assert Decimal(STORE.budget(conn, now=tomorrow)["day_reserved"]) == 0
    assert Decimal(STORE.budget(conn, now=tomorrow)["month_reserved"]) == 30


@pytest.mark.parametrize(("cap", "model_cap", "models"), [(2, 82, ["a", "b", "c"]), (164, 2, ["a", "a", "a"])])
def test_durable_call_limits(conn: psycopg.Connection[Any], cap: int, model_cap: int, models: list[str]) -> None:
    job = claim(conn)
    results = [reserve(conn, job, model=model, max_calls=cap, max_model_calls=model_cap) for model in models]
    assert results == [True, True, False]


def test_small_reservations_round_up_and_invalid_values_rejected(conn: psycopg.Connection[Any]) -> None:
    job = claim(conn)
    assert reserve(conn, job, amount=Decimal("0.000000001"))
    assert Decimal(STORE.budget(conn, now=NOW)["day_reserved"]) == Decimal("0.00000001")
    for invalid in [Decimal("-1"), Decimal("NaN"), Decimal("Infinity"), Decimal("0")]:
        with pytest.raises(ValueError, match="nonnegative"):
            reserve(conn, job, amount=invalid)


def test_concurrent_enqueue_same_day_creates_one_job(conn: psycopg.Connection[Any], dsn: str) -> None:
    barrier, key = Barrier(2), str(uuid4())

    def worker() -> Any:
        with psycopg.connect(dsn, autocommit=True) as other:
            barrier.wait(timeout=5)
            return enqueue(other, idempotency_key=key)

    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: worker(), range(2)))
    assert len({result.run_id for result in results}) == 1
    assert sorted(result.duplicate for result in results) == [False, True]
    assert not any(result.conflict for result in results)


def test_concurrent_duplicate_reserve_and_settle_only_charge_once(conn: psycopg.Connection[Any], dsn: str) -> None:
    job, call_id, barrier = claim(conn), uuid4(), Barrier(2)

    def worker(operation: str) -> bool:
        with psycopg.connect(dsn, autocommit=True) as other:
            barrier.wait(timeout=5)
            return reserve(other, job, call_id=call_id) if operation == "reserve" else settle(other, call_id)

    with ThreadPoolExecutor(max_workers=2) as pool:
        reserved = list(pool.map(worker, ["reserve", "reserve"]))
        settled = list(pool.map(worker, ["settle", "settle"]))
    assert sorted(reserved) == [False, True]
    assert settled == [True, True]
    assert Decimal(STORE.budget(conn, now=NOW)["month_spent"]) == Decimal("0.25")
    assert Decimal(STORE.budget(conn, now=NOW)["month_reserved"]) == 0


def test_reserve_and_settle_same_run_do_not_deadlock(conn: psycopg.Connection[Any], dsn: str) -> None:
    job, call_id, barrier = claim(conn), uuid4(), Barrier(2)
    assert reserve(conn, job, call_id=call_id)

    def worker(operation: str) -> bool:
        with psycopg.connect(dsn, autocommit=True) as other:
            other.execute("SET statement_timeout = '5s'")
            barrier.wait(timeout=5)
            return reserve(other, job) if operation == "reserve" else settle(other, call_id)

    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(worker, ["reserve", "settle"]))
    assert results == [True, True]
    assert Decimal(STORE.budget(conn, now=NOW)["day_reserved"]) == 1
    assert Decimal(STORE.budget(conn, now=NOW)["day_spent"]) == Decimal("0.25")


def test_hard_call_caps_cannot_be_increased_by_caller(conn: psycopg.Connection[Any]) -> None:
    job = claim(conn)
    for model in ["a", "b"]:
        for _ in range(82):
            assert reserve(conn, job, model=model, amount=Decimal("0.001"), max_calls=1000, max_model_calls=1000)
        assert not reserve(conn, job, model=model, amount=Decimal("0.001"), max_calls=1000, max_model_calls=1000)
    assert not reserve(conn, job, model="c", amount=Decimal("0.001"), max_calls=1000, max_model_calls=1000)


def test_hard_daily_budget_cannot_be_increased_by_caller(conn: psycopg.Connection[Any]) -> None:
    job = claim(conn)
    assert not reserve(conn, job, amount=Decimal("31"), day_limit=Decimal("100"))


def test_moscow_day_boundary_keeps_unknown_month_reservation(conn: psycopg.Connection[Any]) -> None:
    before = datetime(2026, 11, 14, 20, 59, tzinfo=UTC)
    after = datetime(2026, 11, 14, 21, 0, tzinfo=UTC)
    first = claim(conn, now=before)
    assert reserve(conn, first, now=before, amount=Decimal("30"))
    second = claim(conn, now=after, run_date=date(2026, 11, 15))
    assert reserve(conn, second, now=after, amount=Decimal("30"))
    budget = STORE.budget(conn, now=after)
    assert Decimal(budget["day_reserved"]) == 30
    assert Decimal(budget["month_reserved"]) == 60


def test_reservation_identity_conflicts_on_model_or_run(conn: psycopg.Connection[Any]) -> None:
    first, call_id = claim(conn), uuid4()
    assert reserve(conn, first, call_id=call_id)
    with pytest.raises(ValueError, match="conflict"):
        reserve(conn, first, call_id=call_id, model="another")
    second = claim(conn, suite="another")
    with pytest.raises(ValueError, match="conflict"):
        reserve(conn, second, call_id=call_id)


def test_settlement_rejects_conflicting_tokens_and_invalid_cost(conn: psycopg.Connection[Any]) -> None:
    job, call_id = claim(conn), uuid4()
    assert reserve(conn, job, call_id=call_id)
    assert settle(conn, call_id)
    with pytest.raises(ValueError, match="conflict"):
        settle(conn, call_id, input_tokens=11)
    with pytest.raises(ValueError, match="nonnegative"):
        settle(conn, call_id, actual_cost=Decimal("-1"))
    with pytest.raises(ValueError, match="nonnegative"):
        settle(conn, call_id, output_tokens=-1)


def test_lease_exact_boundary_fails_closed(conn: psycopg.Connection[Any]) -> None:
    job = claim(conn)
    boundary = NOW + timedelta(seconds=900)
    assert not reserve(conn, job, now=boundary)
    assert not STORE.heartbeat(conn, job, now=boundary, lease_seconds=900)
    assert not STORE.finish(conn, job, status="passed", report={}, error_code=None, now=boundary)
    assert STORE.claim(conn, now=boundary, lease_seconds=900) is None
    assert STORE.status(conn, job.run_id)["status"] == "incomplete"


def test_subquantum_reservation_payload_conflict_detected(conn: psycopg.Connection[Any]) -> None:
    job, call_id = claim(conn), uuid4()
    assert reserve(conn, job, call_id=call_id, amount=Decimal("0.000000001"))
    with pytest.raises(ValueError, match="conflict"):
        reserve(conn, job, call_id=call_id, amount=Decimal("0.000000002"))


def test_known_usage_preserves_exact_decimal_above_estimate_and_budget(conn: psycopg.Connection[Any]) -> None:
    job, call_id = claim(conn), uuid4()
    assert reserve(conn, job, call_id=call_id)
    assert settle(conn, call_id, actual_cost=Decimal("30.000000001"))
    assert Decimal(STORE.budget(conn, now=NOW)["day_spent"]) == Decimal("30.000000001")
    assert not reserve(conn, job, amount=Decimal("0.000000001"))
    with pytest.raises(ValueError, match="conflict"):
        settle(conn, call_id, actual_cost=Decimal("30.000000002"))


def test_rejected_daily_duplicate_does_not_bind_different_key(conn: psycopg.Connection[Any]) -> None:
    first = enqueue(conn)
    alias = str(uuid4())
    duplicate = enqueue(conn, idempotency_key=alias)
    assert duplicate.duplicate
    assert duplicate.conflict
    assert duplicate.run_id == first.run_id
    tomorrow = enqueue(conn, idempotency_key=alias, run_date=date(2026, 11, 15))
    assert not tomorrow.conflict
    assert not tomorrow.duplicate
    assert conn.execute("SELECT count(*) FROM ai_eval_run").fetchone() == (2,)
