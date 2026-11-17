"""Вход и ответ по ai-cash-rule-phrase/v1."""

from pydantic import BaseModel, ConfigDict, Field


class Example(BaseModel):
    """Обезличенное назначение и отметка, что человек выбрал целевую статью."""

    model_config = ConfigDict(extra="forbid")
    text: str = Field(min_length=1, max_length=1600)
    target: bool


class PhraseInput(BaseModel):
    model_config = ConfigDict(extra="forbid")
    examples: list[Example] = Field(min_length=1, max_length=60)


class PhraseOutput(BaseModel):
    model_config = ConfigDict(extra="forbid")
    phrase: str | None = Field(min_length=1, max_length=255)
