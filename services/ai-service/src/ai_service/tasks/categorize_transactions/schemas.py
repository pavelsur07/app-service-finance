"""Вход и выход categorize_transactions (контракт ai-task/v1, $defs categorize_*)."""

from datetime import date
from typing import Literal, Self

from pydantic import BaseModel, ConfigDict, Field, model_validator

MAX_OPERATIONS = 50
MAX_CATEGORIES = 1000

Direction = Literal["inflow", "outflow"]


class Category(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str = Field(min_length=1, max_length=64)
    # Путь от корня: «Операционная деятельность / Расходы / Аренда».
    path: str = Field(min_length=1, max_length=500)
    direction: Literal["inflow", "outflow", "any"]


class Operation(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str = Field(min_length=1, max_length=64)
    direction: Direction
    # Деньги — десятичная строка (services/AGENTS.md §1.3); модель их не считает.
    amount: str = Field(pattern=r"^\d{1,16}\.\d{2}$")
    currency: str = Field(pattern=r"^[A-Z]{3}$")
    date: date
    description: str | None = Field(default=None, max_length=1024)
    counterparty: str | None = Field(default=None, max_length=255)


class CategorizeInput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    categories: list[Category] = Field(min_length=1, max_length=MAX_CATEGORIES)
    operations: list[Operation] = Field(min_length=1, max_length=MAX_OPERATIONS)

    @model_validator(mode="after")
    def _unique_ids(self) -> Self:
        if len({c.id for c in self.categories}) != len(self.categories):
            raise ValueError("category ids must be unique")
        if len({o.id for o in self.operations}) != len(self.operations):
            raise ValueError("operation ids must be unique")
        return self


class CategorizedItem(BaseModel):
    model_config = ConfigDict(extra="forbid")

    operation_id: str
    # null — «недостаточно данных»: модель не угадывает.
    category_id: str | None
    confidence: float = Field(ge=0.0, le=1.0)


class CategorizeOutput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    items: list[CategorizedItem]
