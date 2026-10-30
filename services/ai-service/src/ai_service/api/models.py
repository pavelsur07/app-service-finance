"""HTTP-модели API задач по контракту services/_contracts/ai-task/v1."""

from datetime import datetime
from typing import Any, Literal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, field_validator

TaskState = Literal["queued", "running", "done", "acked"]
ResultStatus = Literal["accepted", "needs_review", "rejected", "failed"]


class TaskRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    task_id: UUID
    task_type: str = Field(pattern=r"^[a-z][a-z0-9_]{1,63}$")
    contract_version: Literal["v1"]
    company_id: UUID
    idempotency_key: str = Field(min_length=1, max_length=200)
    requested_by: str = Field(min_length=1, max_length=64)
    created_at: datetime
    trace_id: UUID
    payload: dict[str, Any]

    @field_validator("payload")
    @classmethod
    def _no_nul(cls, value: dict[str, Any]) -> dict[str, Any]:
        # PostgreSQL не хранит NUL в jsonb; отказ здесь — 422, а не 500 с текстом payload в логе ошибки БД.
        if _has_nul(value):
            raise ValueError("payload must not contain NUL characters")
        return value


def _has_nul(value: Any) -> bool:
    if isinstance(value, str):
        return "\x00" in value
    if isinstance(value, list):
        return any(_has_nul(v) for v in value)
    if isinstance(value, dict):
        return any("\x00" in k or _has_nul(v) for k, v in value.items())
    return False


class TaskAccepted(BaseModel):
    task_id: UUID
    status: TaskState
    duplicate: bool


class TaskStatus(BaseModel):
    task_id: UUID
    task_type: str
    status: TaskState
    attempts: int
    result_status: ResultStatus | None
    created_at: datetime
    updated_at: datetime


class CheckOut(BaseModel):
    name: str
    passed: bool
    severity: Literal["block", "review", "info"]
    details: str


class Usage(BaseModel):
    input_tokens: int
    output_tokens: int


class TaskResult(BaseModel):
    task_id: UUID
    task_type: str
    company_id: UUID
    status: ResultStatus
    output: dict[str, Any] | None
    checks: list[CheckOut]
    confidence: float | None
    trace_id: UUID
    models: list[str]
    prompt_version: str | None
    usage: Usage
    error_code: str | None
    completed_at: datetime


class ResultsPage(BaseModel):
    items: list[TaskResult]
    next_cursor: str | None


class AckRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    task_ids: list[UUID] = Field(min_length=1, max_length=200)


class AckResponse(BaseModel):
    acked: int


class ErrorBody(BaseModel):
    code: str
    message: str


class ErrorResponse(BaseModel):
    error: ErrorBody
