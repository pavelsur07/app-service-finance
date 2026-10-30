"""HTTP API сервиса: стандартные endpoint'ы шлюза и API задач (service-gateway.md §2.3, ТЗ §4)."""

import hmac
from collections.abc import Iterator
from contextlib import asynccontextmanager
from typing import Annotated, Any
from uuid import UUID

import structlog
from fastapi import Depends, FastAPI, Header, HTTPException, Query, Request
from fastapi.responses import JSONResponse
from psycopg import Connection
from psycopg_pool import ConnectionPool

from ai_service import SERVICE_NAME, __version__
from ai_service.api.models import (
    AckRequest,
    AckResponse,
    ResultsPage,
    TaskAccepted,
    TaskRequest,
    TaskResult,
    TaskStatus,
    Usage,
)
from ai_service.store.tasks import NewTask, TaskStore

log = structlog.get_logger(__name__)

MAX_PAGE = 200


class ApiError(Exception):
    def __init__(self, status: int, code: str, message: str) -> None:
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message


def create_app(pool: ConnectionPool[Any], api_token: str, task_types: list[str]) -> FastAPI:
    store = TaskStore()

    @asynccontextmanager
    async def lifespan(_app: FastAPI) -> Any:
        pool.open()
        try:
            yield
        finally:
            pool.close()

    app = FastAPI(title=SERVICE_NAME, version=__version__, lifespan=lifespan, docs_url=None, redoc_url=None)

    @app.exception_handler(ApiError)
    async def _api_error(_request: Request, exc: ApiError) -> JSONResponse:
        return JSONResponse({"error": {"code": exc.code, "message": exc.message}}, status_code=exc.status)

    @app.middleware("http")
    async def _trace(request: Request, call_next: Any) -> Any:
        structlog.contextvars.bind_contextvars(trace_id=request.headers.get("x-trace-id"))
        try:
            return await call_next(request)
        finally:
            structlog.contextvars.unbind_contextvars("trace_id")

    def connection() -> Iterator[Connection[Any]]:
        with pool.connection() as conn:
            yield conn

    def authorized(authorization: Annotated[str | None, Header()] = None) -> None:
        expected = f"Bearer {api_token}"
        if authorization is None or not hmac.compare_digest(authorization.encode(), expected.encode()):
            raise ApiError(401, "unauthorized", "Неверный или отсутствующий токен")

    Conn = Annotated[Connection[Any], Depends(connection)]  # noqa: N806 — алиас типа зависимости
    internal = [Depends(authorized)]

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/ready")
    def ready(conn: Conn) -> dict[str, str]:
        conn.execute("SELECT 1")
        return {"status": "ready"}

    @app.get("/internal/status", dependencies=internal)
    def status(conn: Conn) -> dict[str, Any]:
        return {
            "service": SERVICE_NAME,
            "version": __version__,
            "task_types": task_types,
            "queue": store.queue_stats(conn),
        }

    @app.post("/internal/tasks", status_code=202, dependencies=internal)
    def enqueue(body: TaskRequest, conn: Conn, idempotency_key: Annotated[str | None, Header()] = None) -> TaskAccepted:
        if idempotency_key != body.idempotency_key:
            raise ApiError(422, "idempotency_key_mismatch", "Заголовок Idempotency-Key не равен idempotency_key")
        if body.task_type not in task_types:
            raise ApiError(422, "unknown_task_type", f"Неизвестный task_type {body.task_type}")
        result = store.enqueue(
            conn,
            NewTask(
                task_id=body.task_id,
                task_type=body.task_type,
                contract_version=body.contract_version,
                company_id=body.company_id,
                idempotency_key=body.idempotency_key,
                requested_by=body.requested_by,
                requested_at=body.created_at,
                trace_id=body.trace_id,
                payload=body.payload,
            ),
        )
        if result.conflict:
            raise ApiError(409, "idempotency_conflict", "Ключ повтора уже использован с другим содержимым")
        log.info("task_enqueued", task_id=str(result.task_id), task_type=body.task_type, duplicate=result.duplicate)
        return TaskAccepted(task_id=result.task_id, status=result.status, duplicate=result.duplicate)

    @app.get("/internal/tasks/{task_id}", dependencies=internal)
    def task_status(task_id: UUID, conn: Conn) -> TaskStatus:
        row = store.status(conn, task_id)
        if row is None:
            raise ApiError(404, "task_not_found", "Задача не найдена")
        return TaskStatus.model_validate(row)

    @app.get("/internal/tasks", dependencies=internal)
    def results(
        conn: Conn,
        status: Annotated[str, Query()],
        after: Annotated[int, Query(ge=0)] = 0,
        limit: Annotated[int, Query(ge=1)] = 50,
    ) -> ResultsPage:
        if status != "done":
            raise ApiError(422, "unsupported_status", "Поддерживается только status=done")
        if limit > MAX_PAGE:
            raise ApiError(422, "limit_too_large", f"limit не больше {MAX_PAGE}")
        rows = store.results_page(conn, after, limit)
        items = [_result(row) for row in rows]
        next_cursor = str(rows[-1]["seq"]) if len(rows) == limit else None
        return ResultsPage(items=items, next_cursor=next_cursor)

    @app.post("/internal/tasks/ack", dependencies=internal)
    def ack(body: AckRequest, conn: Conn) -> AckResponse:
        acked = store.ack(conn, body.task_ids)
        log.info("tasks_acked", requested=len(body.task_ids), acked=acked)
        return AckResponse(acked=acked)

    @app.exception_handler(HTTPException)
    async def _http_error(_request: Request, exc: HTTPException) -> JSONResponse:
        return JSONResponse({"error": {"code": "http_error", "message": str(exc.detail)}}, status_code=exc.status_code)

    return app


def _result(row: dict[str, Any]) -> TaskResult:
    confidence = row["confidence"]
    return TaskResult(
        task_id=row["task_id"],
        task_type=row["task_type"],
        company_id=row["company_id"],
        status=row["status"],
        output=row["output"],
        checks=row["checks"],
        confidence=None if confidence is None else float(confidence),
        trace_id=row["trace_id"],
        models=row["models"],
        prompt_version=row["prompt_version"],
        usage=Usage(input_tokens=row["input_tokens"], output_tokens=row["output_tokens"]),
        error_code=row["error_code"],
        completed_at=row["completed_at"],
    )
