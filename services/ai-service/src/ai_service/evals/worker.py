"""Dedicated eval queue executor; never consumes or blocks business ai_task work."""

import signal
import threading
import time
from collections.abc import Callable
from datetime import datetime
from typing import Any

import structlog
from psycopg import Connection

from ai_service.evals.payment_intake_nightly import EvalOutcome, NightlyEvaluator, utcnow
from ai_service.store.evals import EvalStore

log = structlog.get_logger(__name__)


class EvalWorker:
    def __init__(
        self,
        conn: Connection[Any],
        store: EvalStore,
        evaluator: NightlyEvaluator,
        *,
        lease_seconds: int,
        poll_seconds: float,
        clock: Callable[[], datetime] = utcnow,
    ) -> None:
        self.conn, self.store, self.evaluator = conn, store, evaluator
        self.lease_seconds, self.poll_seconds, self.clock = lease_seconds, poll_seconds, clock
        self._stop = threading.Event()

    def stop(self, *_args: object) -> None:
        self._stop.set()

    def run_once(self) -> bool:
        claim = self.store.claim(self.conn, now=self.clock(), lease_seconds=self.lease_seconds)
        if claim is None:
            return False
        started = time.monotonic()
        try:
            outcome = self.evaluator.run(claim)
        except Exception as error:  # noqa: BLE001 — no payload or exception text enters logs/report.
            log.error(
                "eval_execution_error",
                service="ai-service",
                job_id=str(claim.run_id),
                trace_id=str(claim.trace_id),
                stage="evaluate",
                error_type=type(error).__name__,
            )
            outcome = EvalOutcome(
                "incomplete",
                {
                    "complete": False,
                    "configuration_fingerprint": claim.configuration_fingerprint,
                    "coverage_unknown": True,
                    "network_calls": None,
                },
                "internal_error",
            )
        finished = self.store.finish(
            self.conn,
            claim,
            status=outcome.status,
            report=outcome.report,
            error_code=outcome.error_code,
            now=self.clock(),
        )
        log.info(
            "eval_done",
            service="ai-service",
            job_id=str(claim.run_id),
            trace_id=str(claim.trace_id),
            stage="finalize",
            outcome=outcome.status if finished else "lost_lease",
            duration_ms=int((time.monotonic() - started) * 1000),
            retry_count=claim.attempt - 1,
        )
        return True

    def run_forever(self) -> None:
        signal.signal(signal.SIGTERM, self.stop)
        signal.signal(signal.SIGINT, self.stop)
        while not self._stop.is_set():
            try:
                worked = self.run_once()
            except Exception as error:  # noqa: BLE001 — keep daemon alive; expired runs are not replayed.
                log.error("eval_worker_error", service="ai-service", stage="poll", error_type=type(error).__name__)
                worked = False
            if not worked:
                self._stop.wait(self.poll_seconds)
