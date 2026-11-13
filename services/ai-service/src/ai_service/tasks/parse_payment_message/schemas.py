"""Strict models for immutable ai-parse-payment-message/v1."""

import re
from datetime import date
from typing import Annotated, Literal, Self
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, field_validator, model_validator

Id = Annotated[str, Field(min_length=1, max_length=64)]
Currency = Annotated[str, Field(pattern=r"^[A-Z]{3}$")]
Question = Annotated[str, Field(min_length=1, max_length=500)]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class Named(StrictModel):
    id: Id
    name: str = Field(min_length=1, max_length=500)


class Category(Named):
    direction: Literal["inflow", "outflow", "any"]


class AllowedPair(StrictModel):
    project_id: Id
    cfo_id: Id


class DraftItem(StrictModel):
    ref: Id
    description: str = Field(max_length=1024)


class PaymentInput(StrictModel):
    text: str = Field(min_length=1, max_length=16000)
    reference_at: AwareDatetime
    timezone: str = Field(min_length=1, max_length=100)
    currency: Currency | None
    categories: list[Category] = Field(max_length=1000)
    projects: list[Named] = Field(max_length=1000)
    cfos: list[Named] = Field(max_length=1000)
    allowed_pairs: list[AllowedPair] = Field(max_length=10000)
    draft_items: list[DraftItem] = Field(max_length=50)

    @field_validator("reference_at", mode="before")
    @classmethod
    def reference_is_datetime_string(cls, value: object) -> object:
        if not isinstance(value, str) or not re.match(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}T", value):
            raise ValueError("reference_at must be an ISO datetime string")
        return value

    @field_validator("timezone")
    @classmethod
    def valid_timezone(cls, value: str) -> str:
        try:
            ZoneInfo(value)
        except (ZoneInfoNotFoundError, ValueError) as exc:
            raise ValueError("unknown timezone") from exc
        return value

    @model_validator(mode="after")
    def unique_context(self) -> Self:
        for values in (self.categories, self.projects, self.cfos):
            if len({v.id for v in values}) != len(values):
                raise ValueError("directory ids must be unique")
        if len({d.ref for d in self.draft_items}) != len(self.draft_items):
            raise ValueError("draft refs must be unique")
        projects, cfos = {p.id for p in self.projects}, {c.id for c in self.cfos}
        if any(p.project_id not in projects or p.cfo_id not in cfos for p in self.allowed_pairs):
            raise ValueError("allowed pair outside directories")
        return self


class PaymentItem(StrictModel):
    ref: Id
    text_evidence: str = Field(min_length=1, max_length=16000)
    amount_evidence: str | None = Field(min_length=1, max_length=16000)
    amount: str | None = Field(pattern=r"^\d{1,16}\.\d{2}$")
    currency: Currency | None
    date: date | None
    direction: Literal["inflow", "outflow"] | None
    execution_status: Literal["paid", "planned", "unknown"]
    description: str = Field(max_length=1024)
    category_id: Id | None
    project_id: Id | None
    cfo_id: Id | None
    questions: list[Question] = Field(max_length=50)

    @field_validator("date", mode="before")
    @classmethod
    def date_is_calendar_string(cls, value: object) -> object:
        if value is not None and (not isinstance(value, str) or not re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", value)):
            raise ValueError("date must be an ISO calendar date string")
        return value


class PaymentOutput(StrictModel):
    intent: Literal["payments", "correction", "no_payment"]
    items: list[PaymentItem] = Field(max_length=50)
    target_ref: Id | None
    questions: list[Question] = Field(max_length=50)
