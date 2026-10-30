"""Точка входа: `python -m ai_service {api|worker|migrate|cleanup-traces}` (только wiring)."""

import argparse
import sys
from typing import Any

import psycopg
import uvicorn
from psycopg_pool import ConnectionPool

from ai_service.api.app import create_app
from ai_service.config import Settings
from ai_service.core.pipeline import Pipeline
from ai_service.llm.adapters import build_adapters
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import load_routing, worst_case_seconds
from ai_service.store.migrate import migrate
from ai_service.store.tasks import TaskStore
from ai_service.tasks.registry import build_registry
from ai_service.tracing.logging import configure_logging
from ai_service.worker.worker import Worker


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="ai_service")
    parser.add_argument("command", choices=["api", "worker", "migrate", "cleanup-traces"])
    args = parser.parse_args(argv)

    settings = Settings()  # значения — из env
    configure_logging(settings.log_level)
    dsn = settings.db_dsn.get_secret_value()

    if args.command == "migrate":
        migrate(dsn, settings.migrations_dir)
        return 0
    if args.command == "cleanup-traces":
        with psycopg.connect(dsn, autocommit=True) as conn:
            TaskStore().delete_expired_traces(conn, settings.trace_ttl_days)
        return 0

    registry = build_registry()
    routing = load_routing(settings.routes_file)
    missing = [t for t in registry.task_types() if t not in routing.routes]
    if missing:
        print(f"no route for task types: {', '.join(missing)}", file=sys.stderr)  # noqa: T201
        return 2

    if args.command == "api":
        pool: ConnectionPool[Any] = ConnectionPool(dsn, min_size=1, max_size=5, open=False, kwargs={"autocommit": True})
        app = create_app(pool, settings.api_token.get_secret_value(), registry.task_types())
        uvicorn.run(app, host=settings.http_host, port=settings.http_port, log_config=None, access_log=False)
        return 0

    # Аренда короче худшего прогона — вторая копия worker'а перехватит задачу и заплатит за вызовы ещё раз.
    too_long = [
        t
        for t in registry.task_types()
        if worst_case_seconds(routing.routes[t], settings.llm_timeout_seconds, settings.llm_retries_per_model)
        >= settings.worker_lease_seconds
    ]
    if too_long:
        print(f"worker lease is shorter than worst-case run for: {', '.join(too_long)}", file=sys.stderr)  # noqa: T201
        return 2

    gateway = LlmGateway(routing, build_adapters(routing, settings.llm_timeout_seconds), settings.llm_retries_per_model)
    with psycopg.connect(dsn, autocommit=True) as conn:
        Worker(
            conn,
            TaskStore(),
            Pipeline(registry, gateway),
            lease_seconds=settings.worker_lease_seconds,
            max_attempts=settings.worker_max_attempts,
            retry_backoff_seconds=settings.worker_retry_backoff_seconds,
            poll_seconds=settings.worker_poll_seconds,
            trace_ttl_days=settings.trace_ttl_days,
            trace_cleanup_interval_seconds=settings.trace_cleanup_interval_seconds,
        ).run_forever()
    return 0


if __name__ == "__main__":
    sys.exit(main())
