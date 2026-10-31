"""Точка входа: `python -m ai_service <команда>` (только wiring).

Команды: api, worker, migrate, cleanup-traces, evals-import, evals-compare, gateway-check, llm-ping.
"""

import argparse
import json
import os
import sys
from pathlib import Path
from typing import Any

import psycopg
import uvicorn
import yaml
from psycopg_pool import ConnectionPool

from ai_service.api.app import create_app
from ai_service.config import Settings
from ai_service.core.pipeline import Pipeline
from ai_service.evals import compare
from ai_service.evals.anonymize import anonymize_history
from ai_service.evals.sets import HISTORY_DIR, load_sets, write_set
from ai_service.llm.adapters import build_adapters
from ai_service.llm.connectivity import CheckLine, gateway_check, llm_ping
from ai_service.llm.gateway import LlmGateway
from ai_service.llm.routes import (
    RoutingConfig,
    RoutingConfigError,
    assert_via_gateway,
    load_routing,
    parse_routing,
    worst_case_seconds,
)
from ai_service.store.migrate import migrate
from ai_service.store.tasks import TaskStore
from ai_service.tasks.registry import build_registry
from ai_service.tracing.errors import configure_error_reporting
from ai_service.tracing.logging import configure_logging
from ai_service.worker.worker import Worker

_ROOT = Path(__file__).resolve().parents[2]
_ROUTES = _ROOT / "config" / "routes.yaml"  # исходники; в образе пакет в site-packages, маршруты — по env
_GATEWAY_ENV = "AI_SERVICE_LLM_GATEWAY_URL"


def main(argv: list[str] | None = None) -> int:
    routes = Path(os.environ.get("AI_SERVICE_ROUTES_FILE") or _ROUTES)
    evals = Path(os.environ.get("AI_SERVICE_EVALS_DIR") or _ROOT / "evals") / compare.TASK
    parser = argparse.ArgumentParser(prog="ai_service")
    commands = parser.add_subparsers(dest="command", required=True)
    for name in ("api", "worker", "migrate", "cleanup-traces"):
        commands.add_parser(name)
    importer = commands.add_parser("evals-import", help="обезличить выгрузку app:cash:ai-eval-export в набор")
    importer.add_argument("export", type=Path)
    importer.add_argument("--name", required=True, help="имя набора: evals/categorize_transactions/history/<имя>")
    comparer = commands.add_parser("evals-compare", help="таблица «модель × точность × стоимость × задержка»")
    comparer.add_argument("--models", help="через запятую; по умолчанию — модели маршрута с ключами")
    comparer.add_argument("--sets", help="через запятую: synthetic, history/<имя>; по умолчанию — все")
    comparer.add_argument("--routes", type=Path, default=routes)
    comparer.add_argument("--prices", type=Path, default=routes.with_name("prices.yaml"))
    comparer.add_argument("--timeout", type=float, default=120.0)
    checker = commands.add_parser("gateway-check", help="проверки 2 и 4: /models через шлюз с токеном и без")
    checker.add_argument("--routes", type=Path, default=routes)
    pinger = commands.add_parser("llm-ping", help="проверка 3: короткий чат-запрос к каждому провайдеру маршрутов")
    pinger.add_argument("--routes", type=Path, default=routes)
    pinger.add_argument("--timeout", type=float, default=30.0)
    args = parser.parse_args(argv)

    # Evals — без БД и токена API: только файлы наборов и вызовы моделей.
    if args.command == "evals-import":
        return _evals_import(evals, args.export, args.name)
    if args.command == "evals-compare":
        return _evals_compare(evals, args)
    if args.command in ("gateway-check", "llm-ping"):
        return _connectivity(args)

    settings = Settings()  # значения — из env
    configure_logging(settings.log_level)
    configure_error_reporting()
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
    if not _via_gateway(routing, settings.llm_gateway_url):
        return 2
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


def _via_gateway(routing: RoutingConfig, gateway_url: str | None) -> bool:
    try:
        assert_via_gateway(routing, gateway_url)
    except RoutingConfigError as e:
        print(str(e), file=sys.stderr)  # noqa: T201
        return False
    return True


def _connectivity(args: argparse.Namespace) -> int:
    # Без БД и токена API: маршруты, токен шлюза и сеть.
    routing = load_routing(args.routes)
    if not _via_gateway(routing, os.environ.get(_GATEWAY_ENV)):
        return 2
    lines: list[CheckLine] = (
        gateway_check(routing)
        if args.command == "gateway-check"
        else llm_ping(routing, build_adapters(routing, args.timeout))
    )
    if not lines:
        print("no providers in routes", file=sys.stderr)  # noqa: T201
        return 2
    for line in lines:
        print(line.render())  # noqa: T201
    return 0 if all(line.ok for line in lines) else 1


def _evals_import(evals: Path, export: Path, name: str) -> int:
    if not name.replace("-", "").replace("_", "").isalnum():
        print("--name: only letters, digits, - and _", file=sys.stderr)  # noqa: T201
        return 2
    categories, cases = anonymize_history(json.loads(export.read_text(encoding="utf-8")))
    target = evals / HISTORY_DIR / name
    write_set(target, categories, cases)
    print(f"{len(cases)} cases, {len(categories)} categories -> {target}; review masking before commit")  # noqa: T201
    return 0


def _evals_compare(evals: Path, args: argparse.Namespace) -> int:
    raw_routes = yaml.safe_load(args.routes.read_text(encoding="utf-8"))
    env = dict(os.environ)
    if not _via_gateway(parse_routing(raw_routes, env), env.get(_GATEWAY_ENV)):
        return 2
    models = args.models.split(",") if args.models else compare.available_models(raw_routes, env)
    if not models:
        print("no models: set provider keys or pass --models", file=sys.stderr)  # noqa: T201
        return 2
    sets = load_sets(evals)
    if args.sets:
        wanted = set(args.sets.split(","))
        sets = [s for s in sets if s.name in wanted]
    if not sets:
        print("no eval sets selected", file=sys.stderr)  # noqa: T201
        return 2
    prices = (
        compare.parse_prices(yaml.safe_load(args.prices.read_text(encoding="utf-8"))) if args.prices.is_file() else {}
    )

    rows: list[tuple[str, compare.Score]] = []
    for model in models:
        pipeline = compare.single_model_pipeline(raw_routes, model, env, args.timeout)
        total = compare.Score()
        for eval_set in sets:
            total.add(compare.evaluate(pipeline, eval_set))
        rows.append((model, total))
    print(f"Наборы: {', '.join(s.name for s in sets)}")  # noqa: T201
    print(compare.render(rows, prices))  # noqa: T201
    return 0


if __name__ == "__main__":
    sys.exit(main())
