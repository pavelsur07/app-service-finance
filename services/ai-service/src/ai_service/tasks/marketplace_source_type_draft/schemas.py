"""Вход и выход marketplace_source_type_draft (контракт ai-task/v1, $defs source_type_draft_*)."""

from typing import Literal, Self

from pydantic import BaseModel, ConfigDict, Field, model_validator

Level = Literal["revenue", "md1", "md2", "overhead", "info"]
KIND_PATTERN = r"^[a-z][a-z0-9_]{1,63}$"


class Kind(BaseModel):
    model_config = ConfigDict(extra="forbid")

    code: str = Field(pattern=KIND_PATTERN)
    level: Level
    title: str = Field(min_length=1, max_length=255)


class Example(BaseModel):
    """Уже решённый код того же маркетплейса: как платформа его разнесла."""

    model_config = ConfigDict(extra="forbid")

    source_type: str = Field(min_length=1, max_length=128)
    name: str = Field(min_length=1, max_length=255)
    kind: str = Field(pattern=KIND_PATTERN)


class DraftInput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    channel: Literal["ozon", "wb"]
    source_type: str = Field(min_length=1, max_length=128)
    # Название как в кабинете маркетплейса (справочник маркетплейса), если известно.
    name: str = Field(min_length=1, max_length=255)
    # Знак суммы в данных: debit — удержание с продавца, credit — начисление продавцу.
    sign: Literal["debit", "credit"]
    kinds: list[Kind] = Field(min_length=1, max_length=200)
    examples: list[Example] = Field(default_factory=list, max_length=200)

    @model_validator(mode="after")
    def _examples_use_known_kinds(self) -> Self:
        known = {k.code for k in self.kinds}
        if any(e.kind not in known for e in self.examples):
            raise ValueError("example kinds must be in kinds")
        return self


class NewKind(BaseModel):
    model_config = ConfigDict(extra="forbid")

    code: str = Field(pattern=KIND_PATTERN)
    title: str = Field(min_length=1, max_length=255)
    level: Level


class DraftOutput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    # Вид из списка kinds; null — ни один не подходит (тогда new_kind) или данных мало.
    kind: str | None
    new_kind: NewKind | None
    title: str = Field(min_length=1, max_length=255)
    reason: str = Field(min_length=1, max_length=500)
    confidence: float = Field(ge=0.0, le=1.0)
