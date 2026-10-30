from pydantic import BaseModel, ConfigDict, Field


class EchoInput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    text: str = Field(min_length=1, max_length=2000)


class EchoOutput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    text: str
