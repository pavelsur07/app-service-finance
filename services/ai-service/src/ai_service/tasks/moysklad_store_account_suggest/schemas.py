"""Вход и выход moysklad_store_account_suggest (контракт ai-task/v1, $defs store_account_suggest_*)."""

from typing import Self

from pydantic import BaseModel, ConfigDict, Field, model_validator


class Store(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str = Field(min_length=1, max_length=255)
    path_name: str = Field(default="", max_length=4096)


class Account(BaseModel):
    """Действующий счёт актива Баланса компании."""

    model_config = ConfigDict(extra="forbid")

    code: str = Field(min_length=1, max_length=64)
    name: str = Field(min_length=1, max_length=255)
    article_path: str = Field(default="", max_length=1024)


class Example(BaseModel):
    """Уже назначенный склад той же компании: как его разнёс пользователь."""

    model_config = ConfigDict(extra="forbid")

    name: str = Field(min_length=1, max_length=255)
    account_code: str | None = Field(min_length=1, max_length=64)
    excluded: bool


class SuggestInput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    store: Store
    accounts: list[Account] = Field(min_length=1, max_length=200)
    examples: list[Example] = Field(default_factory=list, max_length=100)

    @model_validator(mode="after")
    def _examples_use_known_accounts(self) -> Self:
        known = {a.code for a in self.accounts}
        for e in self.examples:
            if (e.account_code is None) == (not e.excluded) or (
                e.account_code is not None and e.account_code not in known
            ):
                raise ValueError("example must have a known account_code or excluded")
        return self


class SuggestOutput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    # Код из accounts; null вместе с excluded=false — данных мало, решает человек.
    account_code: str | None = Field(min_length=1, max_length=64)
    excluded: bool
    reason: str = Field(min_length=1, max_length=500)
    confidence: float = Field(ge=0.0, le=1.0)
