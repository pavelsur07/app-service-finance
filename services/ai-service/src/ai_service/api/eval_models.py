"""Technical quality-run contract, deliberately separate from company task payloads."""

import re
from datetime import date
from typing import Any, Literal
from uuid import UUID

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, field_validator


class EvalRunRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    contract_version: Literal["v1"]
    run_id: UUID
    suite: Literal["payment_intake"]
    run_date: date
    trace_id: UUID
    idempotency_key: str = Field(min_length=1, max_length=200, strict=True)

    @field_validator("run_date", mode="before")
    @classmethod
    def iso_run_date(cls, value: object) -> object:
        if not isinstance(value, str) or not re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", value):
            raise ValueError("run_date must be an ISO calendar date")
        return value

    @field_validator("idempotency_key")
    @classmethod
    def safe_key(cls, value: str) -> str:
        if any(ord(character) < 32 or ord(character) == 127 for character in value):
            raise ValueError("idempotency key contains control characters")
        return value


EvalState = Literal["queued", "running", "passed", "failed", "unavailable", "incomplete", "skipped_budget"]


class EvalAccepted(BaseModel):
    contract_version: Literal["v1"]
    run_id: UUID
    status: EvalState
    duplicate: bool
    configuration_fingerprint: str = Field(pattern=r"^[0-9a-f]{64}$")


class EvalStatus(BaseModel):
    contract_version: Literal["v1"]
    run_id: UUID
    suite: Literal["payment_intake"]
    run_date: date
    trace_id: UUID
    status: EvalState
    attempt: int = Field(ge=0)
    configuration_fingerprint: str = Field(pattern=r"^[0-9a-f]{64}$")
    created_at: AwareDatetime
    updated_at: AwareDatetime
    completed_at: AwareDatetime | None
    error_code: str | None
    report: dict[str, Any] | None


class EvalLatest(BaseModel):
    enabled: bool
    configuration_fingerprint: str | None
    run: EvalStatus | None
