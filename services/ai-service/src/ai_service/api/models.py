"""HTTP-модели API задач по контракту services/_contracts/ai-task/v1."""

from datetime import datetime
from typing import Any, Literal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field

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
