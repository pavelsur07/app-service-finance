"""Worker: берёт задачи из ai_task (FOR UPDATE SKIP LOCKED), гонит pipeline вне транзакции, пишет итог."""

import signal
import threading
import time
from typing import Any

import structlog
from psycopg import Connection

from ai_service.core.pipeline import Pipeline, PipelineOutcome
from ai_service.llm.types import LlmUnavailableError
from ai_service.store.tasks import ClaimedTask, TaskStore
from ai_service.verification.checks import Verdict

log = structlog.get_logger(__name__)


class Worker:
    def __init__(
        self,
        conn: Connection[Any],
        store: TaskStore,
        pipeline: Pipeline,
        *,
        lease_seconds: int,
        max_attempts: int,
        retry_backoff_seconds: int,
        poll_seconds: float,
        trace_ttl_days: int,
        trace_cleanup_interval_seconds: int,
    ) -> None:
        self._conn = conn
        self._store = store
        self._pipeline = pipeline
        self._lease = lease_seconds
        self._max_attempts = max_attempts
        self._backoff = retry_backoff_seconds
        self._poll = poll_seconds
        self._ttl_days = trace_ttl_days
        self._cleanup_interval = trace_cleanup_interval_seconds
        self._stop = threading.Event()
        self._next_cleanup = 0.0

    def stop(self, *_args: object) -> None:
        self._stop.set()

    def run_forever(self) -> None:
        signal.signal(signal.SIGTERM, self.stop)
        signal.signal(signal.SIGINT, self.stop)
        log.info("worker_started")
        while not self._stop.is_set():
            self._cleanup_traces_if_due()
            if not self.run_once():
                self._stop.wait(self._poll)
        log.info("worker_stopped")

    def run_once(self) -> bool:
        """Обработать одну задачу. False — очередь пуста."""
        task = self._store.claim(self._conn, self._lease)
        if task is None:
            return False
        structlog.contextvars.bind_contextvars(task_id=str(task.task_id), trace_id=str(task.trace_id))
        started = time.monotonic()
        try:
            outcome = self._process(task)
            if outcome is None:
                return True
            if not self._store.finalize(self._conn, task, outcome):
                log.warning("task_finalize_skipped", stage="finalize", outcome="lost_lease")
                return True
            log.info(
                "task_done",
                stage="finalize",
                task_type=task.task_type,
                outcome=outcome.verdict.value,
                error_code=outcome.error_code,
                retry_count=task.attempts - 1,
                duration_ms=int((time.monotonic() - started) * 1000),
            )
            return True
        finally:
            structlog.contextvars.unbind_contextvars("task_id", "trace_id")

    def _process(self, task: ClaimedTask) -> PipelineOutcome | None:
        if task.payload is None:
            return PipelineOutcome(verdict=Verdict.FAILED, error_code="internal_error")
        if task.attempts > self._max_attempts:
            return PipelineOutcome(verdict=Verdict.FAILED, error_code="llm_unavailable")
        try:
            return self._pipeline.run(task.task_type, str(task.company_id), task.payload)
        except LlmUnavailableError as e:
            errors = ",".join(sorted({c.error or "" for c in e.calls})) or "no_models"
            if task.attempts >= self._max_attempts:
                log.warning("task_llm_unavailable", stage="generate", outcome="failed", errors=errors)
                outcome = PipelineOutcome(verdict=Verdict.FAILED, error_code="llm_unavailable")
                outcome.trace = {"task_type": task.task_type, "error_code": "llm_unavailable", "errors": errors}
                return outcome
            log.warning("task_llm_unavailable", stage="generate", outcome="retry", errors=errors)
            self._store.release_for_retry(self._conn, task, errors, self._backoff * task.attempts)
            return None
        except Exception:
            # Баг ядра или задачи: задача уходит человеку как failed, а не крутится в очереди.
            log.exception("task_internal_error", stage="pipeline", outcome="failed")
            return PipelineOutcome(verdict=Verdict.FAILED, error_code="internal_error")

    def _cleanup_traces_if_due(self) -> None:
        now = time.monotonic()
        if now < self._next_cleanup:
            return
        self._next_cleanup = now + self._cleanup_interval
        deleted = self._store.delete_expired_traces(self._conn, self._ttl_days)
        if deleted:
            log.info("traces_expired", deleted=deleted, ttl_days=self._ttl_days)
